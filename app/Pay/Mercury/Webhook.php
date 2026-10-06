<?php
declare(strict_types=1);

namespace App\Pay\Mercury;

use App\Model\MercuryOrder;
use App\Model\MercuryWebhookEvent;
use App\Model\Order as LocalOrder;
use App\Model\PayConfig;
use App\Model\UserRecharge;
use App\Service\Order;
use App\Service\Recharge;
use App\Util\Date;
use App\Util\PayProfile;
use App\Util\PayTest;
use App\Util\Schema;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;

final class Webhook
{
    private const MAX_BODY_BYTES = 1048576;

    public function handle(Request $request, Order $orderService, Recharge $rechargeService): string
    {
        Schema::ensureMercuryPayment();
        if ($request->method() !== 'POST') {
            $this->reject(405, 'method_not_allowed');
        }

        $raw = $request->raw();
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            $this->reject(413, 'invalid_body_size');
        }

        $eventId = trim((string)$request->header('XMercuryEventId'));
        $timestamp = trim((string)$request->header('XMercuryTimestamp'));
        $version = trim((string)$request->header('XMercuryEventVersion'));
        $signature = strtolower(trim((string)$request->header('XMercurySignature')));
        if ($eventId === '' || strlen($eventId) > 128 || $timestamp === '' || strlen($timestamp) > 128) {
            $this->reject(400, 'missing_webhook_headers');
        }
        if ($version !== '1') {
            $this->reject(400, 'unsupported_event_version');
        }
        if (!Protocol::validHexSignature($signature)) {
            $this->reject(401, 'invalid_signature');
        }

