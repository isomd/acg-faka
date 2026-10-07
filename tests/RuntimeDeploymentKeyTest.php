<?php
declare(strict_types=1);

require dirname(__DIR__) . '/kernel/Util/EncryptedDeploymentConfig.php';

use Kernel\Util\EncryptedDeploymentConfig as Config;

if (($argv[1] ?? '') === 'worker') {
    // A fresh process has no static configuration cache and no original key.
    exit(Config::database()['host'] === 'db.internal' ? 0 : 1);
}

function expect(bool $value, string $label): void
{
    if (!$value) throw new RuntimeException($label);
}

function resetCache(): void
{
    (new ReflectionProperty(Config::class, 'cached'))->setValue(null, null);
}

function rejected(callable $operation, string $label): void
{
    resetCache();
    try {
        $operation();
    } catch (RuntimeException | JsonException) {
        return;
    }
    throw new RuntimeException($label);
}

function freshRequest(): void
{
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        $command = [...$command, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=openssl'];
    }
    $process = proc_open([...$command, __FILE__, 'worker'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process), 'could not start fresh PHP process');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process) === 0 && $output === '' && $error === '',
        'fresh PHP request must decrypt using only the prepared runtime key');
}

$directory = sys_get_temp_dir() . '/acg-runtime-key-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$keyFile = $directory . '/source-key';
$cipherFile = $directory . '/encrypted.json';
$runtimeFile = $directory . '/runtime-key.json';
$config = ['database' => ['host' => 'db.internal', 'database' => 'shop', 'username' => 'shop',
    'password' => 'test-only-database-password'], 'redis' => ['enabled' => false]];
$originalEnvironment = [];
foreach (['ACG_CONFIG_KEY_FILE', 'ACG_ENCRYPTED_CONFIG', 'ACG_CONFIG_RUNTIME_KEY_FILE'] as $name) {
    $originalEnvironment[$name] = getenv($name);
}
putenv('ACG_CONFIG_KEY_FILE=' . $keyFile);
putenv('ACG_ENCRYPTED_CONFIG=' . $cipherFile);
putenv('ACG_CONFIG_RUNTIME_KEY_FILE=' . $runtimeFile);

try {
    foreach (['test-only-long-passphrase', bin2hex(random_bytes(32))] as $secret) {
        file_put_contents($keyFile, $secret);
        $ciphertext = Config::encrypt($config, $secret);
        file_put_contents($cipherFile, $ciphertext);
        Config::prepareRuntimeKey($runtimeFile);
        $recordText = file_get_contents($runtimeFile);
        expect(!str_contains($recordText, $secret), 'runtime record must not contain original secret');
        expect(!str_contains($recordText, $config['database']['password']), 'never persist plaintext config');
        $record = json_decode($recordText, true, 4, JSON_THROW_ON_ERROR);
        expect($record['envelope_sha256'] === hash('sha256', $ciphertext), 'key bound to exact ciphertext');
        expect(strlen(base64_decode($record['key'], true)) === 32, 'derived AES key has 32 bytes');
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $runtimeFile);
            expect((fileperms($runtimeFile) & 0777) === 0600, 'record is private at publication');
        }
        // No source file = PBKDF2 cannot accidentally run on a later request.
        unlink($keyFile);
        resetCache();
        expect(Config::database()['password'] === $config['database']['password'], 'fast path matches config');
        freshRequest(); freshRequest();

        unlink($runtimeFile);
        rejected(fn() => Config::load(), 'missing cache must fail, not fall back');
        file_put_contents($runtimeFile, '{bad json');
        rejected(fn() => Config::load(), 'malformed cache rejected');
        foreach (['version' => 2, 'envelope_sha256' => str_repeat('0', 64),
                  'key' => base64_encode('short')] as $field => $value) {
            file_put_contents($runtimeFile, json_encode(array_replace($record, [$field => $value])));
            rejected(fn() => Config::load(), 'invalid runtime key rejected');
        }
        file_put_contents($runtimeFile, json_encode(array_replace($record,
            ['key' => base64_encode(random_bytes(32))])));
        rejected(fn() => Config::load(), 'wrong derived key fails GCM authentication');
        file_put_contents($runtimeFile, $recordText);

        $envelope = json_decode($ciphertext, true, 8, JSON_THROW_ON_ERROR);
        $envelope['data'] = base64_encode('tampered');
        $tampered = json_encode($envelope, JSON_THROW_ON_ERROR);
        file_put_contents($cipherFile, $tampered);
        rejected(fn() => Config::load(), 'changed ciphertext rejected before reuse');
        // Even forged matching metadata cannot bypass AES-GCM authentication.
        file_put_contents($runtimeFile, json_encode(array_replace($record,
            ['envelope_sha256' => hash('sha256', $tampered)])));
        rejected(fn() => Config::load(), 'tampered ciphertext fails GCM');
        file_put_contents($keyFile, $secret);
        rejected(fn() => Config::prepareRuntimeKey($runtimeFile), 'startup must authenticate ciphertext');
        file_put_contents($cipherFile, $ciphertext);

        file_put_contents($keyFile, 'wrong-long-test-passphrase');
        rejected(fn() => Config::prepareRuntimeKey($runtimeFile), 'wrong startup secret rejected');
        file_put_contents($keyFile, $secret);
        Config::prepareRuntimeKey($runtimeFile);
        resetCache();
        expect(Config::database()['host'] === 'db.internal', 'restart regenerates correct key');
        $rotated = Config::encrypt($config, 'rotated-test-only-passphrase');
        file_put_contents($cipherFile, $rotated);
        rejected(fn() => Config::load(), 'rotation requires new startup preparation');
        file_put_contents($keyFile, 'rotated-test-only-passphrase');
        Config::prepareRuntimeKey($runtimeFile);
        unlink($keyFile);
        freshRequest();
        expect(glob($runtimeFile . '.tmp-*') === [], 'temporary key files cleaned up');
    }
    echo "Runtime deployment key tests passed (fresh processes, tampering, rotation, no source key)\n";
} finally {
    foreach ($originalEnvironment as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    foreach ([$keyFile, $cipherFile, $runtimeFile] as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($directory);
}
