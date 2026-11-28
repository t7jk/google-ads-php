<?php
declare(strict_types=1);

namespace App\Install;

/** One line of the installer's check list: a status, what was checked and (for WARN/FAIL) what to do about it, in plain words. */
final class Check
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    private function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $detail,
    ) {
    }

    public static function ok(string $label, string $detail = ''): self
    {
        return new self(self::OK, $label, $detail);
    }

    public static function warn(string $label, string $detail): self
    {
        return new self(self::WARN, $label, $detail);
    }

    public static function fail(string $label, string $detail): self
    {
        return new self(self::FAIL, $label, $detail);
    }

    /** @param list<self> $checks */
    public static function anyFailed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->status === self::FAIL) {
                return true;
            }
        }
        return false;
    }
}
