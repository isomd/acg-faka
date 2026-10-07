<?php
declare(strict_types=1);

namespace App\Util;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/**
 * 新版本引入的字段，给老库补上。
 *
 * 本项目没有迁移系统：kernel/Install/Install.sql 只服务全新安装，
 * 老站升级是覆盖文件，数据库不会自动跟着变。而商品列表的查询是显式列出字段的，
 * 少一列就是整页 500，所以必须自愈。
 *
 * hasColumn() 每次都要查一次 information_schema，不能每个请求都跑，
 * 所以补完在 runtime 下打个标记，之后只花一次 is_file()。
 */
final class Schema
{
    private const MARK_DIR = BASE_PATH . '/runtime/schema';

    /** @var array<string, true> 本次请求内已确认过的，避免重复 is_file */
    private static array $checked = [];

    /**
     * @param string $table 不带前缀的表名
     * @param string $column 列名
     * @param callable(Blueprint): void $define 列定义
     */
    public static function ensureColumn(string $table, string $column, callable $define): void
    {
        $key = $table . '.' . $column;
        if (isset(self::$checked[$key])) {
            return;
        }
        self::$checked[$key] = true;

        $mark = self::MARK_DIR . '/' . str_replace('.', '_', $key);
        if (is_file($mark)) {
            return;
        }

        try {
            if (!Manager::schema()->hasColumn($table, $column)) {
                Manager::schema()->table($table, $define);
            }
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        } catch (\Throwable $e) {
            // 补列失败不能让页面挂掉：可能是数据库账号没有 ALTER 权限。
            // 不写标记，下次请求再试；真正的报错会由后续查询自己抛出来。
        }
    }

    /** 商品标签（#807）。列表与详情都要读，所以两边入口都得先叫一声 */
    public static function ensureCommodityTags(): void
    {
        self::ensureColumn('commodity', 'tags', static function (Blueprint $table): void {
            $table->string('tags', 1000)->nullable()->comment('商品标签：JSON [{text,color}]');
        });
    }

    /** 店铺共享的对方货币与结算汇率：非 CNY 站点接入 CNY 货源时按此换算金额 */
    public static function ensureSharedCurrency(): void
    {
        self::ensureColumn('shared', 'currency', static function (Blueprint $table): void {
            $table->string('currency', 8)->default('CNY')->comment('上游站点货币代码');
        });
        self::ensureColumn('shared', 'currency_rate', static function (Blueprint $table): void {
            $table->decimal('currency_rate', 18, 6)->default(0)->comment('结算汇率：1 上游货币 = ? 本站货币；0 = 按站点汇率自动');
        });
    }

