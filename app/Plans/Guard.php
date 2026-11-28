<?php
declare(strict_types=1);

namespace App\Plans;

/**
 * Plan-level validation (port of src/plans/guard.ts). Each returns a list of error messages (empty = OK).
 * The final write guard is GuardOps (called by Ads::mutate); the checks here serve the plan preview.
 */
final class Guard
{
    public static function checkBudget(float $pln): array
    {
        $max = Cfg::maxDailyBudgetPln();
        if (!is_finite($pln) || $pln <= 0) {
            return ['The daily budget must be greater than 0 PLN'];
        }
        if ($pln > $max) {
            return ["Budget PLN $pln/day exceeds the limit of PLN $max/day; change budgets above PLN $max manually in Google Ads"];
        }
        return [];
    }

    /** @param array{headlines: list<string>, descriptions: list<string>, path1?: string, path2?: string} $r */
    public static function checkRsa(array $r): array
    {
        $e = [];
        $h = $r['headlines'] ?? [];
        $d = $r['descriptions'] ?? [];
        if (count($h) < 3 || count($h) > 15) {
            $e[] = 'An ad must have 3 to 15 headlines (has ' . count($h) . ')';
        }
        if (count($d) < 2 || count($d) > 4) {
            $e[] = 'An ad must have 2 to 4 descriptions (has ' . count($d) . ')';
        }
        foreach ($h as $i => $x) {
            if (mb_strlen($x) > 30) {
                $e[] = 'Headline ' . ($i + 1) . ' has ' . mb_strlen($x) . ' characters (max 30)';
            }
        }
        foreach ($d as $i => $x) {
            if (mb_strlen($x) > 90) {
                $e[] = 'Description ' . ($i + 1) . ' has ' . mb_strlen($x) . ' characters (max 90)';
            }
        }
        if (self::hasDuplicates($h)) {
            $e[] = 'Headlines must not repeat (case-insensitive)';
        }
        if (self::hasDuplicates($d)) {
            $e[] = 'Descriptions must not repeat (case-insensitive)';
        }
        $p1 = (string) ($r['path1'] ?? '');
        $p2 = (string) ($r['path2'] ?? '');
        if ($p2 !== '' && $p1 === '') {
            $e[] = 'Path 2 requires path 1';
        }
        if ($p1 !== '' && mb_strlen($p1) > 15) {
            $e[] = 'Path 1 is longer than 15 characters';
        }
        if ($p2 !== '' && mb_strlen($p2) > 15) {
            $e[] = 'Path 2 is longer than 15 characters';
        }
        return $e;
    }

    private static function hasDuplicates(array $xs): bool
    {
        $seen = [];
        foreach ($xs as $x) {
            $k = mb_strtolower(trim($x));
            if (isset($seen[$k])) {
                return true;
            }
            $seen[$k] = true;
        }
        return false;
    }

    /** Error message or null; the shared host rule from Lp2\UrlHost. */
    public static function checkUrlHost(string $url): ?string
    {
        return \App\Lp2\UrlHost::check($url);
    }

    /** @return array{errors: list<string>, warnings: list<string>} */
    public static function checkFinalUrl(string $url): array
    {
        $bad = self::checkUrlHost($url);
        if ($bad !== null) {
            return ['errors' => [$bad], 'warnings' => []];
        }
        $prefix = Cfg::landingPathPrefix();
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        return ['errors' => [], 'warnings' => $prefix === '' || str_starts_with($path, $prefix) ? [] : ["Adres docelowy spoza $prefix: $url"]];
    }

    /** No plan operation may enable a campaign (status ENABLED in create/update). */
    public static function assertNoEnable(array $ops): array
    {
        $errors = [];
        foreach ($ops as $op) {
            $c = $op['campaignOperation'] ?? null;
            if (is_array($c) && (($c['create']['status'] ?? null) === 'ENABLED' || ($c['update']['status'] ?? null) === 'ENABLED')) {
                $errors[] = 'The operation must not enable a campaign (status ENABLED)';
            }
        }
        return $errors;
    }

    public static function requireEuDeclaration(array $ops): array
    {
        $errors = [];
        foreach ($ops as $op) {
            $c = $op['campaignOperation']['create'] ?? null;
            if (is_array($c) && ($c['containsEuPoliticalAdvertising'] ?? null) !== 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING') {
                $errors[] = 'A new campaign requires the EU political advertising declaration (DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING)';
            }
        }
        return $errors;
    }
}
