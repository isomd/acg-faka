<?php
declare(strict_types=1);

namespace App\Pay\Mercury;

use InvalidArgumentException;

final class Protocol
{
    public static function trimJava(?string $value): string
    {
        return (string)preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', (string)$value);
    }

    /**
     * Mercury v1 canonical money: HALF_UP to two decimal places, no exponent.
     */
    public static function money(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $raw = self::trimJava(is_float($value) ? sprintf('%.14F', $value) : (string)$value);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $raw, $match)) {
            throw new InvalidArgumentException('invalid decimal amount');
        }

        $negative = $match[1] === '-';
        $integer = ltrim($match[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $match[3] ?? '';
        $absolute = $integer . ($fraction === '' ? '' : '.' . $fraction);
        $rounded = bcadd($absolute, '0.005', 2);

        if ($negative && bccomp($rounded, '0.00', 2) !== 0) {
            return '-' . $rounded;
        }
        return $rounded;
    }

    /**
     * Business amounts are stricter than canonical test vectors: positive and already exact to cents.
     */
    public static function exactPositiveMoney(string|int|float $value): string
    {
        // PHP's JSON decoder and ORM expose JSON/DECIMAL values as float in some paths.
        // Convert such trusted values to a plain cents representation before validating the range.
        $raw = self::trimJava(is_float($value) ? sprintf('%.2F', $value) : (string)$value);
        if (!preg_match('/^\d+(?:\.(\d{1,2}))?$/D', $raw)) {
            throw new InvalidArgumentException('amount must be a positive decimal with at most two fractional digits');
        }
        $money = self::money($raw);
        if (bccomp($money, '0.00', 2) <= 0 || bccomp($money, '99999999.99', 2) > 0) {
            throw new InvalidArgumentException('amount is outside the supported range');
        }
        return $money;
    }

    public static function orderSignature(array $request, string $appKey, string $secret, string $timestamp): string
    {
        $quantity = $request['quantity'] ?? 1;
        $benefit = array_key_exists('benefitSnapshot', $request) && $request['benefitSnapshot'] !== null
            ? self::trimJava((string)$request['benefitSnapshot'])
            : '{}';
        $canonical = self::trimJava((string)($request['skuCode'] ?? '')) . '|'
            . self::trimJava((string)($request['productName'] ?? '')) . '|'
            . self::trimJava((string)($request['productType'] ?? '')) . '|'
            . (string)$quantity . '|'
            . self::money($request['unitPrice'] ?? null) . '|'
            . self::money($request['totalAmount'] ?? null) . '|'
            . self::trimJava((string)($request['currency'] ?? '')) . '|'
            . $benefit;
        $digest = hash('sha256', $canonical);
        $orderCanonical = $appKey . ':' . (string)($request['clientOrderNo'] ?? '') . ':' . $digest . ':' . $timestamp;
        return hash_hmac('sha256', $orderCanonical, $secret);
    }

    public static function returnParams(string $tradeNo): array
    {
        // Mercury reserves clientOrderNo; the shop query page expects tradeNo.
        return ['tradeNo' => $tradeNo];
    }

    public static function webhookSignature(string $eventId, string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $eventId . ':' . $timestamp . ':' . $rawBody, $secret);
    }

    public static function validHexSignature(string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $signature) === 1;
    }
}
