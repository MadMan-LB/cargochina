<?php

/**
 * Exact fixed-scale decimal arithmetic for financial values.
 * BCMath is intentionally required: silently falling back to binary floats
 * would make ledger reconciliation non-deterministic.
 */
final class DecimalMath
{
    public static function assertAvailable(): void
    {
        if (!extension_loaded('bcmath')) {
            throw new RuntimeException('The BCMath PHP extension is required for financial calculations.');
        }
    }

    public static function normalize($value, int $scale = 4): string
    {
        self::assertAvailable();
        $value = trim((string) $value);
        if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal value');
        }
        return bcadd($value, '0', $scale);
    }

    public static function round($value, int $scale = 4): string
    {
        self::assertAvailable();
        $value = trim((string) $value);
        if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal value');
        }
        $increment = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        $adjusted = str_starts_with($value, '-')
            ? bcsub($value, $increment, $scale + 1)
            : bcadd($value, $increment, $scale + 1);
        return bcadd($adjusted, '0', $scale);
    }

    public static function add($left, $right, int $scale = 4): string
    {
        self::assertAvailable();
        return bcadd((string) $left, (string) $right, $scale);
    }

    public static function subtract($left, $right, int $scale = 4): string
    {
        self::assertAvailable();
        return bcsub((string) $left, (string) $right, $scale);
    }

    public static function multiply($left, $right, int $scale = 4): string
    {
        self::assertAvailable();
        return self::round(bcmul((string) $left, (string) $right, $scale + 1), $scale);
    }

    public static function divide($left, $right, int $scale = 4): string
    {
        self::assertAvailable();
        if (bccomp((string) $right, '0', $scale + 4) === 0) throw new InvalidArgumentException('Division by zero');
        return self::round(bcdiv((string) $left, (string) $right, $scale + 1), $scale);
    }

    public static function compare($left, $right, int $scale = 4): int
    {
        self::assertAvailable();
        return bccomp((string) $left, (string) $right, $scale);
    }

    public static function sum(array $values, int $scale = 4): string
    {
        $total = self::normalize('0', $scale);
        foreach ($values as $value) {
            $total = self::add($total, $value ?? '0', $scale);
        }
        return $total;
    }
}
