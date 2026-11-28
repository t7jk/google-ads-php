<?php
declare(strict_types=1);

namespace App\Google;

use App\Ads\Errors;
use App\Ads\Money;
use App\Ads\MutationRefused;
use App\Ads\UserError;

/**
 * Google Ads REST client (port of src/ads/client.ts). API version and account number come from config.php.
 * Headers: Bearer only (as in Node); developer-token / login-customer-id are added when set in config.
 * No cache between requests (every PHP request starts from scratch) and no daily quota counter.
 * Writes (mutate*, uploadClickConversions) go through the guard App\Plans\GuardOps (written together with the plans);
 * no guard = refusal (fail closed).
 */
final class Ads
{
    private const MAX_ROWS_HARD = 50_000;
    private const BASE = 'https://googleads.googleapis.com';

    public static function customerId(): string
    {
        return preg_replace('/\D/', '', (string) config('ads_customer_id', ''));
    }

    /**
     * GAQL via googleAds:search with pagination. $maxRows: stops after collecting that many rows.
     * @return list<array> rows (json_decode assoc), with resourceName
     */
    public static function search(string $gaql, ?int $maxRows = null): array
    {
        // Hard memory/time ceiling: without maxRows pagination would run forever (e.g. click_view, ALL_TIME).
        $hardLimit = self::MAX_ROWS_HARD;
        $rows = [];
        $pageToken = null;
        do {
            $r = self::call('googleAds:search', $pageToken ? ['query' => $gaql, 'pageToken' => $pageToken] : ['query' => $gaql]);
            array_push($rows, ...($r['results'] ?? []));
            if ($maxRows === null && count($rows) > $hardLimit) {
                throw new UserError('Result too large - narrow the date range or add a LIMIT');
            }
            $pageToken = $r['nextPageToken'] ?? null;
        } while ($pageToken && ($maxRows === null || count($rows) < $maxRows));
        return $maxRows === null ? $rows : array_slice($rows, 0, $maxRows);
    }

    /**
     * googleAds:mutate z partialFailure=false. $ops = lista operacji (np. ['campaignOperation' => ['create' => ...]]).
     * @return array{results: list<array>}
     */
    public static function mutate(array $ops, bool $validateOnly): array
    {
        $maxPln = (float) config('max_daily_budget_pln', 30);
        $refused = self::guard('checkMutateOps', $ops, (int) Money::plnToMicros($maxPln), $maxPln);
        if ($refused) {
            throw new MutationRefused($refused[0]);
        }
        $r = self::call('googleAds:mutate', ['mutateOperations' => $ops, 'partialFailure' => false, 'validateOnly' => $validateOnly]);
        return ['results' => $r['mutateOperationResponses'] ?? []];
    }

    /** Custom audiences: a separate endpoint customAudiences:mutate; accepts create only. */
    public static function mutateCustomAudiences(array $ops, bool $validateOnly): array
    {
        foreach ($ops as $op) {
            if (array_keys($op) !== ['create']) {
                throw new MutationRefused('Custom audiences can only be created (create operation)');
            }
        }
        $r = self::call('customAudiences:mutate', ['operations' => $ops, 'validateOnly' => $validateOnly]);
        return ['results' => $r['results'] ?? []];
    }

    /** Offline conversions (clicks). The API requires partialFailure=true; individual errors come back in partialFailureError. */
    public static function uploadClickConversions(array $convs, bool $validateOnly): array
    {
        $refused = self::guard('checkClickConversions', $convs, self::customerId());
        if ($refused) {
            throw new MutationRefused($refused[0]);
        }
        $r = self::call('customers/' . self::customerId() . ':uploadClickConversions', ['conversions' => $convs, 'partialFailure' => true, 'validateOnly' => $validateOnly], true);
        return ['results' => $r['results'] ?? []] + (isset($r['partialFailureError']) ? ['partialFailureError' => $r['partialFailureError']] : []);
    }