    /**
     * 上游协议代次（店铺共享）。
     *
     * `/shared/commodity/item` 的入参与返回形状在 3.1.2 变过，`stock`/`draft`/`valuation`
     * 三个端点也是那之后才有的。每次都先打一发新端点再吃 404 的话，商品详情页每次访问
     * 都要多一次往返；探明一次记在店铺档案上，之后直奔正确的那条路。
     * 0=未探明，1=3.1.2 及以后，2=3.1.1 及更老。
     */
    public static function ensureSharedProtocol(): void
    {
        self::ensureColumn('shared', 'protocol', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('protocol')->default(0)->comment('上游协议代次：0=未探明，1=3.1.2+，2=3.1.1及更老');
        });
    }

    /**
     * Dola 分批提货协议。
     *
     * 多个 KEY 不能塞进 shared.app_key(varchar(64))，单独放在不参与列表序列化的 TEXT 列；
     * delivery 表保存每个订单已经从上游提过的批次，支付回调重放时据此保证不重复扣货。
     */
    public static function ensureDolaPickup(): void
    {
        self::ensureColumn('shared', 'dola_keys', static function (Blueprint $table): void {
            $table->text('dola_keys')->nullable()->comment('Dola 提货 KEY，一行一个；仅 type=3 使用');
        });

        if (self::tableExists('dola_pickup_delivery')) {
            self::ensureColumn('dola_pickup_delivery', 'order_quantity', static function (Blueprint $table): void {
                $table->unsignedInteger('order_quantity')->default(0)->after('sequence');
            });
            return;
        }

        try {
            Manager::schema()->create('dola_pickup_delivery', static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('shared_id');
                $table->string('request_no', 64);
                $table->unsignedInteger('sequence');
                $table->unsignedInteger('order_quantity');
                $table->char('source_hash', 64);
                $table->string('request_tag', 96);
                $table->unsignedInteger('requested');
                $table->unsignedInteger('before_remaining')->default(0);
                $table->unsignedInteger('before_times')->default(0);
                $table->unsignedTinyInteger('status')->default(0);
                $table->longText('payload')->nullable();
                $table->string('error', 255)->nullable();
                $table->dateTime('create_time');
                $table->dateTime('update_time');
                $table->unique(['shared_id', 'request_no', 'sequence'], 'dola_delivery_order_seq');
                $table->index(['shared_id', 'status'], 'dola_delivery_shared_status');
            });
            self::$tableKnown['dola_pickup_delivery'] = true;
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents(self::MARK_DIR . '/table_dola_pickup_delivery', (string)time());
        } catch (\Throwable $e) {
            //保存 Dola 货源时还会实际写表；没有 CREATE 权限会在那里给出明确失败，而不是拖到付款后。
        }
    }

    /**
     * 兑换码提货。此表只保存码本体的 HMAC 摘要；自动购买的明文沿用
     * 受保护的订单交付内容，后台生成/导入则只在对应响应中返回明文。
     */
    public static function ensureRedeemCode(): void
    {
        if (self::tableExists('redeem_code')) {
            self::ensureRedeemResultColumns();
            self::ensureRedeemRecord();
            return;
        }

        try {
            Manager::schema()->create('redeem_code', static function (Blueprint $table): void {
                $table->increments('id');
                $table->char('code_hash', 64)->unique('redeem_code_hash_unique');
                $table->string('code_mask', 64);
                $table->unsignedInteger('commodity_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('used_quantity')->default(0);
                $table->unsignedTinyInteger('status')->default(0);
                $table->unsignedInteger('order_id')->nullable();
                $table->unsignedInteger('purchase_order_id')->nullable()->unique('redeem_purchase_order_unique');
                $table->unsignedInteger('shared_id')->default(0)->index('redeem_code_shared');
                $table->char('result_trade_no', 19)->nullable();
                $table->string('result_product_name', 255)->nullable();
                $table->longText('result_secret')->nullable();
                $table->text('result_leave_message')->nullable();
                $table->string('batch_no', 32)->nullable();
                $table->string('note', 64)->nullable();
                $table->dateTime('create_time');
                $table->dateTime('used_time')->nullable();
                $table->string('used_ip', 64)->nullable();
                $table->index(['commodity_id', 'status'], 'redeem_code_commodity_status');
                $table->index('order_id', 'redeem_code_order');
                $table->index('batch_no', 'redeem_code_batch');
                $table->index('create_time', 'redeem_code_create_time');
            });
            self::$tableKnown['redeem_code'] = true;
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents(self::MARK_DIR . '/table_redeem_code', (string)time());
            self::ensureRedeemRecord();
        } catch (\Throwable) {
            //业务入口会再次查询该表并给出真实数据库错误；这里保持与其他升级自愈逻辑一致。
        }
    }

    /**
     * Mercury 下单快照与 Webhook 幂等账本。
     *
     * 两张表必须使用 InnoDB：支付效果、事件消费和本地订单状态需要处于同一事务。
     */
    public static function ensureMercuryPayment(): void
    {
        if (!self::tableExists('mercury_order')) {
            try {
                Manager::schema()->create('mercury_order', static function (Blueprint $table): void {
                    $table->bigIncrements('id');
                    $table->string('tenant_id', 64);
                    $table->string('app_id', 64);
                    $table->unsignedInteger('pay_config_id');
                    $table->string('client_order_no', 64);
                    $table->string('local_type', 16);
                    $table->string('local_trade_no', 64);
                    $table->string('user_id', 128);
                    $table->string('sku_code', 128);
                    $table->string('product_name', 255);
                    $table->string('product_type', 64);
                    $table->unsignedInteger('quantity')->default(1);
                    $table->decimal('unit_price', 18, 2);
                    $table->decimal('total_amount', 18, 2);
                    $table->string('currency', 8);
                    $table->string('payment_method_code', 128);
                    $table->string('mercury_order_no', 128)->nullable();
                    $table->string('transaction_no', 128)->nullable();
                    $table->text('checkout_url')->nullable();
                    $table->string('status', 32);
                    $table->string('effect_status', 32)->default('PENDING');
                    $table->dateTime('create_time');
                    $table->dateTime('update_time');
                    $table->dateTime('paid_time')->nullable();
                    $table->unique(['tenant_id', 'app_id', 'client_order_no'], 'mercury_order_client_unique');
                    $table->unique('mercury_order_no', 'mercury_order_no_unique');
                    $table->unique('transaction_no', 'mercury_transaction_no_unique');
                    $table->index(['local_type', 'local_trade_no'], 'mercury_order_local');
                    $table->index('pay_config_id', 'mercury_order_config');
                });
                self::$tableKnown['mercury_order'] = true;
            } catch (\Throwable) {
                // 实际下单会明确暴露表不存在或无 CREATE 权限，不能退回无幂等处理。
            }
        }

        if (!self::tableExists('mercury_webhook_event')) {
            try {
                Manager::schema()->create('mercury_webhook_event', static function (Blueprint $table): void {
                    $table->bigIncrements('id');
                    $table->string('event_id', 128)->unique('mercury_event_id_unique');
                    $table->string('tenant_id', 64);
                    $table->string('app_id', 64);
                    $table->string('event_type', 64);
                    $table->string('client_order_no', 64)->nullable();
                    $table->string('mercury_order_no', 128)->nullable();
                    $table->string('transaction_no', 128)->nullable();
                    $table->char('body_hash', 64);
                    $table->string('status', 32);
                    $table->dateTime('create_time');
                    $table->dateTime('processed_time')->nullable();
                    $table->index(['tenant_id', 'app_id', 'create_time'], 'mercury_event_scope_time');
                    $table->index('client_order_no', 'mercury_event_client_order');
                });
                self::$tableKnown['mercury_webhook_event'] = true;
            } catch (\Throwable) {
                // 同上：Webhook 必须持久化去重，缺表时应失败并让 Mercury 重试。
            }
        }

        if (!is_dir(self::MARK_DIR)) {
            @mkdir(self::MARK_DIR, 0755, true);
        }
        self::tableExists('mercury_order') && @file_put_contents(self::MARK_DIR . '/table_mercury_order', (string)time());
        self::tableExists('mercury_webhook_event') && @file_put_contents(self::MARK_DIR . '/table_mercury_webhook_event', (string)time());
    }

    public static function ensureRedeemPurchase(): void
    {
        self::ensureRedeemCode();
        self::ensureColumn('order', 'fulfillment_mode', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('fulfillment_mode')->default(0)->comment('0=交付账号/卡密，1=交付兑换码');
        });
        self::ensureColumn('order', 'fulfillment_shared_id', static function (Blueprint $table): void {
            $table->unsignedInteger('fulfillment_shared_id')->default(0)->index('order_fulfillment_shared');
        });
    }

    private static function ensureRedeemResultColumns(): void
    {
        self::ensureColumn('redeem_code', 'purchase_order_id', static function (Blueprint $table): void {
            $table->unsignedInteger('purchase_order_id')->nullable()->unique('redeem_purchase_order_unique');
        });
        self::ensureColumn('redeem_code', 'shared_id', static function (Blueprint $table): void {
            $table->unsignedInteger('shared_id')->default(0)->index('redeem_code_shared');
        });
        self::ensureColumn('redeem_code', 'used_quantity', static function (Blueprint $table): void {
            $table->unsignedInteger('used_quantity')->default(0)->after('quantity');
        });
        self::ensureColumn('redeem_code', 'result_trade_no', static function (Blueprint $table): void {
            $table->char('result_trade_no', 19)->nullable()->after('order_id');
        });
        self::ensureColumn('redeem_code', 'result_product_name', static function (Blueprint $table): void {
            $table->string('result_product_name', 255)->nullable()->after('result_trade_no');
        });
        self::ensureColumn('redeem_code', 'result_secret', static function (Blueprint $table): void {
            $table->longText('result_secret')->nullable()->after('result_product_name');
        });
        self::ensureColumn('redeem_code', 'result_leave_message', static function (Blueprint $table): void {
            $table->text('result_leave_message')->nullable()->after('result_secret');
        });

        // 老版本的 status=1 代表整码已经一次性提完。补列后把它折算成已用额度，
        // 防止升级后旧码被误判为还有余额。
        $backfillMark = self::MARK_DIR . '/redeem_code_used_quantity_backfill';
        try {
            if (!is_file($backfillMark)) {
                Manager::table('redeem_code')
                    ->where('status', 1)
                    ->where('used_quantity', 0)
                    ->whereNotNull('result_trade_no')
                    ->update(['used_quantity' => Manager::raw('quantity')]);
                if (!is_dir(self::MARK_DIR)) {
                    @mkdir(self::MARK_DIR, 0755, true);
                }
                @file_put_contents($backfillMark, (string)time());
            }
        } catch (\Throwable) {
        }
    }

    private static function ensureRedeemRecord(): void
    {
        if (self::tableExists('redeem_record')) {
            return;
        }

        try {
            Manager::schema()->create('redeem_record', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('code_id');
                $table->string('request_token', 64);
                $table->unsignedInteger('order_id');
                $table->char('trade_no', 19);
                $table->string('product_name', 255);
                $table->unsignedInteger('quantity');
                $table->longText('secret');
                $table->text('leave_message')->nullable();
                $table->string('used_ip', 64);
                $table->dateTime('create_time');
                $table->unique(['code_id', 'request_token'], 'redeem_record_code_request');
                $table->unique('order_id', 'redeem_record_order');
                $table->unique('trade_no', 'redeem_record_trade_no');
                $table->index(['code_id', 'id'], 'redeem_record_code_id');
                $table->index('create_time', 'redeem_record_create_time');
            });
            self::$tableKnown['redeem_record'] = true;
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents(self::MARK_DIR . '/table_redeem_record', (string)time());
        } catch (\Throwable) {
            // 与兑换码主表一致：没有 CREATE 权限时让实际业务查询给出数据库错误。
        }
    }

    /** @var array<string, bool> 本次请求内已确认过的表 */
    private static array $tableKnown = [];

    /**
     * 表是否存在（带缓存）。
     *
     * 给「新版本引入的整张表」用：老站升级只覆盖文件，工单（3.5.1）、商品分组（3.1.3）
     * 这类表在升级不完整的库里可能整个缺失，业务查询前先问一声，缺了就按零引用降级，
     * 别让整个功能 500（issue #837）。
     *
     * 「存在」永久缓存（表建出来就不会消失）；「不存在」只缓存在请求内，
     * 升级补表后下一个请求立即生效。
     *
     * @param string $table 不带前缀的表名
     * @return bool
     */
    public static function tableExists(string $table): bool
    {
        if (isset(self::$tableKnown[$table])) {
            return self::$tableKnown[$table];
        }

        $mark = self::MARK_DIR . '/table_' . $table;
        if (is_file($mark)) {
            return self::$tableKnown[$table] = true;
        }

        try {
            $exists = Manager::schema()->hasTable($table);
        } catch (\Throwable $e) {
            //探测本身失败（权限等）按存在处理：真正的报错让业务查询自己抛，别在这里吞掉线索
            $exists = true;
        }

        if ($exists) {
            if (!is_dir(self::MARK_DIR)) {
                @mkdir(self::MARK_DIR, 0755, true);
            }
            @file_put_contents($mark, (string)time());
        }

        return self::$tableKnown[$table] = $exists;
    }
}
