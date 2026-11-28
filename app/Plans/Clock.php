<?php
declare(strict_types=1);

namespace App\Plans;

/** Clock that tests can override (Clock::set); in production returns the current UTC time. */
final class Clock
{
    private static ?\DateTimeImmutable $fixed = null;

    public static function now(): \DateTimeImmutable
    {
        return self::$fixed ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function set(?\DateTimeImmutable $t): void
    {
        self::$fixed = $t;
    }

    public static function iso(?\DateTimeImmutable $t = null): string
    {
        return ($t ?? self::now())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
