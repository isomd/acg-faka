<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/kernel/Helper.php';

use Kernel\Util\EncryptedDeploymentConfig as Config;

$key = bin2hex(random_bytes(32));
$config = [
    'database' => [
        'host' => 'db.internal', 'port' => 3306, 'database' => 'shop',
        'username' => 'shop', 'password' => 'only-in-memory-test-password', 'prefix' => 'acg_',
    ],
    'redis' => ['enabled' => false],
];
$encrypted = Config::encrypt($config, $key);
if (str_contains($encrypted, $config['database']['password'])) {
    throw new RuntimeException('密文包含明文密码');
}

$directory = sys_get_temp_dir() . '/acg-encrypted-config-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$keyFile = $directory . '/key';
$cipherFile = $directory . '/config.json';
try {
    file_put_contents($keyFile, $key);
    file_put_contents($cipherFile, $encrypted);
    putenv('ACG_CONFIG_KEY_FILE=' . $keyFile);
    putenv('ACG_ENCRYPTED_CONFIG=' . $cipherFile);
    if (Config::database()['password'] !== $config['database']['password']) {
        throw new RuntimeException('解密后数据库配置不一致');
    }
    if (config('database')['host'] !== 'db.internal') {
        throw new RuntimeException('项目配置入口没有使用加密数据库配置');
    }

    $cache = new ReflectionProperty(Config::class, 'cached');
    $cache->setValue(null, null);
    $envelope = json_decode($encrypted, true, 8, JSON_THROW_ON_ERROR);
    $envelope['data'] = base64_encode('tampered');
    file_put_contents($cipherFile, json_encode($envelope, JSON_THROW_ON_ERROR));
    try {
        Config::database();
        throw new RuntimeException('篡改密文仍被接受');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), '解密失败')) {
            throw $e;
        }
    }
    $passphrase = 'test-only-long-passphrase';
    $cache->setValue(null, null);
    file_put_contents($keyFile, $passphrase);
    file_put_contents($cipherFile, Config::encrypt($config, $passphrase));
    if (Config::database()['password'] !== $config['database']['password']) {
        throw new RuntimeException('口令模式解密失败');
    }
    $cache->setValue(null, null);
    file_put_contents($keyFile, 'wrong-test-passphrase');
    try {
        Config::database();
        throw new RuntimeException('错误口令仍被接受');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), '解密失败')) throw $e;
    }
    echo "Encrypted deployment config tests passed\n";
} finally {
    putenv('ACG_CONFIG_KEY_FILE');
    putenv('ACG_ENCRYPTED_CONFIG');
    unlink($keyFile);
    unlink($cipherFile);
    rmdir($directory);
}
