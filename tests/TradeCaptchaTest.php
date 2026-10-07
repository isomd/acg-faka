<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
require BASE_PATH . 'kernel/Util/Session.php';
require BASE_PATH . 'app/Util/TradeCaptcha.php';
require BASE_PATH . 'app/Util/Captcha.php';

use App\Util\TradeCaptcha;
use Kernel\Util\Session;

// CI also runs this same suite against an isolated Redis, not production data.
$redisPath = (string)getenv('CAPTCHA_TEST_REDIS_PATH');
if ($redisPath !== '') {
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', $redisPath);
}

// A child process uses the same file-session lock as real concurrent requests.
if (($argv[1] ?? '') === 'worker') {
    if ($redisPath === '') session_save_path($argv[2]);
    session_id($argv[3]);
    if ($argv[4] === 'issue') {
        echo TradeCaptcha::issue($argv[5]);
    } else {
        echo TradeCaptcha::check($argv[6], $argv[5]) ? '1' : '0';
    }
    exit;
}

function expect(bool $result, string $label): void
{
    if (!$result) throw new RuntimeException($label);
}

function mutateChallenges(callable $update): void
{
    Session::start();
    $update($_SESSION);
    Session::end();
}

function workers(string $directory, string $sid, array $jobs): array
{
    $processes = [];
    foreach ($jobs as $job) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $directory, $sid, ...$job],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process), 'worker started');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process) === 0 && $error === '', 'worker failed');
    }
    return $results;
}

$directory = sys_get_temp_dir() . '/acg-captcha-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
if ($redisPath === '') session_save_path($directory);
session_name('ACG-CAPTCHA-TEST');
$sid = 'test-' . bin2hex(random_bytes(16));
$otherSid = 'other-' . bin2hex(random_bytes(16));
session_id($sid);
$a = str_repeat('a', 32);
$b = str_repeat('b', 32);
$c = str_repeat('c', 32);

try {
    $codeA = TradeCaptcha::issue($a);
    $codeB = TradeCaptcha::issue($b);
    expect(TradeCaptcha::issue($a) === $codeA, 'duplicate image load is stable');
    expect(TradeCaptcha::check($codeA, $a), 'page A survives opening page B');
    expect(TradeCaptcha::check($codeB, $b), 'page B still works after checking A');
    expect(!TradeCaptcha::check($codeA, $a), 'successful answer cannot replay');

    $codeA = TradeCaptcha::issue($a);
    $codeB = TradeCaptcha::issue($b);
    $codeC = TradeCaptcha::issue($c, $a);
    expect(!TradeCaptcha::check($codeA, $a), 'refresh revokes its previous challenge');
    expect(TradeCaptcha::check($codeB, $b), 'refresh of A does not affect B');
    expect(TradeCaptcha::check($codeC, $c), 'refreshed challenge works');

    foreach (['', '123', '12345', 'abcd', '0000x', ' 1234', '1234\n'] as $wrong) {
        $code = TradeCaptcha::issue($a);
        expect(!TradeCaptcha::check($wrong, $a), 'malformed answer rejected');
        expect(!TradeCaptcha::check($code, $a), 'wrong attempt consumes challenge');
    }
    $code = TradeCaptcha::issue($a);
    $wrong = $code === '1234' ? '5678' : '1234';
    expect(!TradeCaptcha::check($wrong, $a), 'incorrect four-digit answer rejected');
    expect(!TradeCaptcha::check($code, $a), 'cannot retry a wrong answer');

    foreach (['0000', '0001', '0123'] as $zeroCode) {
        TradeCaptcha::issue($a);
        mutateChallenges(function (&$session) use ($a, $zeroCode) {
            $session['__trade_captchas'][$a]['code'] = $zeroCode;
        });
        expect(TradeCaptcha::check($zeroCode, $a), 'leading zero and all-zero codes work');
    }
    $code = TradeCaptcha::issue($a);
    mutateChallenges(function (&$session) use ($a) {
        $session['__trade_captchas'][$a]['expires'] = time() - 1;
    });
    expect(!TradeCaptcha::check($code, $a), 'expired answer rejected');

    $code = TradeCaptcha::issue($a);
    session_id($otherSid);
    expect(!TradeCaptcha::check($code, $a), 'other browser session cannot use challenge');
    session_id($sid);
    foreach (['', 'trade', strtoupper($a), str_repeat('a', 33), '../' . $a] as $invalid) {
        expect(!TradeCaptcha::check($code, $invalid), 'invalid or missing ID rejected');
        try {
            TradeCaptcha::issue($invalid);
            throw new RuntimeException('invalid ID was issued');
        } catch (InvalidArgumentException) {
        }
    }
    expect(TradeCaptcha::check($code, $a), 'invalid IDs cannot consume other challenge');
    Session::set('trade', '1234');
    expect(!TradeCaptcha::check('1234', ''), 'no legacy shared-slot fallback');

    for ($i = 0; $i < 40; $i++) {
        TradeCaptcha::issue(str_pad(dechex($i), 32, '0', STR_PAD_LEFT));
    }
    expect(count(Session::get('__trade_captchas')) === 32, 'session memory is bounded');
    expect(!TradeCaptcha::check('1234', str_repeat('0', 32)), 'oldest challenge evicted');

    $ids = array_map(fn($i) => str_pad(dechex(100 + $i), 32, '0', STR_PAD_LEFT), range(0, 7));
    $codes = workers($directory, $sid, array_map(fn($id) => ['issue', $id], $ids));
    foreach ($ids as $i => $id) {
        expect(TradeCaptcha::check($codes[$i], $id), 'concurrent image requests do not lose entries');
    }
    $code = TradeCaptcha::issue($a);
    $results = workers($directory, $sid, array_fill(0, 8, ['check', $a, $code]));
    expect(count(array_filter($results, fn($value) => $value === '1')) === 1,
        'concurrent duplicate submissions have exactly one successful check');

    // Existing login/admin/registration callers retain their original interface.
    Session::set('login', '1234');
    expect(\App\Util\Captcha::check(1234, 'login'), 'legacy login still works');
    expect(!\App\Util\Captcha::check(1234, 'login'), 'legacy login remains one-time');
    if (extension_loaded('gd')) {
        ob_start();
        \App\Util\Captcha::generate('trade', $b);
        $png = ob_get_clean();
        $image = getimagesizefromstring($png);
        expect($image[0] === 50 && $image[1] === 24 && $image[2] === IMAGETYPE_PNG,
            'actual challenge image is a 50x24 PNG');
        expect(TradeCaptcha::check(Session::get('__trade_captchas')[$b]['code'], $b),
            'rendered image stores matching challenge');
    }
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) Session::end();
    // Destroy only the two sessions created by this test, including in Redis.
    foreach ([$sid, $otherSid] as $testSid) {
        session_id($testSid);
        Session::clear();
    }
    foreach (glob($directory . '/sess_*') as $file) unlink($file);
    rmdir($directory);
}
echo "Trade CAPTCHA isolation, expiry and concurrency tests passed\n";
