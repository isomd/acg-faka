<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Card;
use App\Model\Commodity;
use App\Model\DolaPickupDelivery;
use App\Model\Order as OrderModel;
use App\Model\RedeemCode as RedeemCodeModel;
use App\Model\RedeemRecord;
use App\Service\Order;
use App\Service\Shop;
use App\Util\Ini;
use App\Util\Client;
use App\Util\Date;
use App\Util\RedeemCode as CodeUtil;
use App\Util\Schema;
use App\Util\Throttle;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([Waf::class, UserVisitor::class])]
class Redeem extends User
{
    #[Inject]
    private Order $order;

    #[Inject]
    private Shop $shop;

    /** 查询兑换码额度，不扣库存。 */
    public function check(Request $request): array
    {
        Schema::ensureRedeemCode();
        $code = $this->findCode($request, 'redeem-check', 40);
        if ((int)$code->status === 2) {
            throw new JSONException('兑换码已锁定，请联系客服');
        }

        $this->noCache();
        return $this->json(data: $this->codeSummary($code));
    }

    /** 按用户指定数量提货，同一 request_token 重试不会重复扣库存。 */
    public function submit(Request $request): array
    {
        Schema::ensureRedeemCode();
        $post = $request->post(flags: Filter::NORMAL);
        $normalized = CodeUtil::normalize($post['code'] ?? '');
        $quantity = (int)($post['quantity'] ?? 0);
        $requestToken = trim((string)($post['request_token'] ?? ''));
        if ($quantity < 1 || $quantity > 1000) {
            throw new JSONException('单次提取数量需为 1 到 1000');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $requestToken) !== 1) {
            throw new JSONException('请求标识无效，请刷新页面后重试');
        }

        $ip = Client::getAddress();
        if (Throttle::tooMany('redeem:ip:' . $ip, 30, 600)) {
            throw new JSONException('尝试次数过多，请十分钟后再试');
        }

        // 没有任何兑换码时，不应把后台密钥初始化状态暴露给前台用户。
        // 只有库里确实存在兑换码，才继续读取密钥并检查配置完整性。
        if (!RedeemCodeModel::query()->exists()) {
            throw new JSONException('兑换码无效或不可用');
        }