    /** Invoices (read-only): GET customers/{cid}/invoices. */
    public static function listInvoices(string $billingSetup, int $issueYear, string $issueMonth): array
    {
        if (!preg_match('#^customers/\d+/billingSetups/\d+$#', $billingSetup)) {
            throw new \InvalidArgumentException('Invalid billing setup resource name (billingSetup)');
        }
        if (!preg_match('/^\d{4}$/', (string) $issueYear) || !preg_match('/^[A-Z]+$/', $issueMonth)) {
            throw new \InvalidArgumentException('Invalid invoice year or month');
        }
        $qs = http_build_query(['billingSetup' => $billingSetup, 'issueYear' => $issueYear, 'issueMonth' => $issueMonth]);
        return self::call("invoices?$qs", null, false, 'GET')['invoices'] ?? [];
    }

    /** Keyword Planner (read-only). $p: keywords?, url?, geoTargetConstants, language, pageSize. */
    public static function generateKeywordIdeas(array $p): array
    {
        $body = ['language' => $p['language'], 'geoTargetConstants' => $p['geoTargetConstants'], 'keywordPlanNetwork' => 'GOOGLE_SEARCH', 'pageSize' => $p['pageSize']];
        $keywords = $p['keywords'] ?? [];
        $url = $p['url'] ?? null;
        if ($keywords && $url) {
            $body['keywordAndUrlSeed'] = ['keywords' => $keywords, 'url' => $url];
        } elseif ($url) {
            $body['urlSeed'] = ['url' => $url];
        } else {
            $body['keywordSeed'] = ['keywords' => $keywords];
        }
        return self::call('customers/' . self::customerId() . ':generateKeywordIdeas', $body, true)['results'] ?? [];
    }

    /** Location suggestions by names. @return list<array{id,name,canonicalName,targetType,countryCode,searchTerm}> */
    public static function suggestGeoTargetConstants(array $names): array
    {
        $r = self::call('geoTargetConstants:suggest', ['locale' => 'pl', 'countryCode' => 'PL', 'locationNames' => ['names' => $names]], true);
        return array_map(fn($x) => [
            'id' => (string) ($x['geoTargetConstant']['id'] ?? ''),
            'name' => (string) ($x['geoTargetConstant']['name'] ?? ''),
            'canonicalName' => (string) ($x['geoTargetConstant']['canonicalName'] ?? ''),
            'targetType' => (string) ($x['geoTargetConstant']['targetType'] ?? ''),
            'countryCode' => (string) ($x['geoTargetConstant']['countryCode'] ?? ''),
            'searchTerm' => (string) ($x['searchTerm'] ?? ''),
        ], $r['geoTargetConstantSuggestions'] ?? []);
    }

    /** Arbitrary POST/GET to the API (e.g. new endpoints): $global=true -> path without the customers/{cid}/ prefix. */
    public static function post(string $path, array $body, bool $global = false): array
    {
        return self::call($path, $body, $global);
    }

    private static function call(string $path, ?array $body, bool $global = false, string $method = 'POST'): array
    {
        $version = (string) config('ads_api_version', 'v26');
        $url = self::BASE . "/$version/" . ($global ? $path : 'customers/' . self::customerId() . "/$path");
        $extra = array_filter([
            'developer-token' => (string) config('ads_developer_token', ''),
            'login-customer-id' => preg_replace('/\D/', '', (string) config('ads_login_customer_id', '')),
        ]);
        $started = microtime(true);
        try {
            [$status, $text, $headers] = GoogleApi::send($method, $url, $method === 'GET' ? null : $body, $extra);
        } catch (\RuntimeException $e) {
            GoogleApi::logFailure('ads', $global ? $path : "customers/…/$path", 0, [], $started, $e->getMessage());
            throw $e;
        }
        if ($status >= 400) {
            $err = Errors::fromResponse($status, $text);
            GoogleApi::logFailure('ads', $global ? $path : "customers/…/$path", $status, $headers, $started, $err->getMessage(),
                ['codes' => $err->codes] + (isset($body['query']) ? ['query' => $body['query']] : []));
            throw $err;
        }
        return $text === '' ? [] : (json_decode($text, true) ?? []);
    }

    /** Calls App\Plans\GuardOps::$method(...$args) -> list of refusal reasons; missing class = refusal. */
    private static function guard(string $method, mixed ...$args): array
    {
        $class = '\\App\\Plans\\GuardOps';
        if (!class_exists($class) || !method_exists($class, $method)) {
            return ['The write-operation guard is not installed - write blocked'];
        }
        return $class::$method(...$args);
    }
}
