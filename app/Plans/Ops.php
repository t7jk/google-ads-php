<?php
declare(strict_types=1);

namespace App\Plans;

use App\Ads\Money;

/**
 * Small, pure builders of Google Ads operations shared by the plan builders (port of src/plans/ops.ts).
 * Pitfall: empty JSON objects ({}) must be \stdClass, not [] (see Json).
 */
final class Ops
{
    /** @param array{type: string, maxCpcPln?: float|int} $b @return array{fields: array, mask: list<string>} */
    public static function biddingFields(array $b): array
    {
        if ($b['type'] === 'MANUAL_CPC') {
            return ['fields' => ['manualCpc' => ['enhancedCpcEnabled' => false]], 'mask' => ['manualCpc.enhancedCpcEnabled']];
        }
        if ($b['type'] === 'MAXIMIZE_CLICKS') {
            return isset($b['maxCpcPln'])
                ? ['fields' => ['targetSpend' => ['cpcBidCeilingMicros' => Money::plnToMicros((float) $b['maxCpcPln'])]], 'mask' => ['targetSpend.cpcBidCeilingMicros']]
                : ['fields' => ['targetSpend' => new \stdClass()], 'mask' => ['targetSpend.cpcBidCeilingMicros']];
        }
        // The mask must point at leaf fields: Google rejects a mask on a field with subfields ("field mask updated a field with
        // subfields"). Node has the same bug here (mask 'maximizeConversions'/'targetSpend'); fixed in PHP.
        // An empty leaf in an update resets the target (tCPA / CPC ceiling), i.e. a strategy without extra limits.
        return ['fields' => ['maximizeConversions' => new \stdClass()], 'mask' => ['maximizeConversions.targetCpaMicros']];
    }

    public static function proximity(string $campaignRn, float $lat, float $lng, float $radiusKm): array
    {
        return ['campaignCriterionOperation' => ['create' => ['campaign' => $campaignRn, 'proximity' => [
            'geoPoint' => ['latitudeInMicroDegrees' => (int) round($lat * 1e6), 'longitudeInMicroDegrees' => (int) round($lng * 1e6)],
            'radius' => $radiusKm, 'radiusUnits' => 'KILOMETERS']]]];
    }

    /** Radius around a city given by address (SEARCH): city name taken from the canonical name, country PL. */
    public static function proximityAddress(string $campaignRn, string $canonicalName, float $radiusKm): array
    {
        return ['campaignCriterionOperation' => ['create' => ['campaign' => $campaignRn, 'proximity' => [
            'address' => ['cityName' => trim(explode(',', $canonicalName)[0]), 'countryCode' => 'PL'],
            'radius' => $radiusKm, 'radiusUnits' => 'KILOMETERS']]]];
    }

    public static function campaignNegative(string $campaignRn, array $k): array
    {
        return ['campaignCriterionOperation' => ['create' => ['campaign' => $campaignRn, 'negative' => true, 'keyword' => ['text' => $k['text'], 'matchType' => $k['match']]]]];
    }

    public static function keyword(string $adGroupRn, array $k): array
    {
        return ['adGroupCriterionOperation' => ['create' => ['adGroup' => $adGroupRn, 'status' => 'ENABLED', 'keyword' => ['text' => $k['text'], 'matchType' => $k['match']]]]];
    }

    public static function adGroupNegative(string $adGroupRn, array $k): array
    {
        return ['adGroupCriterionOperation' => ['create' => ['adGroup' => $adGroupRn, 'negative' => true, 'keyword' => ['text' => $k['text'], 'matchType' => $k['match']]]]];
    }

    /** @param array{resourceName: string, campaignRn: string, name: string, status: string, defaultCpcPln?: float|int|null, grupaSlug?: ?string} $o */
    public static function adGroupCreate(array $o): array
    {
        $c = ['resourceName' => $o['resourceName'], 'campaign' => $o['campaignRn'], 'name' => $o['name'], 'status' => $o['status'], 'type' => 'SEARCH_STANDARD'];
        if (isset($o['defaultCpcPln'])) {
            $c['cpcBidMicros'] = Money::plnToMicros((float) $o['defaultCpcPln']);
        }
        if (!empty($o['grupaSlug'])) {
            $c['urlCustomParameters'] = [['key' => 'grupa', 'value' => $o['grupaSlug']]];
        }
        return ['adGroupOperation' => ['create' => $c]];
    }

    public static function rsaAd(string $adGroupRn, array $r): array
    {
        $rsa = [
            'headlines' => array_map(fn($t) => ['text' => $t], $r['headlines']),
            'descriptions' => array_map(fn($t) => ['text' => $t], $r['descriptions']),
        ];
        if (!empty($r['path1'])) {
            $rsa['path1'] = $r['path1'];
        }
        if (!empty($r['path2'])) {
            $rsa['path2'] = $r['path2'];
        }
        return ['adGroupAdOperation' => ['create' => ['adGroup' => $adGroupRn, 'status' => 'ENABLED', 'ad' => ['finalUrls' => [$r['finalUrl']], 'responsiveSearchAd' => $rsa]]]];
    }
}