        $digest = CodeUtil::digest($normalized);
        $userId = (int)($this->getUser()?->id ?? 0);
        $result = DB::transaction(function () use ($digest, $ip, $userId, $quantity, $requestToken): array {
            /** @var RedeemCodeModel|null $code */
            $code = RedeemCodeModel::query()->where('code_hash', $digest)->lockForUpdate()->first();
            if (!$code) {
                throw new JSONException('兑换码无效或不可用');
            }

            /** @var RedeemRecord|null $previous */
            $previous = RedeemRecord::query()
                ->where('code_id', (int)$code->id)
                ->where('request_token', $requestToken)
                ->first();
            if ($previous) {
                return $this->resultFromRecord($previous, $code, true);
            }

            // 兼容升级前已经一次性兑换完的码：继续返回旧快照，不能重新放出余额。
            if ((int)$code->status === 1
                && $code->result_trade_no !== null
                && $code->result_secret !== null
                && !RedeemRecord::query()->where('code_id', (int)$code->id)->exists()) {
                return $this->resultFromLegacy($code);
            }
            if ((int)$code->status === 2) {
                throw new JSONException('兑换码已锁定，请联系客服');
            }

            $total = (int)$code->quantity;
            $used = min($total, max(0, (int)$code->used_quantity));
            $remaining = max(0, $total - $used);
            if ((int)$code->status === 1 || $remaining === 0) {
                throw new JSONException('该兑换码额度已全部提取，可在提货记录中查看历史结果');
            }
            if ($quantity > $remaining) {
                throw new JSONException("本次最多还能提取 {$remaining} 个");
            }

            /** @var Commodity|null $commodity */
            $commodity = Commodity::query()->with('shared')->whereKey((int)$code->commodity_id)->lockForUpdate()->first();
            if (!$commodity) {
                throw new JSONException('兑换商品不存在，请联系客服');
            }
            $sharedId = (int)$commodity->shared_id;
            $isLocal = $sharedId === 0;
            $isDola = $sharedId > 0 && (int)($commodity->shared?->type ?? -1) === 3;
            if ((int)$commodity->owner !== 0 || (int)$commodity->delivery_way !== 0 || (!$isLocal && !$isDola)) {
                throw new JSONException('该商品不支持兑换码自动提货，请联系客服');
            }
            $config = Ini::toArray((string)$commodity->config);
            if (!empty($config['category']) || !empty($config['sku'])) {
                throw new JSONException('兑换商品已改为多规格，暂时无法自动提货，请联系客服');
            }

            // 19 字符限制下保留 108 bit 摘要。无论商品当前是否仍为 Dola 都先计算，
            // 这样管理员在一次失败后改了商品货源，也不会让重试绕过既有上游账本再次发货。
            $requestNo = 'r' . substr(
                rtrim(strtr(base64_encode(hash('sha256', (string)$code->id . "\0" . $requestToken, true)), '+/', '-_'), '='),
                0,
                18
            );
            if (Schema::tableExists('dola_pickup_delivery')) {
                $priorDelivery = DolaPickupDelivery::query()
                    ->where('request_no', $requestNo)
                    ->first(['shared_id', 'order_quantity']);
                if ($priorDelivery && (!$isDola || (int)$priorDelivery->shared_id !== $sharedId)) {
                    throw new JSONException('该次提货已有 Dola 上游批次，但商品货源已变化，请联系管理员恢复原货源后重试');
                }
                if ($priorDelivery && (int)$priorDelivery->order_quantity !== $quantity) {
                    throw new JSONException('同一请求标识的提取数量不能变化，请恢复原数量后重试');
                }
            }

            // giftOrder() 不带规格。本地卡密按空 race 统计；Dola 必须回源读取所有 KEY 的实时 remaining。
            $stock = $isDola
                ? (int)$this->shop->getItemStock($commodity, null, [])
                : Card::query()
                    ->where('commodity_id', $commodity->id)
                    ->where('status', 0)
                    ->where(static function ($builder): void {
                        $builder->whereNull('race')->orWhere('race', '');
                    })
                    ->count();
            if ($stock < $quantity) {
                throw new JSONException('商品库存不足，兑换码额度未扣除，请稍后再试或联系客服');
            }

            // 数据库事务回滚后，同一个 request_token 仍命中 Dola 的独立提货账本。
            $gift = $this->order->giftOrder($commodity, '', $quantity, '', '', null, $userId, '[]', $isDola ? $requestNo : '');
            $order = OrderModel::query()->with('commodity')->where('trade_no', (string)$gift['tradeNo'])->first();
            if (!$order) {
                throw new JSONException('兑换订单创建失败，兑换码额度未扣除');
            }
            $delivered = $isDola
                ? count($this->secretItems((string)$order->secret))
                : Card::query()->where('order_id', $order->id)->where('status', 1)->count();
            if ((int)$order->delivery_status !== 1 || $delivered !== $quantity) {
                throw new JSONException('商品未完整发货或发货被拦截，兑换码额度未扣除');
            }

            $date = Date::current();
            $leaveMessage = OrderModel::resolveLeaveMessage($order->leave_message, null);
            $record = new RedeemRecord();
            $record->code_id = $code->id;
            $record->request_token = $requestToken;
            $record->order_id = $order->id;
            $record->trade_no = (string)$order->trade_no;
            $record->product_name = (string)($order->commodity?->name ?? '兑换商品');
            $record->quantity = $quantity;
            $record->secret = (string)$order->secret;
            $record->leave_message = $leaveMessage;
            $record->used_ip = $ip;
            $record->create_time = $date;
            $record->save();

            $newUsed = $used + $quantity;
            $code->used_quantity = $newUsed;
            $code->status = $newUsed >= $total ? 1 : 0;
            $code->order_id = $order->id;
            $code->used_time = $date;
            $code->used_ip = $ip;
            $code->result_trade_no = (string)$order->trade_no;
            $code->result_product_name = (string)$record->product_name;
            $code->result_secret = (string)$record->secret;
            $code->result_leave_message = $leaveMessage;
            $code->save();

            return $this->resultFromRecord($record, $code, false);
        });

