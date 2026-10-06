<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Pay/Mercury/Protocol.php';

use App\Pay\Mercury\Protocol;

function assertSameValue(string $label, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// These expected signatures were generated independently with Node.js crypto.
$request = [
    'skuCode' => 'sku-demo',
    'productName' => '测试商品',
    'productType' => 'DIGITAL_GOODS',
    'quantity' => 2,
    'unitPrice' => '7.00',
    'totalAmount' => '14.00',
    'currency' => 'CNY',
    'benefitSnapshot' => null,
    'clientOrderNo' => 'local-20260930-001'
];
assertSameValue('money integer', '7.00', Protocol::money('7'));
assertSameValue('money positive half-up', '1.01', Protocol::money('1.005'));
assertSameValue('money negative half-up', '-1.01', Protocol::money('-1.005'));
assertSameValue('exact business money', '14.00', Protocol::exactPositiveMoney(14.0));
try {
    Protocol::exactPositiveMoney('14.001');
    assertSameValue('over-precise business money rejected', true, false);
} catch (InvalidArgumentException) {
    assertSameValue('over-precise business money rejected', true, true);
}
assertSameValue(
    'order signature',
    'a00ab46e2f588000068f4737d2107651f960065f694fc9a1f6f7ba670c5ff295',
    Protocol::orderSignature($request, 'public-app-key', 'public-test-secret', '2026-09-30T00:00:00Z')
);

$body = '{"eventId":"evt-demo-001","eventType":"WebhookTest","tenantId":"demo","appId":"demo-app"}';
$webhookSignature = Protocol::webhookSignature(
    'evt-demo-001',
    '2026-09-30T00:00:01Z',
    $body,
    'public-test-secret'
);
assertSameValue('webhook signature', 'ce11cf676ee7bf051a1e7e7a391d9dc118ab813fa3df7ed783b1ceae444a5fa6', $webhookSignature);
assertSameValue('signature format', true, Protocol::validHexSignature($webhookSignature));
assertSameValue(
    'raw body is signed byte-for-byte',
    false,
    hash_equals($webhookSignature, Protocol::webhookSignature('evt-demo-001', '2026-09-30T00:00:01Z', $body . "\n", 'public-test-secret'))
);
assertSameValue('uppercase signature rejected', false, Protocol::validHexSignature(strtoupper($webhookSignature)));

echo "Mercury protocol tests passed\n";
