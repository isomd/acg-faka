<?php
declare(strict_types=1);

namespace App\Util;

use App\Model\Config;
use Kernel\Exception\JSONException;

final class RedeemCode
{
    private const KEY_CONFIG = 'redeem_code_hmac_key';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private static ?string $key = null;

    /**
     * Initialize before administrative issuance or a redemption-code purchase.
     * The key stays in the database-only secret channel, never in public config.
     */
    public static function ensureKey(): string
    {
        $key = self::readKey();
        if ($key !== null) {
            return $key;
        }

        $candidate = random_bytes(32);
        Config::query()->insertOrIgnore([
            'key' => self::KEY_CONFIG,
            'value' => base64_encode($candidate),
        ]);

        // Concurrent first requests may race on the unique config key. Always
        // read back the winning value instead of keeping a losing candidate.
        self::$key = null;
        $key = self::readKey();
        if ($key === null) {
            throw new JSONException('无法初始化兑换码密钥，请检查数据库写入权限');
        }
        return $key;
    }

    public static function digest(string $normalized): string
    {
        $key = self::readKey();
        if ($key === null) {
            throw new JSONException('兑换服务尚未初始化，请先在后台生成或导入兑换码');
        }
        return hash_hmac('sha256', $normalized, $key);
    }

    public static function normalize(mixed $code): string
    {
        if (!is_scalar($code)) {
            throw new JSONException('兑换码格式不正确');
        }
        $normalized = strtoupper(trim((string)$code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{7,63}$/D', $normalized)) {
            throw new JSONException('兑换码需为 8 到 64 位字母、数字、下划线或短横线');
        }
        return $normalized;
    }

    public static function mask(string $normalized): string
    {
        $length = strlen($normalized);
        if ($length <= 10) {
            return substr($normalized, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($normalized, -2);
        }
        return substr($normalized, 0, 5) . str_repeat('*', min(12, $length - 9)) . substr($normalized, -4);
    }

    public static function generate(string $prefix = 'ACG-R'): string
    {
        $prefix = strtoupper(trim($prefix));
        if ($prefix === '') {
            $prefix = 'ACG-R';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,15}$/D', $prefix)) {
            throw new JSONException('兑换码前缀需为 1 到 16 位字母、数字、下划线或短横线');
        }

        $parts = [];
        for ($group = 0; $group < 4; $group++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $parts[] = $part;
        }
        return $prefix . '-' . implode('-', $parts);
    }

    private static function readKey(): ?string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $decoded = base64_decode(trim(Config::secret(self::KEY_CONFIG)), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return null;
        }
        return self::$key = $decoded;
    }
}
