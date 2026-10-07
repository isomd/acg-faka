<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Commodity;
use App\Model\Order;
use App\Model\RedeemCode as Code;
use App\Model\Shared;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Exception\JSONException;

/** A paid Dola purchase sells an entitlement; only redemption fetches accounts. */
final class RedeemPurchase
{
    public const DIRECT = 0;
    public const CODE = 1;

    public static function isProduct(Commodity $commodity): bool
    {
        return (int)$commodity->owner === 0 && (int)$commodity->delivery_way === 0
            && (int)($commodity->shared?->type ?? -1) === 3;
    }

    public static function prepare(Commodity $commodity): void
    {
        $config = Ini::toArray((string)$commodity->config);
        if (!self::isProduct($commodity) || !empty($config['category']) || !empty($config['sku'])
            || (int)$commodity->draft_status === 1) {
            throw new JSONException('兑换码售卖仅支持主站单规格 Dola 自动发货商品');
        }
        // Run DDL and secret initialization BEFORE any payment transaction.
        Schema::ensureRedeemPurchase();
        RedeemCode::ensureKey();
    }

    public static function lockPool(int $sharedId): Shared
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Dola 额度锁必须在事务内获取');
        }
        $shared = Shared::query()->whereKey($sharedId)->where('type', 3)->lockForUpdate()->first();
        if (!$shared) throw new JSONException('兑换货源已变更，请联系商家');
        return $shared;
    }

    /** Include locked codes: an administrative lock does not cancel owed goods. */
    public static function reserved(int $sharedId): int
    {
        $grammar = DB::connection()->getQueryGrammar();
        $quantity = $grammar->wrap('codes.quantity');
        $used = $grammar->wrap('codes.used_quantity');
        $codes = DB::table('redeem_code as codes')
            ->leftJoin('commodity as products', 'products.id', '=', 'codes.commodity_id')
            ->whereIn('codes.status', [0, 2])
            ->where(static function ($query) use ($sharedId): void {
                $query->where('codes.shared_id', $sharedId)->orWhere(static function ($legacy) use ($sharedId): void {
                    $legacy->where('codes.shared_id', 0)->where('products.shared_id', $sharedId);
                });
            })
            ->sum(DB::raw("CASE WHEN {$quantity} > {$used} THEN {$quantity} - {$used} ELSE 0 END"));
        // Never silently expire an unpaid reservation: late payment must still
        // be fulfillable. Existing admin cleanup of unpaid orders releases it.
        $pending = DB::table('order')->where('fulfillment_mode', self::CODE)
            ->where('fulfillment_shared_id', $sharedId)->where('delivery_status', 0)
            ->whereIn('status', [0, 1])
            ->whereNotExists(static function ($query): void {
                $query->select(DB::raw('1'))->from('redeem_code')
                    ->whereColumn('redeem_code.purchase_order_id', 'order.id');
            })->sum('card_num');
        return (int)$codes + (int)$pending;
    }

    public static function available(int $physicalStock, int $sharedId): int
    {
        return max(0, $physicalStock - self::reserved($sharedId));
    }

    /** Caller holds the purchase-order lock and an enclosing transaction. */
    public static function issue(Order $order): string
    {
        if ((int)$order->fulfillment_mode !== self::CODE || (int)$order->status !== 1
            || (int)$order->card_num < 1) {
            throw new JSONException('订单尚未支付或兑换额度无效');
        }
        self::lockPool((int)$order->fulfillment_shared_id);
        $commodity = Commodity::query()->with('shared')->whereKey($order->commodity_id)->lockForUpdate()->first();
        if (!$commodity || (int)$commodity->shared_id !== (int)$order->fulfillment_shared_id
            || !self::isProduct($commodity)) {
            throw new JSONException('购买时的兑换商品货源已变更，请联系商家恢复后发货');
        }
        $existing = Code::query()->where('purchase_order_id', $order->id)->first();
        if ($existing) {
            $plain = RedeemCode::normalize((string)$order->secret);
            if (!hash_equals((string)$existing->code_hash, RedeemCode::digest($plain))) {
                throw new JSONException('订单兑换码记录不一致，请联系商家');
            }
            return $plain;
        }
        $plain = RedeemCode::generate('DOLA');
        $code = new Code();
        $code->code_hash = RedeemCode::digest($plain);
        $code->code_mask = RedeemCode::mask($plain);
        $code->commodity_id = $order->commodity_id;
        $code->purchase_order_id = $order->id;
        $code->shared_id = $order->fulfillment_shared_id;
        $code->quantity = $order->card_num;
        $code->used_quantity = 0;
        $code->status = 0;
        $code->batch_no = 'ORDER-' . $order->trade_no;
        $code->note = '购买订单自动发码';
        $code->create_time = Date::current();
        $code->save();
        // Order delivery stays retrievable through the protected order-query
        // path; the redeem table itself retains only an HMAC digest.
        $order->secret = $plain;
        $order->delivery_status = 1;
        $message = "付款已发兑换码，可兑换 {$order->card_num} 个账号。请在首页「兑换码提货」按需分批提取，未用额度会保留。";
        $order->leave_message = trim((string)$order->leave_message . "\n" . $message);
        $order->save();
        return $plain;
    }
}
