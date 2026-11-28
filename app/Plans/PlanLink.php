<?php
declare(strict_types=1);

namespace App\Plans;

use App\Auth\LinkToken;

/**
 * 24-hour link to the plan preview page: a LinkToken whose purpose is "plan:<id>", so one plan's token does not fit another.
 * The token lets a person OPEN the page; approving additionally needs an admin session (PlansController::approve).
 */
final class PlanLink
{
    public static function purpose(string $planId): string
    {
        return "plan:$planId";
    }

    public static function make(string $purpose, ?int $nowMs = null, int $ttlMs = LinkToken::TTL_MS): string
    {
        return LinkToken::make($purpose, $ttlMs, null, $nowMs);
    }

    public static function valid(string $purpose, ?string $token, ?int $nowMs = null): bool
    {
        return LinkToken::verify($purpose, $token, $nowMs);
    }
}
