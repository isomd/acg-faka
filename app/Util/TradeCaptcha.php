<?php
declare(strict_types=1);

namespace App\Util;

use Kernel\Util\Session;

/** Purchase challenges are isolated by image ID AND by browser session. */
final class TradeCaptcha
{
    private const KEY = '__trade_captchas';
    private const TTL = 600;
    private const LIMIT = 32;

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }

    private static function open(): void
    {
        Session::start();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('无法保存验证码，请刷新页面重试');
        }
    }

    private static function prune(array $challenges): array
    {
        $now = time();
        foreach ($challenges as $id => $entry) {
            if (!is_array($entry) || ($entry['expires'] ?? 0) <= $now) {
                unset($challenges[$id]);
            }
        }
        return $challenges;
    }

    public static function issue(string $id, string $previous = ''): string
    {
        if (!self::validId($id)) {
            throw new \InvalidArgumentException('验证码标识无效，请刷新页面');
        }
        self::open();
        try {
            $challenges = self::prune((array)($_SESSION[self::KEY] ?? []));
            if ($previous !== $id && self::validId($previous)) {
                unset($challenges[$previous]);
            }
            // Duplicate image loads must not overwrite the code already displayed.
            if (!isset($challenges[$id])) {
                while (count($challenges) >= self::LIMIT) {
                    unset($challenges[array_key_first($challenges)]);
                }
                $code = '';
                for ($i = 0; $i < 4; $i++) {
                    $code .= random_int(0, 9);
                }
                $challenges[$id] = ['code' => $code, 'expires' => time() + self::TTL];
            }
            $_SESSION[self::KEY] = $challenges;
            return $challenges[$id]['code'];
        } finally {
            Session::end();
        }
    }

    public static function check(string $code, string $id): bool
    {
        if (!self::validId($id)) {
            return false; // No fallback to the old shared "trade" slot.
        }
        self::open();
        try {
            $challenges = self::prune((array)($_SESSION[self::KEY] ?? []));
            $stored = $challenges[$id]['code'] ?? null;
            // Read and consume under ONE session lock, even on a wrong answer.
            unset($challenges[$id]);
            $_SESSION[self::KEY] = $challenges;
            return is_string($stored) && preg_match('/^[0-9]{4}$/D', $code) === 1
                && hash_equals($stored, $code);
        } finally {
            Session::end();
        }
    }
}