        $matchedConfigs = $this->matchingConfigs($eventId, $timestamp, $raw, $signature);
        if ($matchedConfigs === []) {
            $this->reject(401, 'invalid_signature');
        }

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->reject(400, 'invalid_json');
        }
        if (!is_array($payload)) {
            $this->reject(400, 'invalid_event');
        }
        if (!is_string($payload['eventId'] ?? null) || !hash_equals($eventId, $payload['eventId'])) {
            $this->reject(400, 'event_id_mismatch');
        }

        $tenantId = $this->requiredString($payload, 'tenantId', 64);
        $appId = $this->requiredString($payload, 'appId', 64);
        $eventType = $this->requiredString($payload, 'eventType', 64);
        $scopeConfigs = array_values(array_filter($matchedConfigs, static function (array $candidate) use ($tenantId, $appId): bool {
            return hash_equals($candidate['tenant_id'], $tenantId) && hash_equals($candidate['app_id'], $appId);
        }));
        if ($scopeConfigs === []) {
            $this->reject(403, 'event_scope_mismatch');
        }

        $bodyHash = hash('sha256', $raw);
        if ($eventType === 'WebhookTest') {
            if ((string)($payload['eventVersion'] ?? '') !== '1') {
                $this->reject(400, 'event_version_mismatch');
            }
            $this->requiredString($payload, 'testedAt', 128);
            return $this->recordWebhookTest($eventId, $tenantId, $appId, $bodyHash);
        }
        if ($eventType !== 'PaymentSucceeded') {
            $this->reject(400, 'unsupported_event_type');
        }

        $allowedConfigIds = array_map(static fn(array $candidate): int => $candidate['id'], $scopeConfigs);
        return DB::transaction(function () use (
            $payload,
            $eventId,
            $tenantId,
            $appId,
            $eventType,
            $bodyHash,
            $allowedConfigIds,
            $orderService,
            $rechargeService
        ): string {
            $duplicate = MercuryWebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();
            if ($duplicate) {
                if (!hash_equals((string)$duplicate->body_hash, $bodyHash)) {
                    $this->reject(409, 'event_id_reused');
                }
                if ((string)$duplicate->status === 'PROCESSED') {
                    return 'success';
                }
                $this->reject(409, 'event_in_progress');
            }

            $clientOrderNo = $this->requiredString($payload, 'clientOrderNo', 64);
            $mapping = MercuryOrder::query()
                ->where('tenant_id', $tenantId)
                ->where('app_id', $appId)
                ->where('client_order_no', $clientOrderNo)
                ->lockForUpdate()
                ->first();
            if (!$mapping || !in_array((int)$mapping->pay_config_id, $allowedConfigIds, true)) {
                $this->reject(404, 'local_order_not_found');
            }

            $this->verifyPaymentSnapshot($payload, $mapping);

            $event = new MercuryWebhookEvent();
            $event->event_id = $eventId;
            $event->tenant_id = $tenantId;
            $event->app_id = $appId;
            $event->event_type = $eventType;
            $event->client_order_no = $clientOrderNo;
            $event->mercury_order_no = (string)$mapping->mercury_order_no;
            $event->transaction_no = (string)$mapping->transaction_no;
            $event->body_hash = $bodyHash;
            $event->status = 'PROCESSING';
            $event->create_time = Date::current();
            $event->save();

            // 第二层幂等：Mercury 可能用新 eventId 重投同一笔已付款订单。
            if ((string)$mapping->effect_status !== 'COMPLETED') {
                if ((string)$mapping->local_type === 'order') {
                    $local = LocalOrder::query()->where('trade_no', (string)$mapping->local_trade_no)->lockForUpdate()->first();
                    if (!$local || !$local->pay || (string)$local->pay->handle !== 'Mercury'
                        || (int)$local->pay->pay_config_id !== (int)$mapping->pay_config_id) {
                        $this->reject(409, 'local_order_mismatch');
                    }
                    $localUserId = (int)$local->owner > 0
                        ? 'account:' . (int)$local->owner
                        : 'guest:' . (string)$local->trade_no;
                    $localAmount = $local->gateway_amount !== null ? $local->gateway_amount : $local->amount;
                    if (!hash_equals((string)$mapping->user_id, $localUserId)
                        || !hash_equals((string)$mapping->sku_code, 'commodity:' . (int)$local->commodity_id)
                        || !$this->sameMoney((string)$mapping->total_amount, $localAmount)) {
                        $this->reject(409, 'local_order_snapshot_mismatch');
                    }
                    if ((int)$local->status === 0) {
                        $orderService->orderSuccess($local);
                    } elseif ((int)$local->status !== 1) {
                        $this->reject(409, 'local_order_state_mismatch');
                    }
                } elseif ((string)$mapping->local_type === 'recharge') {
                    $local = UserRecharge::query()->where('trade_no', (string)$mapping->local_trade_no)->lockForUpdate()->first();
                    if (!$local || !$local->pay || (string)$local->pay->handle !== 'Mercury'
                        || (int)$local->pay->pay_config_id !== (int)$mapping->pay_config_id) {
                        $this->reject(409, 'local_recharge_mismatch');
                    }
                    $localAmount = $local->gateway_amount !== null ? $local->gateway_amount : $local->amount;
                    if (!hash_equals((string)$mapping->user_id, 'account:' . (int)$local->user_id)
                        || !hash_equals((string)$mapping->sku_code, 'acg-faka-recharge')
                        || !$this->sameMoney((string)$mapping->total_amount, $localAmount)) {
                        $this->reject(409, 'local_recharge_snapshot_mismatch');
                    }
                    if ((int)$local->status === 0) {
                        $rechargeService->orderSuccess($local);
                    } elseif ((int)$local->status !== 1) {
                        $this->reject(409, 'local_recharge_state_mismatch');
                    }
                } elseif ((string)$mapping->local_type === 'test') {
                    // 后台拨测只有诊断状态，没有商品、余额或任何可发放权益。
                    PayTest::patch((string)$mapping->local_trade_no, [
                        'status' => 'paid',
                        'paid_amount' => (string)$mapping->total_amount,
                        'pay_time' => Date::current(),
                        'message' => ''
                    ]);
                } else {
                    $this->reject(409, 'unknown_local_order_type');
                }

                $mapping->effect_status = 'COMPLETED';
                $mapping->status = 'SUCCESS';
                $mapping->paid_time = Date::current();
                $mapping->update_time = Date::current();
                $mapping->save();
            }

            $event->status = 'PROCESSED';
            $event->processed_time = Date::current();
            $event->save();
            return 'success';
        }, 3);
    }

    private function matchingConfigs(string $eventId, string $timestamp, string $raw, string $signature): array
    {
        $matched = [];
        $seen = [];
        foreach (PayConfig::query()->where('handle', 'Mercury')->get(['id']) as $row) {
            $config = PayProfile::raw('Mercury', (int)$row->id);
            if (!is_array($config)) {
                continue;
            }
            $secret = (string)($config['app_secret'] ?? '');
            $tenantId = Protocol::trimJava((string)($config['tenant_id'] ?? ''));
            $appId = Protocol::trimJava((string)($config['app_id'] ?? ''));
            if ($secret === '' || $tenantId === '' || $appId === '') {
                continue;
            }
            if (!hash_equals(Protocol::webhookSignature($eventId, $timestamp, $raw, $secret), $signature)) {
                continue;
            }
            $dedupe = (int)$row->id . "\0" . $tenantId . "\0" . $appId;
            if (!isset($seen[$dedupe])) {
                $matched[] = ['id' => (int)$row->id, 'tenant_id' => $tenantId, 'app_id' => $appId];
                $seen[$dedupe] = true;
            }
        }
        return $matched;
    }

    private function recordWebhookTest(string $eventId, string $tenantId, string $appId, string $bodyHash): string
    {
        return DB::transaction(function () use ($eventId, $tenantId, $appId, $bodyHash): string {
            $event = MercuryWebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();
            if ($event) {
                if (!hash_equals((string)$event->body_hash, $bodyHash)) {
                    $this->reject(409, 'event_id_reused');
                }
                if ((string)$event->status === 'PROCESSED') {
                    return 'success';
                }
                $this->reject(409, 'event_in_progress');
            }
            $event = new MercuryWebhookEvent();
            $event->event_id = $eventId;
            $event->tenant_id = $tenantId;
            $event->app_id = $appId;
            $event->event_type = 'WebhookTest';
            $event->body_hash = $bodyHash;
            $event->status = 'PROCESSED';
            $event->create_time = Date::current();
            $event->processed_time = Date::current();
            $event->save();
            return 'success';
        }, 3);
    }

    private function verifyPaymentSnapshot(array $payload, MercuryOrder $mapping): void
    {
        $eventOrderNo = $this->requiredString($payload, 'orderNo', 128);
        $eventTransactionNo = $this->requiredString($payload, 'transactionNo', 128);
        $knownOrderNo = trim((string)$mapping->mercury_order_no);
        $knownTransactionNo = trim((string)$mapping->transaction_no);

        // HTTP 超时时 Mercury 可能已经创建订单，但响应中的两个平台编号没有落到本地。
        // 只有签名、应用范围、业务号和完整快照都将继续校验，且两个编号都尚未绑定时，
        // 才允许从成功事件补回映射；半绑定或已知编号不一致一律拒绝。
        if ($knownOrderNo === '' && $knownTransactionNo === ''
            && in_array((string)$mapping->status, ['CREATING', 'UNKNOWN'], true)) {
            $collision = MercuryOrder::query()
                ->where('id', '<>', (int)$mapping->id)
                ->where(function ($query) use ($eventOrderNo, $eventTransactionNo) {
                    $query->where('mercury_order_no', $eventOrderNo)->orWhere('transaction_no', $eventTransactionNo);
                })
                ->lockForUpdate()
                ->first();
            if ($collision) {
                $this->reject(409, 'payment_identifier_conflict');
            }
            $mapping->mercury_order_no = $eventOrderNo;
            $mapping->transaction_no = $eventTransactionNo;
            $knownOrderNo = $eventOrderNo;
            $knownTransactionNo = $eventTransactionNo;
        }

        $checks = [
            'orderNo' => $knownOrderNo,
            'transactionNo' => $knownTransactionNo,
            'clientOrderNo' => (string)$mapping->client_order_no,
            'userId' => (string)$mapping->user_id,
            'currency' => (string)$mapping->currency
        ];
        foreach ($checks as $field => $expected) {
            if ($expected === '' || !is_string($payload[$field] ?? null) || !hash_equals($expected, $payload[$field])) {
                $this->reject(409, 'payment_' . $field . '_mismatch');
            }
        }
        $total = $payload['totalAmount'] ?? null;
        if (!is_int($total) && !is_float($total) && !is_string($total)) {
            $this->reject(409, 'payment_amount_mismatch');
        }
        try {
            $actualTotal = Protocol::exactPositiveMoney($total);
        } catch (\InvalidArgumentException) {
            $this->reject(409, 'payment_amount_mismatch');
        }
        if (!hash_equals(Protocol::money((string)$mapping->total_amount), $actualTotal)) {
            $this->reject(409, 'payment_amount_mismatch');
        }

        $items = $payload['items'] ?? null;
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0])) {
            $this->reject(409, 'payment_items_mismatch');
        }
        $item = $items[0];
        foreach ([
            'skuCode' => (string)$mapping->sku_code,
            'productName' => (string)$mapping->product_name,
            'productType' => (string)$mapping->product_type,
            'currency' => (string)$mapping->currency
        ] as $field => $expected) {
            // item.currency is accepted when supplied; current event contract does not require it.
            if ($field === 'currency' && !array_key_exists($field, $item)) {
                continue;
            }
            if (!is_string($item[$field] ?? null) || !hash_equals($expected, $item[$field])) {
                $this->reject(409, 'payment_item_' . $field . '_mismatch');
            }
        }
        if (!is_int($item['quantity'] ?? null) || $item['quantity'] !== (int)$mapping->quantity) {
            $this->reject(409, 'payment_item_quantity_mismatch');
        }
        foreach (['unitPrice' => 'unit_price', 'totalAmount' => 'total_amount'] as $eventField => $column) {
            $value = $item[$eventField] ?? null;
            try {
                $actual = (!is_int($value) && !is_float($value) && !is_string($value))
                    ? ''
                    : Protocol::exactPositiveMoney($value);
            } catch (\InvalidArgumentException) {
                $actual = '';
            }
            if (!hash_equals(Protocol::money((string)$mapping->{$column}), $actual)) {
                $this->reject(409, 'payment_item_amount_mismatch');
            }
        }
    }

    private function requiredString(array $payload, string $field, int $maxLength): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            $this->reject(400, 'invalid_' . $field);
        }
        return $value;
    }

    private function sameMoney(string|int|float $expected, string|int|float $actual): bool
    {
        try {
            return hash_equals(Protocol::money($expected), Protocol::exactPositiveMoney($actual));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function reject(int $status, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        throw new JSONException($message, $status);
    }
}
