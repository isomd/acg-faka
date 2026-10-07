<?php
declare(strict_types=1);

// Real Eloquent/SQLite, real order fulfillment and real redemption controller.
// No production configuration, network, payment or supplier credentials used.
error_reporting(E_ALL & ~E_DEPRECATED);
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for RedeemPurchaseTest\n");
    exit(1);
}
$testRoot = sys_get_temp_dir() . '/acg-redeem-purchase-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
define('BASE_PATH', $testRoot);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Model\Commodity;
use App\Model\Order as OrderModel;
use App\Model\RedeemCode as CodeModel;
use App\Model\RedeemRecord;
use App\Util\RedeemCode;
use App\Util\RedeemPurchase;
use App\Util\Schema;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Exception\JSONException;

function lang(?string $text, string $group = ''): string {return $text ?? '';}
// Template compilation needs the callable names, not the production helpers.
function t(string $text): string {return $text;}
function item_var(mixed $item): string {return '';}
function contact_type_msg(mixed $type): string {return '';}
function ready(string $asset): string {return '';}
function widget_render(mixed $widget): string {return '';}
function hook(int $point, mixed &...$args): mixed
{
    if ($point === App\Consts\Hook::USER_API_ORDER_DELIVERY_BEGIN && ($GLOBALS['holdDelivery'] ?? false)) {
        $args[0]->action = App\Entity\RiskContext::REVIEW;
    }
    if ($point === App\Consts\Hook::USER_API_ORDER_PAY_AFTER && ($GLOBALS['failAfterIssue'] ?? false)) {
        throw new JSONException('test: post-delivery failure');
    }
    return null;
}
function config(string $key): array
{
    return ['database' => ':memory:', 'password' => 'public-test-only', 'username' => 'test', 'prefix' => 't_'];
}
function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function inject(object $object, string $declaringClass, string $property, object $value): void
{
    $reflection = new ReflectionProperty($declaringClass, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($object, $value);
}

final class FakeDolaSupplier extends App\Service\Bind\Shared
{
    public int $remaining = 200;
    public int $calls = 0;
    public bool $fail = false;
    public string $lastRequestNo = '';
    private array $batches = [];

    public function getItemStock(Commodity $commodity, App\Model\Shared $shared, string $code, ?string $race = null, ?array $sku = []): string
    {
        return (string)$this->remaining;
    }

    public function trade(App\Model\Shared $shared, Commodity $commodity, string $contact, int $num, int $cardId, int $device, string $password, string $race, ?array $sku, ?string $widget, string $requestNo): string
    {
        if ($this->fail) throw new JSONException('test: supplier unavailable');
        $this->lastRequestNo = $requestNo;
        if (!isset($this->batches[$requestNo])) {
            $this->calls++;
            $this->remaining -= $num;
            $this->batches[$requestNo] = implode("\n", array_map(fn($i) => "test{$i}@example.invalid----password----cookie", range(1, $num)));
        }
        return $this->batches[$requestNo];
    }
}

final class RedeemRequest extends Kernel\Context\Abstract\Request
{
    public function __construct(private array $values) {}
    public function post(?string $key = null, int $flags = 0): mixed {return $key === null ? $this->values : ($this->values[$key] ?? null);}
}

function pendingOrder(int $quantity = 100): OrderModel
{
    $order = new OrderModel();
    $order->commodity_id = 2;
    $order->trade_no = App\Util\Str::generateTradeNo();
    $order->card_num = $quantity;
    $order->amount = 80;
    $order->contact = '';
    $order->create_device = 0;
    $order->status = 0;
    $order->delivery_status = 0;
    $order->fulfillment_mode = RedeemPurchase::CODE;
    $order->fulfillment_shared_id = 1;
    $order->leave_message = 'Original delivery note';
    $order->create_time = date('Y-m-d H:i:s');
    $order->save();
    return $order;
}

try {
    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 't_']);
    $db->setAsGlobal();$db->bootEloquent();
    DB::schema()->create('config', function (Blueprint $t): void {
        $t->increments('id');$t->string('key')->unique();$t->text('value');
    });
    DB::schema()->create('shared', function (Blueprint $t): void {
        $t->increments('id');$t->integer('type');$t->string('name')->nullable();
    });
    DB::schema()->create('pay', function (Blueprint $t): void {
        $t->increments('id');$t->string('handle')->nullable();
    });
    DB::table('pay')->insert(['id' => 1, 'handle' => 'system']);
    DB::schema()->create('commodity', function (Blueprint $t): void {
        $t->increments('id');
        foreach (['owner', 'shared_id', 'delivery_way', 'draft_status', 'send_email', 'contact_type'] as $field) $t->integer($field)->default(0);
        foreach (['config', 'shared_code', 'name', 'leave_message'] as $field) $t->text($field)->nullable();
    });
    DB::schema()->create('order', function (Blueprint $t): void {
        $t->increments('id');$t->string('trade_no')->unique();$t->string('request_no')->nullable()->unique();
        foreach (['commodity_id', 'owner', 'user_id', 'card_num', 'pay_id', 'status', 'delivery_status', 'create_device', 'from', 'divide_amount', 'rebate', 'rent'] as $field) $t->integer($field)->default(0);
        $t->integer('card_id')->nullable();$t->decimal('amount', 10, 2)->default(0);
        foreach (['create_time', 'pay_time', 'secret', 'leave_message', 'contact', 'password', 'create_ip', 'widget', 'race', 'sku'] as $field) $t->text($field)->nullable();
    });
    // Begin with the OLD redeem table to exercise additive upgrade migration.
    DB::schema()->create('redeem_code', function (Blueprint $t): void {
        $t->increments('id');$t->string('code_hash')->unique();$t->string('code_mask');
        $t->integer('commodity_id');$t->integer('quantity');$t->integer('status')->default(0);
        $t->integer('order_id')->nullable();
        foreach (['batch_no', 'note', 'create_time', 'used_time', 'used_ip'] as $field) $t->text($field)->nullable();
    });
    DB::table('shared')->insert(['id' => 1, 'type' => 3]);
    DB::table('commodity')->insert(['id' => 2, 'owner' => 0, 'shared_id' => 1, 'delivery_way' => 0,
        'shared_code' => 'dola-account', 'name' => 'Dola accounts',
        'config' => "[wholesale]\n100=0.80\n150=0.75\n1600=0.70\n3750=0.65"]);
    $product = Commodity::with('shared')->find(2);
    RedeemPurchase::prepare($product);
    verify(DB::schema()->hasColumn('order', 'fulfillment_mode'), 'order snapshot migration failed');
    verify(DB::schema()->hasColumn('redeem_code', 'purchase_order_id'), 'purchase code migration failed');
    verify(DB::schema()->hasTable('redeem_record'), 'redemption history migration failed');

    $supplier = new FakeDolaSupplier();
    $orders = new App\Service\Bind\Order();
    inject($orders, App\Service\Bind\Order::class, 'shared', $supplier);
    $shop = new App\Service\Bind\Shop();
    inject($shop, App\Service\Bind\Shop::class, 'shared', $supplier);
    $controller = new App\Controller\User\Api\Redeem();
    inject($controller, App\Controller\User\Api\Redeem::class, 'order', $orders);
    inject($controller, App\Controller\User\Api\Redeem::class, 'shared', $supplier);

    $purchase = pendingOrder();
    verify(RedeemPurchase::reserved(1) === 100, 'pending purchase must reserve its quantity');
    verify(RedeemPurchase::available(200, 1) === 100, 'pending reservation not subtracted');
    $plain = $orders->orderSuccess($purchase);
    verify($supplier->calls === 0 && $supplier->remaining === 200, 'payment unexpectedly fetched accounts');
    verify(str_starts_with($plain, 'DOLA-'), 'payment did not deliver a redemption code');
    $code = CodeModel::query()->where('purchase_order_id', $purchase->id)->first();
    verify($code !== null && $code->quantity === 100 && $code->used_quantity === 0, 'purchased quota incorrect');
    verify($code->shared_id === 1 && $code->code_hash === RedeemCode::digest($plain), 'code source/hash mismatch');
    verify($purchase->status === 1 && $purchase->delivery_status === 1, 'purchase delivery not completed');
    verify(str_contains($purchase->leave_message, '100') && str_contains($purchase->leave_message, 'Original'), 'pickup instructions lost');
    verify(RedeemPurchase::reserved(1) === 100, 'issued quota double-counted with pending purchase');
    $stale = OrderModel::find($purchase->id);$stale->status = 0;$stale->delivery_status = 0;
    verify($orders->orderSuccess($stale) === $plain && CodeModel::count() === 1, 'callback retry duplicated/replaced code');
    verify($supplier->calls === 0, 'callback retry fetched supplier accounts');

    $partial = $controller->submit(new RedeemRequest(['code' => $plain, 'quantity' => '10', 'request_token' => 'redeem-test-token-0001']));
    verify($partial['data']['remainingQuantity'] === 90 && count($partial['data']['items']) === 10, 'partial redemption failed');
    verify($supplier->calls === 1 && $supplier->remaining === 190, 'redemption did not fetch exactly ten');
    verify(CodeModel::count() === 1, 'redemption recursively generated another code');
    verify(RedeemRecord::count() === 1 && RedeemPurchase::reserved(1) === 90, 'remaining quota/history incorrect');
    verify(RedeemPurchase::available(190, 1) === 100, 'redemption changed unallocated stock');
    $repeat = $controller->submit(new RedeemRequest(['code' => $plain, 'quantity' => '10', 'request_token' => 'redeem-test-token-0001']));
    verify($repeat['data']['repeated'] && $supplier->calls === 1 && RedeemRecord::count() === 1, 'redemption retry fetched/deducted twice');
    verify(OrderModel::query()->where('trade_no', $partial['data']['tradeNo'])->first()->fulfillment_mode == 0, 'redemption order did not retain direct delivery');

    foreach ([0, 91, 1001] as $invalidQuantity) {
        try {
            $controller->submit(new RedeemRequest(['code' => $plain, 'quantity' => (string)$invalidQuantity, 'request_token' => 'redeem-test-token-invalid']));
            throw new RuntimeException('invalid redemption quantity accepted');
        } catch (JSONException) {}
    }
    verify($code->fresh()->used_quantity === 10 && $supplier->calls === 1, 'rejected quantity consumed entitlement');
    $supplier->fail = true;
    try {
        $controller->submit(new RedeemRequest(['code' => $plain, 'quantity' => '20', 'request_token' => 'redeem-test-token-failure']));
        throw new RuntimeException('supplier failure accepted');
    } catch (JSONException) {}
    $supplier->fail = false;
    verify($code->fresh()->used_quantity === 10 && RedeemRecord::count() === 1, 'supplier failure deducted quota');

    // All physical stock may be committed: redemption must still use PHYSICAL
    // stock rather than the public "available for sale" number.
    $other = pendingOrder(100);$orders->orderSuccess($other);
    verify($shop->getItemStock($product) === '0', 'sold-out quota still advertised as saleable');
    $rest = $controller->submit(new RedeemRequest(['code' => $plain, 'quantity' => '90', 'request_token' => 'redeem-test-token-final00']));
    verify($rest['data']['remainingQuantity'] === 0 && $supplier->remaining === 100, 'zero saleable stock blocked rightful redemption');
    verify($code->fresh()->status === 1 && CodeModel::count() === 2, 'exhaustion created a code or left quota open');
    try {
        $controller->submit(new RedeemRequest(['code' => $plain, 'quantity' => '1', 'request_token' => 'redeem-test-token-empty00']));
        throw new RuntimeException('exhausted code redeemed');
    } catch (JSONException) {}

    // Direct/historical orders keep account delivery, not a newly minted code.
    $legacy = pendingOrder(1);$legacy->fulfillment_mode = 0;$legacy->fulfillment_shared_id = 0;$legacy->save();
    $legacySecret = $orders->orderSuccess($legacy);
    verify(str_contains($legacySecret, '----') && CodeModel::count() === 2, 'historical direct order changed delivery mode');

    // Code creation and order fulfillment must roll back together.
    $failure = pendingOrder(1);$GLOBALS['failAfterIssue'] = true;
    try {$orders->orderSuccess($failure);throw new RuntimeException('post-delivery failure accepted');} catch (JSONException) {}
    $GLOBALS['failAfterIssue'] = false;
    verify(CodeModel::query()->where('purchase_order_id', $failure->id)->count() === 0, 'failed payment fulfillment left an orphan code');
    verify($failure->fresh()->status === 0 && $failure->fresh()->delivery_status === 0, 'failed issuance committed purchase');
    $orders->orderSuccess($failure);
    verify(CodeModel::query()->where('purchase_order_id', $failure->id)->count() === 1, 'failed issuance cannot safely retry');

    // Source snapshot prevents an administrator moving an owed quota to a
    // different supplier without explicitly reconciling it.
    DB::table('shared')->insert(['id' => 3, 'type' => 3]);
    DB::table('commodity')->where('id', 2)->update(['shared_id' => 3]);
    verify(RedeemPurchase::reserved(1) === 101, 'source change freed outstanding quota');
    try {
        $controller->submit(new RedeemRequest(['code' => $other->secret, 'quantity' => '1', 'request_token' => 'redeem-test-source-change']));
        throw new RuntimeException('changed supplier accepted');
    } catch (JSONException) {}
    DB::table('commodity')->where('id', 2)->update(['shared_id' => 1]);
    DB::table('redeem_code')->where('purchase_order_id', $other->id)->update(['status' => 2]);
    verify(RedeemPurchase::reserved(1) === 101, 'locking a code released owed quota');
    DB::table('redeem_code')->where('purchase_order_id', $other->id)->update(['status' => 0]);

    $unpaid = pendingOrder(3);
    verify(RedeemPurchase::reserved(1) === 104, 'new pending order not reserved');
    $unpaid->delete();
    verify(RedeemPurchase::reserved(1) === 101, 'pending-order cleanup did not release quota');
    verify(DB::table('commodity')->where('id', 2)->value('config') === $product->config, 'wholesale prices were overwritten');

    $unpaid = pendingOrder(1);
    try {
        DB::transaction(fn() => RedeemPurchase::issue($unpaid));
        throw new RuntimeException('unpaid purchase issued a code');
    } catch (JSONException) {}
    verify(CodeModel::query()->where('purchase_order_id', $unpaid->id)->count() === 0, 'unpaid issuance left a code');
    $unpaid->delete();

    $changed = pendingOrder(1);
    DB::table('commodity')->where('id', 2)->update(['shared_id' => 3]);
    try {$orders->orderSuccess($changed);throw new RuntimeException('changed purchase supplier accepted');} catch (JSONException) {}
    verify($changed->fresh()->status === 0 && CodeModel::query()->where('purchase_order_id', $changed->id)->count() === 0, 'source mismatch committed issuance');
    DB::table('commodity')->where('id', 2)->update(['shared_id' => 1]);
    $changed->delete();

    $held = pendingOrder(1);$beforeHeld = RedeemPurchase::reserved(1);$GLOBALS['holdDelivery'] = true;
    $orders->orderSuccess($held);$GLOBALS['holdDelivery'] = false;
    verify($held->status === 1 && $held->delivery_status === 0 && CodeModel::query()->where('purchase_order_id', $held->id)->count() === 0, 'review-held purchase issued a code');
    verify(RedeemPurchase::reserved(1) === $beforeHeld, 'review hold released purchased quota');
    $orders->orderSuccess($held);
    verify(CodeModel::query()->where('purchase_order_id', $held->id)->count() === 1, 'review-approved purchase not issued');

    $legacyPlain = RedeemCode::generate();$beforeLegacy = RedeemPurchase::reserved(1);
    $manual = new CodeModel();
    $manual->code_hash = RedeemCode::digest($legacyPlain);$manual->code_mask = RedeemCode::mask($legacyPlain);
    $manual->commodity_id = 2;$manual->quantity = 5;$manual->used_quantity = 2;$manual->status = 0;
    $manual->create_time = date('Y-m-d H:i:s');$manual->save();
    verify(RedeemPurchase::reserved(1) === $beforeLegacy + 3, 'legacy/admin quota not counted');
    $manual->delete();

    // Model a successful external pickup followed by a local rollback. The real
    // supplier writes this ledger through an independent connection; here seed
    // its snapshot AFTER rollback, without making any network call.
    Schema::ensureDolaPickup();$supplier->remaining = 100;$GLOBALS['failAfterIssue'] = true;
    try {
        $controller->submit(new RedeemRequest(['code' => $other->secret, 'quantity' => '100', 'request_token' => 'redeem-test-rollback-100']));
        throw new RuntimeException('post-pickup failure accepted');
    } catch (JSONException) {}
    $GLOBALS['failAfterIssue'] = false;
    verify($supplier->remaining === 0 && CodeModel::query()->where('purchase_order_id', $other->id)->value('used_quantity') === 0, 'rollback consumed quota or failed to model external pickup');
    DB::table('dola_pickup_delivery')->insert([
        'shared_id' => 1, 'request_no' => $supplier->lastRequestNo, 'sequence' => 1, 'order_quantity' => 100,
        'source_hash' => str_repeat('a', 64), 'request_tag' => 'test-recovery', 'requested' => 100,
        'status' => 1, 'payload' => '[]', 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
    ]);
    $callsAfterRollback = $supplier->calls;
    $recovered = $controller->submit(new RedeemRequest(['code' => $other->secret, 'quantity' => '100', 'request_token' => 'redeem-test-rollback-100']));
    verify($recovered['data']['remainingQuantity'] === 0 && count($recovered['data']['items']) === 100, 'last physical batch could not be recovered after rollback');
    verify($supplier->calls === $callsAfterRollback && $supplier->remaining === 0, 'rollback retry picked up twice');

    $smarty = new Smarty();
    $smarty->left_delimiter = '#{';$smarty->right_delimiter = '}';
    $smarty->setCompileDir($testRoot . '/smarty');
    $smarty->createTemplate(dirname(__DIR__) . '/app/View/User/Theme/Cartoon/Index/Item.html')->compileTemplateSource();

    echo "Redeem purchase issuance, partial pickup, retries, rollback, stock and template tests passed\n";
} finally {
    DB::disconnect();
    // Only the random test-owned temporary directory is removed.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($testRoot);
}
