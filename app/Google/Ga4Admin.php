<?php
declare(strict_types=1);

namespace App\Google;

/**
 * GA4 Admin API v1beta (port of src/ga4/admin.ts + guard.ts), only for properties/{ga4_property_id}.
 * Writes: only keyEvents.create and keyEvents.delete (guard checkWrite). The analytics.edit scope is checked locally.
 */
final class Ga4Admin
{
    private const BASE = 'https://analyticsadmin.googleapis.com/v1beta';
    private const COUNTING_METHODS = ['ONCE_PER_EVENT', 'ONCE_PER_SESSION'];
    public const EDIT_MISSING = 'No permission to edit GA4 - open the connector home page and click "Connect with Google" again, ticking the Analytics (edit) permission';

    /** @return list<array{name,eventName,countingMethod,createTime,deletable,custom}> */
    public static function listKeyEvents(): array
    {
        return self::list('keyEvents', 'keyEvents', fn($k) => [
            'name' => $k['name'] ?? '', 'eventName' => $k['eventName'] ?? '', 'countingMethod' => $k['countingMethod'] ?? '',
            'createTime' => $k['createTime'] ?? null, 'deletable' => $k['deletable'] ?? null, 'custom' => $k['custom'] ?? null,
        ]);
    }

    /** @return list<array{name,type,displayName,measurementId,defaultUri}> */
    public static function listDataStreams(): array
    {
        return self::list('dataStreams', 'dataStreams', fn($s) => [
            'name' => $s['name'] ?? '', 'type' => $s['type'] ?? '', 'displayName' => $s['displayName'] ?? null,
            'measurementId' => $s['webStreamData']['measurementId'] ?? null, 'defaultUri' => $s['webStreamData']['defaultUri'] ?? null,
        ]);
    }

    /** @return list<array{name,customerId,canManageClients,adsPersonalizationEnabled,createTime}> */
    public static function listGoogleAdsLinks(): array
    {
        return self::list('googleAdsLinks', 'googleAdsLinks', fn($l) => [
            'name' => $l['name'] ?? '', 'customerId' => (string) ($l['customerId'] ?? ''), 'canManageClients' => $l['canManageClients'] ?? null,
            'adsPersonalizationEnabled' => $l['adsPersonalizationEnabled'] ?? null, 'createTime' => $l['createTime'] ?? null,
        ]);
    }

    public static function createKeyEvent(string $eventName, string $countingMethod): array
    {
        self::assertWritable(self::checkWrite(['method' => 'keyEvents.create', 'eventName' => $eventName, 'countingMethod' => $countingMethod]));
        $k = self::call('POST', self::property() . '/keyEvents', ['eventName' => $eventName, 'countingMethod' => $countingMethod]);
        return ['name' => $k['name'] ?? '', 'eventName' => $k['eventName'] ?? $eventName, 'countingMethod' => $k['countingMethod'] ?? $countingMethod];
    }

    public static function deleteKeyEvent(string $name): void
    {
        self::assertWritable(self::checkWrite(['method' => 'keyEvents.delete', 'name' => $name]));
        self::call('DELETE', $name);
    }

    /** Returns a list of errors (empty = allowed). Permits no other operation and no other property. */
    public static function checkWrite(array $op): array
    {
        $property = (string) config('ga4_property_id', '');
        if (!preg_match('/^\d+$/', $property)) {
            return ['Missing or invalid GA4_PROPERTY_ID - writing to GA4 is blocked'];
        }
        if ($op['method'] === 'keyEvents.create') {
            $e = [];
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $op['eventName'])) {
                $e[] = "Invalid event name: {$op['eventName']}";
            }
            if (!in_array($op['countingMethod'], self::COUNTING_METHODS, true)) {
                $e[] = "Invalid counting method: {$op['countingMethod']}";
            }
            return $e;
        }
        if ($op['method'] === 'keyEvents.delete') {
            return preg_match("#^properties/$property/keyEvents/\\d+$#", $op['name']) ? [] : ["Deleting is allowed only for key events of property $property: {$op['name']}"];
        }
        return ['Operacja GA4 niedozwolona'];
    }

    private static function assertWritable(array $errors): void
    {
        if ($errors) {
            throw new \RuntimeException($errors[0]);
        }
        if (!Token::hasGa4EditScope()) {
            throw new \RuntimeException(self::EDIT_MISSING);
        }
    }

    private static function property(): string
    {
        $id = (string) config('ga4_property_id', '');
        if ($id === '') {
            throw new \RuntimeException('GA4_PROPERTY_ID is missing from the configuration');
        }
        return "properties/$id";
    }

    private static function list(string $collection, string $field, callable $map): array
    {
        $out = [];
        $pageToken = '';
        for ($i = 0; $i < 10; $i++) {
            $j = self::call('GET', self::property() . "/$collection?pageSize=200" . ($pageToken !== '' ? '&pageToken=' . rawurlencode($pageToken) : ''));
            array_push($out, ...array_map($map, $j[$field] ?? []));
            $pageToken = $j['nextPageToken'] ?? '';
            if ($pageToken === '') {
                break;
            }
        }
        return $out;
    }

    private static function call(string $method, string $path, ?array $body = null): array
    {
        $started = microtime(true);
        [$status, $text, $headers] = GoogleApi::send($method, self::BASE . "/$path", $body);
        $j = json_decode($text, true) ?: [];
        if ($status >= 400) {
            $msg = $j['error']['message'] ?? 'unknown error';
            GoogleApi::logFailure('ga4admin', $path, $status, $headers, $started, $msg);
            throw new \RuntimeException("GA4 Admin error ($status): $msg");
        }
        return $j;
    }
}
