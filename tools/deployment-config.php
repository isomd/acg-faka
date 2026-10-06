<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Kernel\Util\EncryptedDeploymentConfig as Config;

function requireOutsideRepository(string $path): void
{
    $repository = realpath(dirname(__DIR__));
    $target = realpath($path) ?: realpath(dirname($path));
    if ($repository !== false && $target !== false) {
        $repository = strtolower(rtrim($repository, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
        $target = strtolower(rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
        if (str_starts_with($target, $repository)) {
            throw new RuntimeException('明文配置和密钥文件必须放在仓库之外');
        }
    }
}

try {
    $action = $argv[1] ?? '';
    if ($action === 'new-key' && count($argv) === 3) {
        $path = $argv[2];
        requireOutsideRepository($path);
        if (file_exists($path)) {
            throw new RuntimeException('密钥文件已存在，拒绝覆盖');
        }
        if (file_put_contents($path, bin2hex(random_bytes(32)) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('无法写入密钥文件');
        }
        chmod($path, 0600);
        echo "密钥已生成，请将文件内容保存在 GitHub Secret ACG_CONFIG_KEY_HEX 中。\n";
        exit(0);
    }
    if ($action === 'encrypt' && count($argv) === 5) {
        [$script, $action, $input, $keyFile, $output] = $argv;
        requireOutsideRepository($input);
        requireOutsideRepository($keyFile);
        if (file_exists($output)) {
            throw new RuntimeException('密文文件已存在，拒绝覆盖');
        }
        $config = json_decode((string)file_get_contents($input), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($config) || !isset($config['database']) || !is_array($config['database'])) {
            throw new RuntimeException('明文 JSON 必须包含 database 对象');
        }
        $key = trim((string)file_get_contents($keyFile));
        $encrypted = Config::encrypt($config, $key);
        if (file_put_contents($output, $encrypted, LOCK_EX) === false) {
            throw new RuntimeException('无法写入密文文件');
        }
        echo "密文已生成，只有该文件可以提交到仓库。\n";
        exit(0);
    }
    fwrite(STDERR, "用法：php tools/deployment-config.php new-key <密钥路径>\n"
        . "或：php tools/deployment-config.php encrypt <明文 JSON 路径> <密钥路径> <密文输出路径>\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
