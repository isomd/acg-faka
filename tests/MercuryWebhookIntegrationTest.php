<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

$databaseConfigFile = getenv('ACG_TEST_DB_CONFIG');
if (!$databaseConfigFile || !is_file($databaseConfigFile)) {
    fwrite(STDERR, "SKIP: set ACG_TEST_DB_CONFIG to a local database config file\n");
    exit(0);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Pay\Mercury\Protocol;
use App\Pay\Mercury\Webhook;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Context\Interface\Request;

$database = require $databaseConfigFile;
$capsule = new DB();
$capsule->addConnection($database);
$capsule->setAsGlobal();
$capsule->bootEloquent();
App\Util\Schema::ensureMercuryPayment();

function webhookRequest(
    string $body,
    string $signature,
    string $eventId = 'evt-public-webhook-test',
    string $timestamp = '2026-09-30T00:00:01Z'
): Request
{
    return new class($body, $signature, $eventId, $timestamp) implements Request {
        public function __construct(
            private string $body,
            private string $signature,
            private string $eventId,
            private string $timestamp
        ) {}
        public function method(): string { return 'POST'; }
        public function all(int $flags = 0): mixed { return []; }
        public function post(?string $key = null, int $flags = 0): mixed { return []; }
        public function unsafePost(?string $key = null): mixed { return []; }
        public function xml(?string $key = null, int $flags = 0): mixed { return []; }
        public function get(?string $key = null, int $flags = 0): mixed { return []; }
        public function unsafeGet(?string $key = null): mixed { return []; }
        public function header(?string $key = null): mixed
        {
            $headers = [
                'XMercuryEventId' => $this->eventId,
                'XMercuryTimestamp' => $this->timestamp,
                'XMercuryEventVersion' => '1',
                'XMercurySignature' => $this->signature
            ];
            return $key === null ? $headers : ($headers[$key] ?? null);
        }
        public function cookie(?string $key = null): mixed { return []; }
        public function json(?string $key = null, int $flags = 0): mixed { return []; }
        public function unsafeJson(?string $key = null): mixed { return []; }
        public function file(?string $key = null): mixed { return []; }
        public function uri(): string { return '/user/api/mercury/webhook'; }
        public function uriSuffix(): string { return ''; }
        public function setProperty(string $property, mixed $value): void {}
        public function url(): string { return 'http://127.0.0.1'; }
        public function domain(): string { return '127.0.0.1'; }
        public function raw(): string { return $this->body; }
    };
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$secret = 'public-webhook-integration-test-secret';
$body = json_encode([
    'eventId' => 'evt-public-webhook-test',
    'eventVersion' => '1',
    'eventType' => 'WebhookTest',
    'tenantId' => 'public-test-tenant',
    'appId' => 'public-test-app',
    'testedAt' => '2026-09-30T00:00:01Z'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$signature = Protocol::webhookSignature('evt-public-webhook-test', '2026-09-30T00:00:01Z', $body, $secret);

DB::connection()->beginTransaction();
try {
    $configId = DB::table('pay_config')->insertGetId([
        'handle' => 'Mercury',
        'name' => 'Mercury webhook test ' . bin2hex(random_bytes(4)),
        'config' => json_encode([
            'tenant_id' => 'public-test-tenant',
            'app_id' => 'public-test-app',
            'app_secret' => $secret
        ], JSON_UNESCAPED_SLASHES),
        'sort' => 0,
        'create_time' => date('Y-m-d H:i:s')
    ]);

    $ordersBefore = DB::table('order')->count();
    $rechargesBefore = DB::table('user_recharge')->count();
    $handler = new Webhook();
    $orderService = new App\Service\Bind\Order();
    $rechargeService = new App\Service\Bind\Recharge();

    check($handler->handle(webhookRequest($body, $signature), $orderService, $rechargeService) === 'success', 'WebhookTest was not accepted');
    check($handler->handle(webhookRequest($body, $signature), $orderService, $rechargeService) === 'success', 'duplicate WebhookTest was not idempotent');
    check(DB::table('mercury_webhook_event')->where('event_id', 'evt-public-webhook-test')->count() === 1, 'event was not durably deduplicated');
    check(DB::table('order')->count() === $ordersBefore, 'WebhookTest changed commodity orders');
    check(DB::table('user_recharge')->count() === $rechargesBefore, 'WebhookTest changed recharge orders');

    try {
        $handler->handle(webhookRequest($body . "\n", $signature), $orderService, $rechargeService);
        check(false, 'one-byte body change was accepted');
    } catch (Kernel\Exception\JSONException $exception) {
        check($exception->getCode() === 401, 'one-byte body change returned the wrong error');
    }

    DB::table('mercury_order')->insert([
        'tenant_id' => 'public-test-tenant',
        'app_id' => 'public-test-app',
        'pay_config_id' => $configId,
        'client_order_no' => 'public-test-order',
        'local_type' => 'test',
        'local_trade_no' => 'public-test-order',
        'user_id' => 'test:public-test-order',
        'sku_code' => 'acg-faka-payment-test',
        'product_name' => '支付接口拨测',
        'product_type' => 'PAYMENT_TEST',
        'quantity' => 1,
        'unit_price' => '14.00',
        'total_amount' => '14.00',
        'currency' => 'CNY',
        'payment_method_code' => 'PUBLIC_TEST',
        'mercury_order_no' => 'mercury-public-order',
        'transaction_no' => 'mercury-public-transaction',
        'status' => 'PENDING',
        'effect_status' => 'PENDING',
        'create_time' => date('Y-m-d H:i:s'),
        'update_time' => date('Y-m-d H:i:s')
    ]);

    $payment = [
        'eventId' => 'evt-public-payment-1',
        'tenantId' => 'public-test-tenant',
        'appId' => 'public-test-app',
        'eventType' => 'PaymentSucceeded',
        'orderNo' => 'mercury-public-order',
        'transactionNo' => 'mercury-public-transaction',
        'clientOrderNo' => 'public-test-order',
        'userId' => 'test:public-test-order',
        'totalAmount' => 14.00,
        'currency' => 'CNY',
        'items' => [[
            'skuCode' => 'acg-faka-payment-test',
            'productName' => '支付接口拨测',
            'productType' => 'PAYMENT_TEST',
            'quantity' => 1,
            'unitPrice' => 14.00,
            'totalAmount' => 14.00,
            'benefitSnapshot' => null
        ]],
        'occurredAt' => '2026-09-30T00:01:00Z'
    ];
    $paymentBody = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $paymentSignature = Protocol::webhookSignature('evt-public-payment-1', '2026-09-30T00:01:01Z', $paymentBody, $secret);
    check($handler->handle(webhookRequest($paymentBody, $paymentSignature, 'evt-public-payment-1', '2026-09-30T00:01:01Z'), $orderService, $rechargeService) === 'success', 'PaymentSucceeded was not accepted');
    check(DB::table('mercury_order')->where('client_order_no', 'public-test-order')->value('effect_status') === 'COMPLETED', 'payment effect was not completed');

    $payment['eventId'] = 'evt-public-payment-2';
    $secondBody = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $secondSignature = Protocol::webhookSignature('evt-public-payment-2', '2026-09-30T00:01:02Z', $secondBody, $secret);
    check($handler->handle(webhookRequest($secondBody, $secondSignature, 'evt-public-payment-2', '2026-09-30T00:01:02Z'), $orderService, $rechargeService) === 'success', 'same payment with a new event ID was not idempotent');
    check(DB::table('mercury_webhook_event')->where('client_order_no', 'public-test-order')->count() === 2, 'distinct payment events were not recorded');

    $payment['eventId'] = 'evt-public-payment-bad-amount';
    $payment['totalAmount'] = 15.00;
    $badAmountBody = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $badAmountSignature = Protocol::webhookSignature('evt-public-payment-bad-amount', '2026-09-30T00:01:03Z', $badAmountBody, $secret);
    try {
        $handler->handle(webhookRequest($badAmountBody, $badAmountSignature, 'evt-public-payment-bad-amount', '2026-09-30T00:01:03Z'), $orderService, $rechargeService);
        check(false, 'mismatched payment amount was accepted');
    } catch (Kernel\Exception\JSONException $exception) {
        check($exception->getCode() === 409, 'mismatched payment amount returned the wrong error');
    }
    check(DB::table('mercury_webhook_event')->where('event_id', 'evt-public-payment-bad-amount')->count() === 0, 'rejected payment was marked processed');
    check(DB::table('order')->count() === $ordersBefore, 'payment test changed commodity orders');
    check(DB::table('user_recharge')->count() === $rechargesBefore, 'payment test changed recharge orders');

    echo "Mercury webhook integration tests passed\n";
} finally {
    DB::connection()->rollBack();
}
