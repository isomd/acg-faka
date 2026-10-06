<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Interceptor\Owner;
use App\Model\Commodity;
use App\Model\DolaPickupDelivery;
use App\Model\ManageLog;
use App\Model\RedeemCode as RedeemCodeModel;
use App\Model\Shared;
use App\Service\Query;
use App\Util\Date;
use App\Util\Ini;
use App\Util\RedeemCode as CodeUtil;
use App\Util\Schema;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Redeem extends Manage
{
    #[Inject]
    private Query $query;

    #[Inject]
    private \App\Service\Bind\DolaPickup $dolaPickup;

    private const MAX_BATCH = 1000;
    private const MAX_QUANTITY = 1000;

    public function data(): array
    {
        Schema::ensureRedeemCode();
        $get = new Get(RedeemCodeModel::class);
        $get->setPaginate((int)$this->request->post('page'), (int)$this->request->post('limit'));
        $get->setWhere($_POST);
        $get->setFilterColumns(['id', 'code_mask', 'commodity_id', 'quantity', 'used_quantity', 'status', 'order_id', 'batch_no', 'note', 'create_time', 'used_time', 'used_ip']);
        $data = $this->query->get($get, static function (Builder $builder) {
            return $builder->with([
                'commodity:id,name,cover',
                'order:id,trade_no',
            ]);
        });
        return $this->json(data: $data);
    }

    /** 可绑定兑换码的单规格自动发货商品：本站卡密或 Dola 货源。 */
    public function products(): array
    {
        $rows = Commodity::query()
            ->with('shared:id,type')
            ->where('owner', 0)
            ->where('delivery_way', 0)
            ->orderByDesc('id')
            ->get(['id', 'name', 'config', 'shared_id']);

        $list = [];
        foreach ($rows as $commodity) {
            $config = Ini::toArray((string)$commodity->config);
            if (!empty($config['category']) || !empty($config['sku'])) {
                continue;
            }
            $sharedId = (int)$commodity->shared_id;
            $isLocal = $sharedId === 0;
            $isDola = $sharedId > 0 && (int)($commodity->shared?->type ?? -1) === 3;
            if (!$isLocal && !$isDola) {
                continue;
            }
            $list[] = [
                'id' => (int)$commodity->id,
                'name' => ($isDola ? '[Dola] ' : '[本地] ') . (string)$commodity->name,
            ];
        }
        return $this->json(data: $list);
    }

    public function generate(): array
    {
        Schema::ensureRedeemCode();
        CodeUtil::ensureKey();
        [$commodity, $quantity, $note] = $this->requestConfig();
        $count = (int)($_POST['count'] ?? 0);
        $prefix = strtoupper(trim((string)($_POST['prefix'] ?? 'ACG-R')));
        if ($count < 1 || $count > self::MAX_BATCH) {
            throw new JSONException('每次只能生成 1 到 ' . self::MAX_BATCH . ' 个兑换码');
        }

        $date = Date::current();
        $batchNo = strtoupper(Str::generateRandStr(16));
        $codes = DB::transaction(function () use ($commodity, $quantity, $note, $count, $prefix, $date, $batchNo): array {
            $result = [];
            $attempts = 0;
            while (count($result) < $count && $attempts < $count * 4) {
                $attempts++;
                $code = CodeUtil::generate($prefix);
                if ($this->storeCode($code, (int)$commodity->id, $quantity, $note, $batchNo, $date)) {
                    $result[] = $code;
                }
            }
            if (count($result) !== $count) {
                throw new JSONException('兑换码生成发生冲突，未写入任何数据，请重试');
            }
            return $result;
        });

        // The plaintext only exists in this response. An audit-table failure
        // must not turn a successful batch into an unrecoverable lost batch.
        try {
            ManageLog::log($this->getManage(), "[生成兑换码]商品ID:{$commodity->id}，数量:" . count($codes) . "，批次:{$batchNo}");
        } catch (\Throwable) {
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        return $this->json(200, '兑换码生成成功，请立即复制或下载', [
            'success' => count($codes),
            'batch_no' => $batchNo,
            'codes' => implode(PHP_EOL, $codes),
        ]);
    }

    public function import(): array
    {
        Schema::ensureRedeemCode();
        CodeUtil::ensureKey();
        [$commodity, $quantity, $note] = $this->requestConfig();
        $raw = $_POST['codes'] ?? '';
        if (!is_scalar($raw)) {
            throw new JSONException('兑换码列表格式不正确');
        }
        $lines = preg_split('/[\r\n,，;；]+/u', (string)$raw) ?: [];
        $codes = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $normalized = CodeUtil::normalize($line);
            $codes[$normalized] = $normalized;
        }
        $codes = array_values($codes);
        if ($codes === [] || count($codes) > self::MAX_BATCH) {
            throw new JSONException('每次请导入 1 到 ' . self::MAX_BATCH . ' 个兑换码');
        }

        $date = Date::current();
        $batchNo = strtoupper(Str::generateRandStr(16));
        $success = 0;
        $duplicate = 0;
        DB::transaction(function () use ($codes, $commodity, $quantity, $note, $batchNo, $date, &$success, &$duplicate): void {
            foreach ($codes as $code) {
                if ($this->storeCode($code, (int)$commodity->id, $quantity, $note, $batchNo, $date)) {
                    $success++;
                } else {
                    $duplicate++;
                }
            }
        });

        ManageLog::log($this->getManage(), "[导入兑换码]商品ID:{$commodity->id}，成功:{$success}，重复:{$duplicate}，批次:{$batchNo}");
        return $this->json(200, "导入完成，成功:{$success}个，重复:{$duplicate}个", [
            'success' => $success,
            'duplicate' => $duplicate,
            'batch_no' => $batchNo,
        ]);
    }

    public function lock(): array
    {
        $ids = $this->ids($_POST['list'] ?? []);
        $count = RedeemCodeModel::query()->whereIn('id', $ids)->where('status', 0)->update(['status' => 2]);
        ManageLog::log($this->getManage(), "[锁定兑换码]共计:{$count}");
        return $this->json(200, '锁定完成', ['count' => $count]);
    }

    public function unlock(): array
    {
        $ids = $this->ids($_POST['list'] ?? []);
        $available = RedeemCodeModel::query()->whereIn('id', $ids)->where('status', 2)
            ->whereColumn('used_quantity', '<', 'quantity')->update(['status' => 0]);
        $exhausted = RedeemCodeModel::query()->whereIn('id', $ids)->where('status', 2)
            ->whereColumn('used_quantity', '>=', 'quantity')->update(['status' => 1]);
        $count = $available + $exhausted;
        ManageLog::log($this->getManage(), "[解锁兑换码]共计:{$count}");
        return $this->json(200, '解锁完成', ['count' => $count]);
    }

    public function del(): array
    {
        $ids = $this->ids($_POST['list'] ?? []);
        $count = DB::transaction(static function () use ($ids): int {
            $selected = RedeemCodeModel::query()->whereIn('id', $ids)->lockForUpdate()->get(['id', 'status', 'used_quantity', 'order_id']);
            if ($selected->count() !== count($ids)) {
                throw new JSONException('部分兑换码不存在，请刷新后重试');
            }
            if ($selected->contains(static fn($row): bool => (int)$row->status === 1 || (int)$row->used_quantity > 0 || (int)$row->order_id > 0)) {
                throw new JSONException('已产生提货记录的兑换码不能删除');
            }
            return RedeemCodeModel::query()->whereIn('id', $ids)->whereIn('status', [0, 2])->whereNull('order_id')->delete();
        });
        ManageLog::log($this->getManage(), "[删除兑换码]共计:{$count}");
        return $this->json(200, '删除完成', ['count' => $count]);
    }

    /**
     * Dola 上游 KEY 实时状态。响应只含掩码和 SHA-256，不返回原始 KEY。
     */
    #[Interceptor(Owner::class, Interceptor::TYPE_API)]
    public function dolaSources(): array
    {
        Schema::ensureDolaPickup();
        $stores = Shared::query()
            ->where('type', 3)
            ->orderBy('id')
            ->get(['id', 'name', 'domain', 'dola_keys']);
        $rows = [];
        foreach ($stores as $store) {
            try {
                $keys = $this->dolaPickup->parseKeys((string)$store->dola_keys);
            } catch (\Throwable $e) {
                $rows[] = [
                    'id' => (string)$store->id . ':invalid',
                    'shared_id' => (int)$store->id,
                    'store_name' => (string)$store->name,
                    'domain' => (string)$store->domain,
                    'key_mask' => '配置异常',
                    'source_hash' => '',
                    'online' => false,
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ];
                continue;
            }
            foreach ($keys as $key) {
                $hash = hash('sha256', $key);
                $row = [
                    'id' => (string)$store->id . ':' . $hash,
                    'shared_id' => (int)$store->id,
                    'store_name' => (string)$store->name,
                    'domain' => (string)$store->domain,
                    'key_mask' => $this->maskDolaKey($key),
                    'source_hash' => $hash,
                    'online' => false,
                    'total' => null,
                    'taken' => null,
                    'remaining' => null,
                    'delivered_times' => null,
                    'source_name' => '',
                    'error' => null,
                ];
                try {
                    $status = $this->dolaPickup->status((string)$store->domain, $key);
                    $row = array_merge($row, [
                        'online' => true,
                        'total' => $status['total'],
                        'taken' => $status['taken'],
                        'remaining' => $status['remaining'],
                        'delivered_times' => $status['delivered_times'],
                        'source_name' => $status['name'],
                    ]);
                } catch (\Throwable $e) {
                    $row['error'] = mb_substr($e->getMessage(), 0, 160);
                }
                $rows[] = $row;
            }
        }
        return $this->json(data: ['list' => $rows, 'total' => count($rows)]);
    }

    /** 新增一个或多个 Dola 上游 KEY；完整链接也可直接粘贴。 */
    #[Interceptor(Owner::class, Interceptor::TYPE_API)]
    public function dolaAdd(): array
    {
        Schema::ensureDolaPickup();
        $sharedId = $this->positiveId($_POST['shared_id'] ?? null, 'Dola 货源');
        $raw = $_POST['keys'] ?? '';
        if (!is_scalar($raw)) {
            throw new JSONException('Dola 提货 KEY 格式不正确');
        }
        $store = Shared::query()->whereKey($sharedId)->where('type', 3)->first();
        if (!$store) {
            throw new JSONException('Dola 货源不存在，请先在店铺共享中创建');
        }
        $before = $this->dolaPickup->normalizeKeys((string)$store->dola_keys);
        $incoming = $this->dolaPickup->parseKeys((string)$raw);
        $merged = $this->dolaPickup->normalizeKeys($before . "\n" . implode("\n", $incoming));

        //先以只读查询验证所有 KEY，再进本地短事务；此处绝不携带 n，不会提货。
        $connection = $this->dolaPickup->connect((string)$store->domain, $merged);
        DB::transaction(function () use ($sharedId, $before, $merged, $connection): void {
            $locked = Shared::query()->whereKey($sharedId)->where('type', 3)->lockForUpdate()->first();
            if (!$locked || $this->dolaPickup->normalizeKeys((string)$locked->dola_keys) !== $before) {
                throw new JSONException('Dola KEY 配置刚刚发生变化，请刷新后重试');
            }
            $locked->dola_keys = $merged;
            $locked->balance = (float)$connection['balance'];
            $locked->save();
        });
        ManageLog::log($this->getManage(), "[Dola上游KEY]新增到货源ID:{$sharedId}，数量:" . count($incoming));
        return $this->json(200, 'Dola 上游 KEY 已添加并验证', ['count' => count($incoming)]);
    }

    /** 移除没有待恢复批次依赖的 Dola KEY。 */
    #[Interceptor(Owner::class, Interceptor::TYPE_API)]
    public function dolaRemove(): array
    {
        Schema::ensureDolaPickup();
        $sharedId = $this->positiveId($_POST['shared_id'] ?? null, 'Dola 货源');
        $hash = strtolower(trim((string)($_POST['source_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new JSONException('Dola KEY 标识不正确');
        }
        $remainingKeys = $this->dolaPickup->withStoreLock($sharedId, function () use ($sharedId, $hash): string {
            return DB::transaction(function () use ($sharedId, $hash): string {
                $store = Shared::query()->whereKey($sharedId)->where('type', 3)->lockForUpdate()->first();
                if (!$store) {
                    throw new JSONException('Dola 货源不存在');
                }
                if (DolaPickupDelivery::query()
                    ->where('shared_id', $sharedId)
                    ->where('source_hash', $hash)
                    ->where('status', 0)
                    ->exists()) {
                    throw new JSONException('该 KEY 仍有待恢复的提货批次，暂时不能移除');
                }
                $keys = $this->dolaPickup->parseKeys((string)$store->dola_keys);
                $kept = [];
                $found = false;
                foreach ($keys as $key) {
                    if (hash_equals($hash, hash('sha256', $key))) {
                        $found = true;
                        continue;
                    }
                    $kept[] = $key;
                }
                if (!$found) {
                    throw new JSONException('Dola KEY 已不存在，请刷新列表');
                }
                if ($kept === []) {
                    throw new JSONException('每个 Dola 货源至少保留一个 KEY；如不再使用请删除整个共享店铺');
                }
                $normalized = $this->dolaPickup->normalizeKeys(implode("\n", $kept));
                $store->dola_keys = $normalized;
                $store->save();
                return $normalized;
            });
        });

        //移除已经落库后再刷新缓存余额；上游临时不可用不能让已确认的本地删除“假回滚”。
        try {
            $store = Shared::query()->find($sharedId);
            if ($store) {
                $connection = $this->dolaPickup->connect((string)$store->domain, $remainingKeys);
                $store->balance = (float)$connection['balance'];
                $store->save();
            }
        } catch (\Throwable) {
        }
        ManageLog::log($this->getManage(), "[Dola上游KEY]从货源ID:{$sharedId}移除:" . substr($hash, 0, 12));
        return $this->json(200, 'Dola 上游 KEY 已移除');
    }

    /** Dola 提货账本列表，不包含账号明文。 */
    #[Interceptor(Owner::class, Interceptor::TYPE_API)]
    public function dolaDeliveries(): array
    {
        Schema::ensureDolaPickup();
        $get = new Get(DolaPickupDelivery::class);
        $get->setPaginate(max(1, (int)$this->request->post('page')), max(10, min(100, (int)$this->request->post('limit') ?: 15)));
        $get->setWhere($_POST);
        $get->setFilterColumns([
            'id', 'shared_id', 'request_no', 'sequence', 'order_quantity', 'requested',
            'status', 'error', 'create_time', 'update_time',
        ]);
        $data = $this->query->get($get, static function (Builder $builder) {
            return $builder->with([
                'shared:id,name,domain',
                'orderByRequest:id,request_no,trade_no,status,delivery_status',
                'orderByTradeNo:id,trade_no,status,delivery_status',
            ]);
        });
        return $this->json(data: $data);
    }

    /** 单条读取已交付批次；这是唯一会把 Dola 账号内容返回给管理员的接口。 */
    #[Interceptor(Owner::class, Interceptor::TYPE_API)]
    public function dolaDeliveryDetail(): array
    {
        Schema::ensureDolaPickup();
        $id = $this->positiveId($_POST['id'] ?? null, '提货记录');
        $delivery = DolaPickupDelivery::query()
            ->with([
                'shared:id,name,domain',
                'orderByRequest:id,request_no,trade_no,status,delivery_status',
                'orderByTradeNo:id,trade_no,status,delivery_status',
            ])
            ->find($id);
        if (!$delivery) {
            throw new JSONException('Dola 提货记录不存在');
        }
        if ((int)$delivery->status !== 1) {
            throw new JSONException('该批次尚未成功恢复，暂无可查看的交付内容');
        }
        $payload = json_decode((string)$delivery->payload, true);
        if (!is_array($payload) || count($payload) !== (int)$delivery->requested) {
            throw new JSONException('Dola 本地提货记录损坏，请核对上游历史');
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $order = $delivery->orderByRequest ?: $delivery->orderByTradeNo;
        return $this->json(data: [
            'id' => (int)$delivery->id,
            'request_no' => (string)$delivery->request_no,
            'sequence' => (int)$delivery->sequence,
            'requested' => (int)$delivery->requested,
            'secret' => implode(PHP_EOL, array_map('strval', $payload)),
            'store_name' => (string)($delivery->shared?->name ?? ''),
            'trade_no' => (string)($order?->trade_no ?? ''),
            'create_time' => (string)$delivery->create_time,
        ]);
    }

    private function positiveId(mixed $value, string $name): int
    {
        if (!is_scalar($value) || !ctype_digit(trim((string)$value)) || (int)$value < 1) {
            throw new JSONException("{$name} ID 必须是正整数");
        }
        return (int)$value;
    }

    private function maskDolaKey(string $key): string
    {
        $length = strlen($key);
        if ($length <= 10) {
            return substr($key, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($key, -2);
        }
        return substr($key, 0, 5) . str_repeat('*', min(14, $length - 9)) . substr($key, -4);
    }

    /** @return array{0:Commodity,1:int,2:string} */
    private function requestConfig(): array
    {
        $commodityId = (int)($_POST['commodity_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new JSONException('每个兑换码的发货数量需为 1 到 ' . self::MAX_QUANTITY);
        }
        if (mb_strlen($note) > 64) {
            throw new JSONException('备注最多 64 个字符');
        }
        $commodity = Commodity::query()
            ->with('shared:id,type')
            ->where('id', $commodityId)
            ->where('owner', 0)
            ->where('delivery_way', 0)
            ->first();
        $sharedId = (int)($commodity?->shared_id ?? 0);
        $isLocal = $commodity && $sharedId === 0;
        $isDola = $commodity && $sharedId > 0 && (int)($commodity->shared?->type ?? -1) === 3;
        if (!$isLocal && !$isDola) {
            throw new JSONException('只能选择主站的本地卡密或 Dola 自动发货商品');
        }
        $config = Ini::toArray((string)$commodity->config);
        if (!empty($config['category']) || !empty($config['sku'])) {
            throw new JSONException('兑换码提货暂不支持带商品种类或 SKU 的多规格商品');
        }
        return [$commodity, $quantity, $note];
    }

    private function storeCode(string $code, int $commodityId, int $quantity, string $note, string $batchNo, string $date): bool
    {
        $normalized = CodeUtil::normalize($code);
        return RedeemCodeModel::query()->insertOrIgnore([
            'code_hash' => CodeUtil::digest($normalized),
            'code_mask' => CodeUtil::mask($normalized),
            'commodity_id' => $commodityId,
            'quantity' => $quantity,
            'used_quantity' => 0,
            'status' => 0,
            'batch_no' => $batchNo,
            'note' => $note === '' ? null : $note,
            'create_time' => $date,
        ]) === 1;
    }

    /** @return int[] */
    private function ids(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $ids = [];
        foreach ($value as $candidate) {
            if (!is_scalar($candidate) || !ctype_digit(trim((string)$candidate)) || (int)$candidate < 1) {
                throw new JSONException('兑换码 ID 必须是正整数');
            }
            $ids[] = (int)$candidate;
        }
        $ids = array_values(array_unique($ids));
        if ($ids === [] || count($ids) > 500) {
            throw new JSONException('每次请选择 1 到 500 个兑换码');
        }
        return $ids;
    }
}
