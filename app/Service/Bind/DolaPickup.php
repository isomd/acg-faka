<?php
declare(strict_types=1);

namespace App\Service\Bind;

use App\Model\Shared;
use App\Util\Date;
use App\Util\Http;
use Kernel\Exception\JSONException;
use PDO;

/**
 * ops.czai2.ccwu.cc/pickup 兼容客户端。
 *
 * 上游以 GET + key 工作：不传 n 是只读查询，传 n 才提货。所有请求都显式带 api=1，
 * 避免 Accept/User-Agent 变化时拿到 HTML 页面。
 */
final class DolaPickup
{
    private const ITEM_CODE = 'dola-account';
    //库存查询必须逐个向上游核验；限制数量，避免一次前台询价被异常配置拖成数百个外部请求。
    private const MAX_KEYS = 50;
    private const MAX_BATCH = 200;

    /** 独立自提交连接：提货账本不能跟随外层订单事务一起回滚。 */
    private ?PDO $ledger = null;
    private ?string $ledgerTable = null;

    /** @return string[] */
    public function parseKeys(string $value): array
    {
        $parts = preg_split('/[\s,;，；]+/u', trim($value)) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $candidate = trim((string)$part);
            if ($candidate === '') {
                continue;
            }
            if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                $query = [];
                parse_str((string)parse_url($candidate, PHP_URL_QUERY), $query);
                $candidate = trim((string)($query['key'] ?? ''));
            }
            if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $candidate)) {
                throw new JSONException('Dola 提货 KEY 格式不正确，请每行填写一个 KEY 或完整提货链接');
            }
            $keys[$candidate] = $candidate;
            if (count($keys) > self::MAX_KEYS) {
                throw new JSONException('一个 Dola 货源最多配置 ' . self::MAX_KEYS . ' 个提货 KEY');
            }
        }
        if ($keys === []) {
            throw new JSONException('请至少填写一个 Dola 提货 KEY');
        }
        return array_values($keys);
    }

    public function normalizeKeys(string $value): string
    {
        return implode("\n", $this->parseKeys($value));
    }

    private function endpoint(string $domain): string
    {
        $domain = rtrim($domain, '/');
        return str_ends_with(strtolower($domain), '/pickup') ? $domain : $domain . '/pickup';
    }

    /**
     * @throws JSONException
     */
    private function request(string $domain, string $key, array $query = [], ?string $userAgent = null): array
    {
        $query = array_merge(['api' => '1', 'key' => $key, 'format' => 'json'], $query);
        try {
            $response = Http::make(['verify' => true])->get($this->endpoint($domain), [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => $userAgent ?: 'acg-faka-dola/1.0',
                ],
                'connect_timeout' => 8,
                'timeout' => 45,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new JSONException('Dola 上游连接失败，请稍后重试');
        }

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new JSONException('Dola 上游返回了无法识别的数据');
        }
        if (($data['ok'] ?? false) !== true) {
            $message = trim(strip_tags((string)($data['error'] ?? '')));
            throw new JSONException($message !== '' ? 'Dola 提货失败：' . mb_substr($message, 0, 120) : 'Dola 提货失败');
        }
        return $data;
    }

    /** @return array{total:int,taken:int,remaining:int,done:bool,delivered_times:int,history:array,name:string} */
    public function status(string $domain, string $key): array
    {
        $data = $this->request($domain, $key);
        foreach (['total', 'taken', 'remaining'] as $field) {
            if (!isset($data[$field]) || !is_numeric($data[$field]) || (int)$data[$field] < 0) {
                throw new JSONException("Dola 上游缺少有效的 {$field} 字段");
            }
        }
        return [
            'total' => (int)$data['total'],
            'taken' => (int)$data['taken'],
            'remaining' => (int)$data['remaining'],
            'done' => (bool)($data['done'] ?? false),
            'delivered_times' => max(0, (int)($data['delivered_times'] ?? 0)),
            'history' => is_array($data['history'] ?? null) ? $data['history'] : [],
            'name' => trim(strip_tags((string)($data['name'] ?? 'Dola 账号'))),
        ];
    }

    /** @return array{total:int,taken:int,remaining:int,name:string,sources:array<int,array>} */
    public function aggregate(string $domain, string $credentials): array
    {
        $result = ['total' => 0, 'taken' => 0, 'remaining' => 0, 'name' => 'Dola 账号', 'sources' => []];
        foreach ($this->parseKeys($credentials) as $key) {
            $status = $this->status($domain, $key);
            $result['total'] += $status['total'];
            $result['taken'] += $status['taken'];
            $result['remaining'] += $status['remaining'];
            $result['name'] = $status['name'] !== '' ? $status['name'] : $result['name'];
            $result['sources'][] = ['key' => $key] + $status;
        }
        return $result;
    }

    public function connect(string $domain, string $credentials): array
    {
        $aggregate = $this->aggregate($domain, $credentials);
        return [
            'shopName' => 'Dola 提货 · ' . $aggregate['name'] . '（' . count($aggregate['sources']) . ' 个 KEY）',
            'balance' => $aggregate['remaining'],
        ];
    }

    public function item(string $domain, string $credentials): array
    {
        $aggregate = $this->aggregate($domain, $credentials);
        return [
            'id' => 1,
            'code' => self::ITEM_CODE,
            'name' => 'Dola账号',
            'description' => 'Dola 账号自动提货商品。付款后按购买数量从上游 KEY 库自动提取并发货。',
            'cover' => '',
            'price' => '0.95',
            'user_price' => '0.95',
            'factory_price' => '0',
            'delivery_way' => 0,
            'contact_type' => 0,
            'password_status' => 0,
            'seckill_status' => 0,
            'seckill_start_time' => '',
            'seckill_end_time' => '',
            'draft_status' => 0,
            'draft_premium' => '0',
            'inventory_hidden' => 0,
            'only_user' => 0,
            'purchase_count' => 0,
            'minimum' => 1,
            'maximum' => 10000,
            'stock' => $aggregate['remaining'],
            'widget' => '[]',
            'config' => [
                'wholesale' => [
                    100 => '0.80',
                    150 => '0.75',
                    1600 => '0.70',
                    3750 => '0.65',
                ],
            ],
        ];
    }

    public function items(string $domain, string $credentials): array
    {
        return [[
            'id' => 0,
            'name' => 'Dola 账号',
            'children' => [$this->item($domain, $credentials)],
        ]];
    }

    /** @return string[] */
    private function lines(array $items, int $expected): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new JSONException('Dola 上游返回的账号记录格式不正确');
            }
            $email = trim((string)($item['email'] ?? ''));
            $password = trim((string)($item['password'] ?? ''));
            $cookie = trim((string)($item['cookie'] ?? ''));
            if ($email === '' && $password === '' && $cookie === '') {
                throw new JSONException('Dola 上游返回了空账号记录');
            }
            $lines[] = $email . '----' . $password . '----' . $cookie;
        }
        if (count($lines) !== $expected) {
            throw new JSONException("Dola 上游应返回 {$expected} 个账号，实际返回 " . count($lines) . ' 个');
        }
        return $lines;
    }

    /** @return string[] */
    private function take(string $domain, string $key, int $quantity, string $requestTag): array
    {
        $data = $this->request($domain, $key, ['n' => (string)$quantity], $requestTag);
        if ((int)($data['count'] ?? -1) !== $quantity || !is_array($data['items'] ?? null)) {
            throw new JSONException('Dola 上游提货数量与请求不一致');
        }
        return $this->lines($data['items'], $quantity);
    }

    /** @return string[] */
    private function historyBatch(string $domain, string $key, int $index, int $expected): array
    {
        $data = $this->request($domain, $key, ['history' => '1', 'batch' => (string)$index]);
        $batch = $data['batch'] ?? null;
        if (!is_array($batch) || !is_array($batch['accounts'] ?? null) || (int)($batch['n'] ?? count($batch['accounts'])) !== $expected) {
            throw new JSONException('Dola 历史提货批次无法校验');
        }
        return $this->lines($batch['accounts'], $expected);
    }

    private function ledger(): PDO
    {
        if ($this->ledger instanceof PDO) {
            return $this->ledger;
        }
        $config = (array)config('database');
        if (($config['driver'] ?? '') !== 'mysql') {
            throw new JSONException('Dola 提货账本目前只支持 MySQL');
        }
        $host = (string)($config['host'] ?? '127.0.0.1');
        $database = (string)($config['database'] ?? '');
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $port = isset($config['port']) && $config['port'] !== '' ? ';port=' . (int)$config['port'] : '';
        $socket = !empty($config['unix_socket']) ? ';unix_socket=' . (string)$config['unix_socket'] : '';
        $options = (array)($config['options'] ?? []);
        $options[PDO::ATTR_PERSISTENT] = false;
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
        try {
            $this->ledger = new PDO(
                "mysql:host={$host};dbname={$database};charset={$charset}{$port}{$socket}",
                (string)($config['username'] ?? ''),
                (string)($config['password'] ?? ''),
                $options
            );
        } catch (\Throwable $e) {
            throw new JSONException('Dola 提货账本数据库连接失败');
        }
        return $this->ledger;
    }

    private function ledgerTable(): string
    {
        if ($this->ledgerTable !== null) {
            return $this->ledgerTable;
        }
        $config = (array)config('database');
        $prefix = (string)($config['prefix'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            throw new JSONException('数据库表前缀不合法，无法启用 Dola 提货账本');
        }
        return $this->ledgerTable = '`' . $prefix . 'dola_pickup_delivery`';
    }

    /** @return array<int,array<string,mixed>> */
    private function deliveryRows(int $sharedId, string $requestNo): array
    {
        $statement = $this->ledger()->prepare(
            'SELECT * FROM ' . $this->ledgerTable() . ' WHERE shared_id = ? AND request_no = ? ORDER BY sequence ASC'
        );
        $statement->execute([$sharedId, $requestNo]);
        return $statement->fetchAll() ?: [];
    }

    /** @return array<string,mixed> */
    private function insertDelivery(array $delivery): array
    {
        $fields = [
            'shared_id', 'request_no', 'sequence', 'order_quantity', 'source_hash', 'request_tag', 'requested',
            'before_remaining', 'before_times', 'status', 'payload', 'error', 'create_time', 'update_time',
        ];
        $statement = $this->ledger()->prepare(
            'INSERT INTO ' . $this->ledgerTable()
            . ' (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')'
        );
        $statement->execute(array_map(static fn(string $field) => $delivery[$field] ?? null, $fields));
        $delivery['id'] = (int)$this->ledger()->lastInsertId();
        return $delivery;
    }

    private function updateDelivery(array $delivery): void
    {
        $statement = $this->ledger()->prepare(
            'UPDATE ' . $this->ledgerTable()
            . ' SET status = ?, payload = ?, error = ?, update_time = ? WHERE id = ?'
        );
        $statement->execute([
            (int)$delivery['status'],
            $delivery['payload'] ?? null,
            $delivery['error'] ?? null,
            (string)$delivery['update_time'],
            (int)$delivery['id'],
        ]);
    }

    private function payload(array $delivery): array
    {
        $payload = json_decode((string)($delivery['payload'] ?? ''), true);
        if (!is_array($payload) || count($payload) !== (int)$delivery['requested']) {
            throw new JSONException('Dola 本地提货记录损坏，请人工核对订单');
        }
        return array_map('strval', $payload);
    }

    private function saveDelivered(array &$delivery, array $lines): void
    {
        $delivery['payload'] = json_encode(array_values($lines), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $delivery['status'] = 1;
        $delivery['error'] = null;
        $delivery['update_time'] = Date::current();
        $this->updateDelivery($delivery);
    }

    /**
     * 上游已扣货但 HTTP 响应丢失时，查询记录中的 UA 能唯一定位本订单批次并把账号重新读回。
     *
     * @return string[]|null 没有发生扣货时返回 null，可以安全重试原请求
     */
    private function recover(array $delivery, string $domain, string $key): ?array
    {
        $status = $this->status($domain, $key);
        foreach ($status['history'] as $index => $history) {
            if (!is_array($history)) {
                continue;
            }
            if (
                hash_equals((string)$delivery['request_tag'], (string)($history['ua'] ?? ''))
                && (int)($history['n'] ?? -1) === (int)$delivery['requested']
            ) {
                return $this->historyBatch($domain, $key, (int)$index, (int)$delivery['requested']);
            }
        }
        if (
            $status['remaining'] === (int)$delivery['before_remaining']
            && $status['delivered_times'] === (int)$delivery['before_times']
        ) {
            return null;
        }
        throw new JSONException('Dola 上游状态发生了无法归属的变化，请在提货记录中人工核对后再处理此订单');
    }

    private function acquireLock(int $sharedId): string
    {
        $name = 'acg_dola_' . $sharedId;
        $statement = $this->ledger()->prepare('SELECT GET_LOCK(?, 30) AS acquired');
        $statement->execute([$name]);
        $row = $statement->fetch();
        if ((int)($row['acquired'] ?? 0) !== 1) {
            throw new JSONException('Dola 提货任务繁忙，请稍后重试');
        }
        return $name;
    }

    private function releaseLock(string $name): void
    {
        try {
            $statement = $this->ledger()->prepare('SELECT RELEASE_LOCK(?) AS released');
            $statement->execute([$name]);
        } catch (\Throwable) {
        }
    }

    /**
     * @throws JSONException
     */
    public function trade(Shared $shared, int $quantity, string $requestNo, string $credentials): string
    {
        if ($quantity < 1 || $quantity > 10000) {
            throw new JSONException('Dola 单次提货数量必须在 1–10000 之间');
        }
        $requestNo = trim($requestNo);
        if ($requestNo === '') {
            throw new JSONException('Dola 提货缺少订单号');
        }

        $keys = $this->parseKeys($credentials);
        $keyByHash = [];
        foreach ($keys as $key) {
            $keyByHash[hash('sha256', $key)] = $key;
        }

        $lock = $this->acquireLock((int)$shared->id);
        try {
            $rows = $this->deliveryRows((int)$shared->id, $requestNo);
            $allLines = [];
            foreach ($rows as &$row) {
                $recordedQuantity = (int)($row['order_quantity'] ?? 0);
                if ($recordedQuantity > 0 && $recordedQuantity !== $quantity) {
                    throw new JSONException('本次重试的购买数量与已提批次不一致，请恢复原数量后重试或联系管理员');
                }
                $key = $keyByHash[(string)$row['source_hash']] ?? null;
                if (!$key) {
                    throw new JSONException('Dola 订单使用的提货 KEY 已从货源配置移除，请恢复后重试');
                }
                if ((int)$row['status'] === 1) {
                    array_push($allLines, ...$this->payload($row));
                    continue;
                }
                $recovered = $this->recover($row, (string)$shared->domain, $key);
                if ($recovered !== null) {
                    $this->saveDelivered($row, $recovered);
                    array_push($allLines, ...$recovered);
                } else {
                    try {
                        $lines = $this->take((string)$shared->domain, $key, (int)$row['requested'], (string)$row['request_tag']);
                    } catch (\Throwable $e) {
                        $recovered = $this->recover($row, (string)$shared->domain, $key);
                        if ($recovered === null) {
                            $row['error'] = mb_substr($e->getMessage(), 0, 250);
                            $row['update_time'] = Date::current();
                            $this->updateDelivery($row);
                            throw $e;
                        }
                        $lines = $recovered;
                    }
                    $this->saveDelivered($row, $lines);
                    array_push($allLines, ...$lines);
                }
            }
            unset($row);

            if (count($allLines) > $quantity) {
                throw new JSONException('Dola 本地提货数量超过订单数量，请人工核对');
            }
            $needed = $quantity - count($allLines);
            if ($needed === 0) {
                return implode("\n", $allLines);
            }

            $aggregate = $this->aggregate((string)$shared->domain, $credentials);
            if ($aggregate['remaining'] < $needed) {
                throw new JSONException("Dola 库存不足，当前仅剩 {$aggregate['remaining']} 个");
            }

            $sequence = count($rows);
            foreach ($aggregate['sources'] as $source) {
                if ($needed <= 0) {
                    break;
                }
                $available = (int)$source['remaining'];
                while ($available > 0 && $needed > 0) {
                    $take = min($available, $needed, self::MAX_BATCH);
                    $key = (string)$source['key'];
                    $tag = 'acg-faka-dola/' . substr(hash('sha256', $requestNo), 0, 16) . '/' . $sequence;
                    $now = Date::current();
                    $delivery = $this->insertDelivery([
                        'shared_id' => (int)$shared->id,
                        'request_no' => $requestNo,
                        'sequence' => $sequence,
                        'order_quantity' => $quantity,
                        'source_hash' => hash('sha256', $key),
                        'request_tag' => $tag,
                        'requested' => $take,
                        'before_remaining' => $available,
                        'before_times' => (int)$source['delivered_times'],
                        'status' => 0,
                        'payload' => null,
                        'error' => null,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    try {
                        $lines = $this->take((string)$shared->domain, $key, $take, $tag);
                    } catch (\Throwable $e) {
                        $recovered = $this->recover($delivery, (string)$shared->domain, $key);
                        if ($recovered === null) {
                            $delivery['error'] = mb_substr($e->getMessage(), 0, 250);
                            $delivery['update_time'] = Date::current();
                            $this->updateDelivery($delivery);
                            throw $e;
                        }
                        $lines = $recovered;
                    }
                    $this->saveDelivered($delivery, $lines);
                    array_push($allLines, ...$lines);
                    $needed -= $take;
                    $available -= $take;
                    $source['delivered_times']++;
                    $sequence++;
                }
            }

            if ($needed !== 0 || count($allLines) !== $quantity) {
                throw new JSONException('Dola 提货未完成，请稍后重试订单发货');
            }
            return implode("\n", $allLines);
        } finally {
            $this->releaseLock($lock);
        }
    }
}
