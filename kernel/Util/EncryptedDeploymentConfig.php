<?php
declare(strict_types=1);

namespace Kernel\Util;

use RuntimeException;

final class EncryptedDeploymentConfig
{
    private const AAD = 'acg-faka-deployment-config-v1';
    private static ?array $cached = null;

    public static function enabled(): bool
    {
        return (string)getenv('ACG_ENCRYPTED_CONFIG') !== '';
    }

    public static function load(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $path = (string)getenv('ACG_ENCRYPTED_CONFIG');
        $keyPath = (string)getenv('ACG_CONFIG_KEY_FILE');
        if ($path === '' || $keyPath === '' || !is_readable($path) || !is_readable($keyPath)) {
            throw new RuntimeException('加密部署配置或解密密钥不可读取');
        }
        $envelope = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1 || ($envelope['cipher'] ?? null) !== 'aes-256-gcm') {
            throw new RuntimeException('加密部署配置格式不支持');
        }
        $secret = trim((string)file_get_contents($keyPath));
        $key = self::deriveKey($secret, $envelope);
        $nonce = self::decode($envelope, 'nonce', 12);
        $tag = self::decode($envelope, 'tag', 16);
        $ciphertext = self::decode($envelope, 'data');
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD);
        if ($plaintext === false) {
            throw new RuntimeException('部署配置解密失败，请检查密钥和密文版本');
        }
        $config = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($config) || !isset($config['database']) || !is_array($config['database'])) {
            throw new RuntimeException('部署配置缺少 database 对象');
        }
        self::$cached = $config;
        return $config;
    }

    public static function database(): array
    {
        $db = self::load()['database'];
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!isset($db[$field]) || !is_string($db[$field]) || $db[$field] === '') {
                throw new RuntimeException('部署数据库配置缺少 ' . $field);
            }
        }
        $port = (int)($db['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('部署数据库端口无效');
        }
        return [
            'driver' => 'mysql',
            'host' => $db['host'],
            'port' => $port,
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => $db['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => (string)($db['prefix'] ?? 'acg_'),
        ];
    }

    public static function configureSession(): void
    {
        $redis = self::load()['redis'] ?? [];
        if (!is_array($redis) || ($redis['enabled'] ?? false) !== true) {
            return;
        }
        $host = (string)($redis['host'] ?? '');
        $port = (int)($redis['port'] ?? 6379);
        $database = (int)($redis['database'] ?? 1);
        if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $host)
            || $port < 1 || $port > 65535 || $database < 0 || $database > 15) {
            throw new RuntimeException('Redis 配置无效');
        }
        if (!extension_loaded('redis')) {
            throw new RuntimeException('启用 Redis 会话需要 PHP Redis 扩展');
        }
        $path = 'tcp://' . $host . ':' . $port . '?database=' . $database . '&prefix=acg_sess:';
        $password = (string)($redis['password'] ?? '');
        if ($password !== '') {
            $path .= '&auth=' . rawurlencode($password);
        }
        if (ini_set('session.save_handler', 'redis') === false
            || ini_set('session.save_path', $path) === false) {
            throw new RuntimeException('无法配置 Redis 会话');
        }
    }

    public static function encrypt(array $config, string $keyHex): string
    {
        $keyHex = trim($keyHex);
        $envelope = ['version' => 1, 'cipher' => 'aes-256-gcm'];
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $keyHex)) {
            $envelope['kdf'] = 'pbkdf2-sha256';
            $envelope['salt'] = base64_encode(random_bytes(16));
        }
        $key = self::deriveKey($keyHex, $envelope);
        $nonce = random_bytes(12);
        $tag = '';
        $plaintext = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16);
        if ($ciphertext === false) {
            throw new RuntimeException('部署配置加密失败');
        }
        return json_encode($envelope + [
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function deriveKey(string $secret, array $envelope): string
    {
        if (($envelope['kdf'] ?? null) === 'pbkdf2-sha256') {
            if (strlen($secret) < 12 || str_contains($secret, "\n") || str_contains($secret, "\r")) {
                throw new RuntimeException('部署配置口令必须至少 12 字节且不能包含换行');
            }
            return hash_pbkdf2('sha256', $secret, self::decode($envelope, 'salt', 16), 600000, 32, true);
        }
        if (isset($envelope['kdf']) || !preg_match('/^[a-fA-F0-9]{64}$/D', $secret)) {
            throw new RuntimeException('部署配置密钥或派生算法无效');
        }
        return hex2bin($secret);
    }

    private static function decode(array $envelope, string $field, ?int $length = null): string
    {
        $raw = isset($envelope[$field]) && is_string($envelope[$field])
            ? base64_decode($envelope[$field], true) : false;
        if ($raw === false || ($length !== null && strlen($raw) !== $length)) {
            throw new RuntimeException('加密部署配置字段无效：' . $field);
        }
        return $raw;
    }
}