        $this->noCache();
        return $this->json(200, $result['repeated'] ? '已返回该次请求原先的提货结果' : '提取成功', $result);
    }

    /** 凭兑换码查看最近 20 次提货快照。 */
    public function history(Request $request): array
    {
        Schema::ensureRedeemCode();
        $code = $this->findCode($request, 'redeem-history', 30);
        $records = RedeemRecord::query()
            ->where('code_id', (int)$code->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $list = [];
        foreach ($records as $record) {
            $list[] = $this->resultFromRecord($record, $code, true);
        }
        if ($list === [] && $code->result_trade_no !== null && $code->result_secret !== null) {
            $list[] = $this->resultFromLegacy($code);
        }

        $this->noCache();
        return $this->json(data: [
            'summary' => $this->codeSummary($code),
            'list' => $list,
        ]);
    }

    private function findCode(Request $request, string $throttlePrefix, int $limit): RedeemCodeModel
    {
        $post = $request->post(flags: Filter::NORMAL);
        $normalized = CodeUtil::normalize($post['code'] ?? '');
        $ip = Client::getAddress();
        if (Throttle::tooMany($throttlePrefix . ':ip:' . $ip, $limit, 600)) {
            throw new JSONException('查询次数过多，请十分钟后再试');
        }
        if (!RedeemCodeModel::query()->exists()) {
            throw new JSONException('兑换码无效或不可用');
        }
        $code = RedeemCodeModel::query()->with('commodity')->where('code_hash', CodeUtil::digest($normalized))->first();
        if (!$code) {
            throw new JSONException('兑换码无效或不可用');
        }
        return $code;
    }

    private function codeSummary(RedeemCodeModel $code): array
    {
        $total = max(0, (int)$code->quantity);
        $used = min($total, max(0, (int)$code->used_quantity));
        if ((int)$code->status === 1 && $used === 0 && $code->result_trade_no !== null) {
            $used = $total;
        }
        return [
            'productName' => (string)($code->commodity?->name ?? $code->result_product_name ?? '兑换商品'),
            'cover' => (string)($code->commodity?->cover ?? ''),
            'totalQuantity' => $total,
            'usedQuantity' => $used,
            'remainingQuantity' => max(0, $total - $used),
            'locked' => (int)$code->status === 2,
            'exhausted' => (int)$code->status === 1 || $used >= $total,
        ];
    }

    private function resultFromRecord(RedeemRecord $record, RedeemCodeModel $code, bool $repeated): array
    {
        $summary = $this->codeSummary($code);
        $secret = (string)$record->secret;
        return array_merge($summary, [
            'id' => (int)$record->id,
            'tradeNo' => (string)$record->trade_no,
            'productName' => (string)$record->product_name,
            'quantity' => (int)$record->quantity,
            'secret' => $secret,
            'items' => $this->secretItems($secret),
            'leaveMessage' => $record->leave_message,
            'createTime' => (string)$record->create_time,
            'repeated' => $repeated,
        ]);
    }

    private function resultFromLegacy(RedeemCodeModel $code): array
    {
        $secret = (string)$code->result_secret;
        return array_merge($this->codeSummary($code), [
            'id' => 0,
            'tradeNo' => (string)$code->result_trade_no,
            'productName' => (string)($code->result_product_name ?: '兑换商品'),
            'quantity' => (int)$code->quantity,
            'secret' => $secret,
            'items' => $this->secretItems($secret),
            'leaveMessage' => $code->result_leave_message,
            'createTime' => (string)($code->used_time ?? ''),
            'repeated' => true,
        ]);
    }

    /** @return string[] */
    private function secretItems(string $secret): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', trim($secret)) ?: []), static fn(string $line): bool => $line !== ''));
    }

    private function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}
