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
        [$raw, $envelope] = self::readEnvelope($path);
        $runtimePath = (string)getenv('ACG_CONFIG_RUNTIME_KEY_FILE');
        // Docker prepares this key before starting FPM. Never silently fall back
        // to PBKDF2 if the explicitly configured runtime key is missing/stale.
        $key = $runtimePath !== '' ? self::readRuntimeKey($runtimePath, $raw)
            : self::deriveKey(self::readSecret(), $envelope);
        self::$cached = self::decryptConfig($envelope, $key);
        return self::$cached;
    }

    /** CLI startup only: authenticate the ciphertext before publishing a key. */
    public static function prepareRuntimeKey(string $target): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('运行时密钥只能在服务启动时生成');
        }
        if ($target === '' || !is_dir(dirname($target))) {
            throw new RuntimeException('运行时密钥目录不存在');
        }
        [$raw, $envelope] = self::readEnvelope((string)getenv('ACG_ENCRYPTED_CONFIG'));
        $key = self::deriveKey(self::readSecret(), $envelope);
        self::decryptConfig($envelope, $key);
        $record = json_encode(['version' => 1, 'envelope_sha256' => hash('sha256', $raw),
            'key' => base64_encode($key)], JSON_THROW_ON_ERROR);
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        $previousUmask = umask(0077);
        try {
            // 0600 from creation, atomic replacement on the same tmpfs. No
            // plaintext database/Redis configuration is written anywhere.
            if (file_put_contents($temporary, $record, LOCK_EX) !== strlen($record)
                || !chmod($temporary, 0600) || !rename($temporary, $target)) {
                throw new RuntimeException('无法保存运行时密钥');
            }
        } finally {
            umask($previousUmask);
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private static function readSecret(): string
    {
        $keyPath = (string)getenv('ACG_CONFIG_KEY_FILE');
        if ($keyPath === '' || !is_readable($keyPath)) {
            throw new RuntimeException('部署配置解密密钥不可读取');
        }
        return trim((string)file_get_contents($keyPath));
    }

    /** @return array{string, array} */
    private static function readEnvelope(string $path): array
    {
        if ($path === '' || !is_readable($path)) {
            throw new RuntimeException('加密部署配置不可读取');
        }
        $raw = (string)file_get_contents($path);
        $envelope = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1 || ($envelope['cipher'] ?? null) !== 'aes-256-gcm') {
            throw new RuntimeException('加密部署配置格式不支持');
        }
        if (isset($envelope['kdf'])) {
            if ($envelope['kdf'] !== 'pbkdf2-sha256') {
                throw new RuntimeException('部署配置派生算法无效');
            }
            self::decode($envelope, 'salt', 16);
        }
        return [$raw, $envelope];
    }

    private static function readRuntimeKey(string $path, string $raw): string
    {
        if (!is_readable($path)) {
            throw new RuntimeException('运行时密钥不可读取，请重启服务');
        }
        $record = json_decode((string)file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['version'] ?? null) !== 1
            || !is_string($record['envelope_sha256'] ?? null)
            || !hash_equals(hash('sha256', $raw), $record['envelope_sha256'])) {
            throw new RuntimeException('运行时密钥与密文版本不匹配，请重启服务');
        }
        return self::decode($record, 'key', 32);
    }

    private static function decryptConfig(array $envelope, string $key): array
    {
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
