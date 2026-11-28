<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * Registry of plan kinds: the only place the executor, undo and validation ask about the behaviour of a given kind.
 * Loads all app/Plans/Kinds/*.php files (each returns an array `kind => definition`), so a new builder (D2) adds
 * ITS OWN file Kinds/<Name>.php without touching the core. Kind definition (all keys optional unless noted):
 *   'fingerprint'   => fn(array $plan): string   fingerprint of the current account state within the plan's scope (compared with
 *                      beforeFingerprint on approval and with afterFingerprint on undo); missing = kind without state check
 *   'needsCampaign' => bool                      the plan must have a campaignId (otherwise refused)
 *   'noSnapshot'    => bool                      the kind creates new resources and has no fingerprint (new_campaign, conversion_action)
 *   'mutate'        => fn(array $ops, bool $validateOnly): array{results: array}   custom write instead of Ads::mutate (e.g. custom_audience)
 *   'validate'      => fn(array &$plan): bool    custom validation in Google (validateOnly); true = handled (skip the default)
 *   'run'           => fn(array $plan): array{ok: bool, message: string, results?: mixed}  custom execution of the whole plan
 *                      (ga4_key_events, offline_conversions); may use Executor::refuse/fail/succeed
 *   'message'       => fn(array $plan, array $planOps, array $results, string $default): string   message after a successful run
 *   'undo'          => fn(array $plan): array   custom undo (e.g. ga4_key_events): ['plan' => array] or ['message' => string, 'refused' => bool]
 * Special key '*' (global hooks): 'materialize' => fn(array $ops): array — replaces blob references with bytes
 * before sending (throws MissingBlob).
 */
final class Kinds
{
    private static ?array $all = null;

    private static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            $files = glob(__DIR__ . '/Kinds/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                self::$all = array_merge(self::$all, (array) require $file);
            }
        }
        return self::$all;
    }

    public static function get(string $kind): ?array
    {
        return $kind === '*' ? null : (self::all()[$kind] ?? null);
    }

    public static function hook(string $name): ?callable
    {
        return self::all()['*'][$name] ?? null;
    }

    /** Current account-state fingerprint for the plan (for comparison); null = kind without a fingerprint. @throws \Throwable API read errors */
    public static function currentFingerprint(array $plan): ?string
    {
        $fp = self::get($plan['kind'])['fingerprint'] ?? null;
        return $fp ? $fp($plan) : null;
    }
}
