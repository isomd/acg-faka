<?php
declare(strict_types=1);

namespace App\Pay\Mercury\Impl;

use App\Entity\PayEntity;
use App\Model\MercuryOrder;
use App\Model\Order as LocalOrder;
use App\Model\Pay as PaymentMethod;
use App\Model\UserRecharge;
use App\Pay\Base;
use App\Pay\Mercury\Protocol;
use App\Util\Date;
use App\Util\PayTest;
use App\Util\Schema;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Kernel\Exception\JSONException;

final class Pay extends Base implements \App\Pay\Pay
{
    public function trade(): PayEntity
    {
        Schema::ensureMercuryPayment();
        $config = $this->validatedConfig();
        $snapshot = $this->localSnapshot($config);
        $mapping = $this->mapping($snapshot, $config);

        if ((string)$mapping->checkout_url !== '') {
            return $this->redirect((string)$mapping->checkout_url);
        }

        $body = [
            'skuCode' => $snapshot['sku_code'],
            'productName' => $snapshot['product_name'],
            'productType' => $snapshot['product_type'],
            'userId' => $snapshot['user_id'],
            'clientOrderNo' => $this->tradeNo,
            'quantity' => 1,
            'unitPrice' => (float)$snapshot['total_amount'],
            'totalAmount' => (float)$snapshot['total_amount'],
            'currency' => $config['currency'],
            'benefitSnapshot' => null,
            'paymentMethodCode' => $config['payment_method_code'],
            'returnParams' => Protocol::returnParams($this->tradeNo)
        ];

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $signature = Protocol::orderSignature($body, $config['app_key'], $config['app_secret'], $timestamp);

        try {
            $response = (new Client([
                'base_uri' => $config['endpoint'],
                'verify' => true,
                'connect_timeout' => 8,
                'timeout' => 20,
                'http_errors' => false
            ]))->post('/internal/orders', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'X-Mercury-App-Key' => $config['app_key'],
                    'X-Mercury-Timestamp' => $timestamp,
                    'X-Mercury-Signature' => $signature
                ],
                'json' => $body
            ]);
        } catch (GuzzleException $e) {
            $mapping->status = 'UNKNOWN';
            $mapping->update_time = Date::current();
            $mapping->save();
            $this->log('Mercury 下单网络结果未知，业务单号：' . $this->tradeNo);
            throw new JSONException('支付平台暂未返回结果，请勿重复付款并联系管理员核对订单');
        }

        $status = $response->getStatusCode();
        $payload = json_decode((string)$response->getBody(), true);
        if ($status < 200 || $status >= 300 || !is_array($payload) || ($payload['code'] ?? '') !== 'mercury.success') {
            $mapping->status = 'FAILED';
            $mapping->update_time = Date::current();
            $mapping->save();
            $code = is_array($payload) ? (string)($payload['code'] ?? 'invalid_response') : 'invalid_response';
            $this->log('Mercury 下单失败，业务单号：' . $this->tradeNo . '，状态：' . $status . '，错误码：' . $code);
            throw new JSONException('支付平台下单失败（' . $code . '），请联系管理员检查支付配置');
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new JSONException('支付平台返回的数据格式不正确');
        }

        $orderNo = trim((string)($data['orderNo'] ?? ''));
        $transactionNo = trim((string)($data['transactionNo'] ?? ''));
        $checkoutUrl = trim((string)($data['checkoutUrl'] ?? ''));
        $currency = trim((string)($data['currency'] ?? ''));
        try {
            $actualAmount = Protocol::exactPositiveMoney(is_scalar($data['totalAmount'] ?? null) ? $data['totalAmount'] : '');
        } catch (\InvalidArgumentException) {
            $actualAmount = '';
        }
        if (
            $orderNo === '' || $transactionNo === ''
            || !filter_var($checkoutUrl, FILTER_VALIDATE_URL)
            || strtolower((string)parse_url($checkoutUrl, PHP_URL_SCHEME)) !== 'https'
            || !hash_equals($config['currency'], $currency)
            || !hash_equals($snapshot['total_amount'], $actualAmount)
        ) {
            $mapping->status = 'MISMATCH';
            $mapping->update_time = Date::current();
            $mapping->save();
            $this->log('Mercury 下单响应与本地快照不一致，业务单号：' . $this->tradeNo);
            throw new JSONException('支付平台返回的订单信息与本地订单不一致，已停止跳转');
        }

        $existing = MercuryOrder::query()
            ->where('tenant_id', $config['tenant_id'])
            ->where('app_id', $config['app_id'])
            ->where(function ($query) use ($orderNo, $transactionNo) {
                $query->where('mercury_order_no', $orderNo)->orWhere('transaction_no', $transactionNo);
            })
            ->where('id', '<>', $mapping->id)
            ->first();
        if ($existing) {
            throw new JSONException('支付平台订单标识发生冲突，已停止处理');
        }

        $mapping->mercury_order_no = $orderNo;
        $mapping->transaction_no = $transactionNo;
        $mapping->checkout_url = $checkoutUrl;
        $mapping->status = (string)($data['orderStatus'] ?? 'PENDING');
        $mapping->update_time = Date::current();
        $mapping->save();

        return $this->redirect($checkoutUrl);
    }

    private function validatedConfig(): array
    {
        if (!extension_loaded('openssl')) {
            throw new JSONException('PHP 未启用 OpenSSL 扩展，无法安全连接 Mercury');
        }
        $config = [];
        foreach (['endpoint', 'tenant_id', 'app_id', 'app_key', 'app_secret', 'payment_method_code', 'currency'] as $key) {
            $config[$key] = Protocol::trimJava((string)($this->config[$key] ?? ''));
            if ($config[$key] === '') {
                throw new JSONException('Mercury 支付配置不完整：' . $key);
            }
        }
        $config['endpoint'] = rtrim($config['endpoint'], '/');
        if (!filter_var($config['endpoint'], FILTER_VALIDATE_URL) || strtolower((string)parse_url($config['endpoint'], PHP_URL_SCHEME)) !== 'https') {
            throw new JSONException('Mercury API 地址必须是有效的 HTTPS 地址');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $config['tenant_id'])
            || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $config['app_id'])
            || !preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $config['payment_method_code'])
            || !preg_match('/^[A-Z]{3,8}$/D', $config['currency'])) {
            throw new JSONException('Mercury 应用、支付方式或币种配置格式不正确');
        }
        return $config;
    }

    private function localSnapshot(array $config): array
    {
        try {
            $amount = Protocol::exactPositiveMoney($this->amount);
        } catch (\InvalidArgumentException) {
            throw new JSONException('Mercury 支付金额必须在 0.01 到 99999999.99 之间');
        }

        if (str_contains(strtolower($this->callbackUrl), 'callbacktest')) {
            $test = PayTest::get($this->tradeNo);
            $payment = is_array($test) ? PaymentMethod::query()->find((int)($test['pay_id'] ?? 0)) : null;
            if (!$payment || (string)$payment->handle !== 'Mercury') {
                throw new JSONException('Mercury 拨测记录不存在或已过期');
            }
            return [
                'local_type' => 'test',
                'pay_config_id' => (int)$payment->pay_config_id,
                'user_id' => 'test:' . $this->tradeNo,
                'sku_code' => 'acg-faka-payment-test',
                'product_name' => '支付接口拨测',
                'product_type' => 'PAYMENT_TEST',
                'total_amount' => $amount
            ];
        }

        if (str_contains(strtolower($this->callbackUrl), 'rechargenotification')) {
            $local = UserRecharge::with('pay')->where('trade_no', $this->tradeNo)->first();
            if (!$local || !$local->pay || (string)$local->pay->handle !== 'Mercury') {
                throw new JSONException('Mercury 充值订单尚未持久化，已拒绝向支付平台下单');
            }
            return [
                'local_type' => 'recharge',
                'pay_config_id' => (int)$local->pay->pay_config_id,
                'user_id' => 'account:' . (int)$local->user_id,
                'sku_code' => 'acg-faka-recharge',
                'product_name' => '余额充值',
                'product_type' => 'ACCOUNT_RECHARGE',
                'total_amount' => $amount
            ];
        }

        $local = LocalOrder::with(['pay', 'commodity'])->where('trade_no', $this->tradeNo)->first();
        if (!$local || !$local->pay || (string)$local->pay->handle !== 'Mercury') {
            throw new JSONException('Mercury 商品订单尚未持久化，已拒绝向支付平台下单');
        }
        $name = Protocol::trimJava((string)($local->commodity?->name ?? '商品订单'));
        if ($name === '') {
            $name = '商品订单';
        }
        return [
            'local_type' => 'order',
            'pay_config_id' => (int)$local->pay->pay_config_id,
            'user_id' => (int)$local->owner > 0 ? 'account:' . (int)$local->owner : 'guest:' . $this->tradeNo,
            'sku_code' => 'commodity:' . (int)$local->commodity_id,
            'product_name' => mb_substr($name . ((int)$local->card_num > 1 ? ' × ' . (int)$local->card_num : ''), 0, 255),
            'product_type' => 'DIGITAL_GOODS',
            'total_amount' => $amount
        ];
    }

    private function mapping(array $snapshot, array $config): MercuryOrder
    {
        $mapping = MercuryOrder::query()
            ->where('tenant_id', $config['tenant_id'])
            ->where('app_id', $config['app_id'])
            ->where('client_order_no', $this->tradeNo)
            ->first();
        $expected = [
            'pay_config_id' => $snapshot['pay_config_id'],
            'local_type' => $snapshot['local_type'],
            'local_trade_no' => $this->tradeNo,
            'user_id' => $snapshot['user_id'],
            'sku_code' => $snapshot['sku_code'],
            'product_name' => $snapshot['product_name'],
            'product_type' => $snapshot['product_type'],
            'quantity' => 1,
            'unit_price' => $snapshot['total_amount'],
            'total_amount' => $snapshot['total_amount'],
            'currency' => $config['currency'],
            'payment_method_code' => $config['payment_method_code']
        ];

        if ($mapping) {
            foreach ($expected as $key => $value) {
                $actual = in_array($key, ['unit_price', 'total_amount'], true)
                    ? Protocol::money((string)$mapping->{$key})
                    : (string)$mapping->{$key};
                if (!hash_equals((string)$value, $actual)) {
                    throw new JSONException('同一业务订单的 Mercury 支付快照发生变化，已拒绝重试');
                }
            }
            return $mapping;
        }

        $mapping = new MercuryOrder();
        foreach ($expected as $key => $value) {
            $mapping->{$key} = $value;
        }
        $mapping->tenant_id = $config['tenant_id'];
        $mapping->app_id = $config['app_id'];
        $mapping->client_order_no = $this->tradeNo;
        $mapping->status = 'CREATING';
        $mapping->effect_status = 'PENDING';
        $mapping->create_time = Date::current();
        $mapping->update_time = Date::current();
        $mapping->save();
        return $mapping;
    }

    private function redirect(string $url): PayEntity
    {
        $entity = new PayEntity();
        $entity->setType(\App\Pay\Pay::TYPE_REDIRECT);
        $entity->setUrl($url);
        return $entity;
    }
}
