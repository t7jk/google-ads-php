<?php
declare(strict_types=1);

namespace App\Report;

use App\Ads\Money;
use App\Ads\UserError;
use App\Google\Ads;

/** Campaign metrics in compact form (PLN, CTR in %) and resolving a campaign by ID or name (port of the tools-read.ts helpers). */
final class Metrics
{
    /** Micros -> PLN rounded to the grosz. */
    public static function money(mixed $micros): int|float
    {
        return Js::round2(Money::microsToPln(Js::num($micros)));
    }

    /** Like money(), but null stays null. */
    public static function pln(mixed $micros): int|float|null
    {
        return $micros === null ? null : self::money($micros);
    }

    /** CTR (fraction) -> percentage text, e.g. "3.25". */
    public static function ctrPct(mixed $ctr): string
    {
        return Js::fixed2(Js::num($ctr) * 100);
    }

    /**
     * GAQL row metrics (impressions, clicks, costMicros, conversions, ctr, averageCpc).
     * @return array{impressions,clicks,cost,conversions,ctrPercent:string,avgCpc,costPerConversion}
     */
    public static function of(array $m = []): array
    {
        $conv = Js::num($m['conversions'] ?? null);
        $cost = self::money($m['costMicros'] ?? null);
        return [
            'impressions' => Js::num($m['impressions'] ?? null),
            'clicks' => Js::num($m['clicks'] ?? null),
            'cost' => $cost,
            'conversions' => $conv,
            'ctrPercent' => self::ctrPct($m['ctr'] ?? null),
            'avgCpc' => self::money($m['averageCpc'] ?? null),
            'costPerConversion' => $conv > 0 ? Js::round2($cost / $conv) : null,
        ];
    }

    /** Sums the metrics of two rows; CTR and CPC are computed from the sums, not summed. */
    public static function sum(array $a, array $m = []): array
    {
        $impressions = Js::num($a['impressions'] ?? null) + Js::num($m['impressions'] ?? null);
        $clicks = Js::num($a['clicks'] ?? null) + Js::num($m['clicks'] ?? null);
        $costMicros = Js::num($a['costMicros'] ?? null) + Js::num($m['costMicros'] ?? null);
        return [
            'impressions' => $impressions, 'clicks' => $clicks, 'costMicros' => $costMicros,
            'conversions' => Js::num($a['conversions'] ?? null) + Js::num($m['conversions'] ?? null),
            'ctr' => $impressions ? $clicks / $impressions : 0,
            'averageCpc' => $clicks ? $costMicros / $clicks : 0,
        ];
    }

    /** Campaign ID (digits) or its exact name -> ID. No such campaign = UserError with a message for the model. */
    public static function resolveCampaignId(string $campaign): string
    {
        if (preg_match('/^\d+$/', $campaign)) {
            return $campaign;
        }
        foreach (Ads::search("SELECT campaign.id, campaign.name FROM campaign WHERE campaign.status != 'REMOVED'") as $r) {
            if (Js::get($r, 'campaign.name') === $campaign) {
                return (string) $r['campaign']['id'];
            }
        }
        throw new UserError("Campaign \"{$campaign}\" not found");
    }

    /** Required non-empty string from the arguments (zod: z.string().min(1)). */
    public static function requireString(array $args, string $key): string
    {
        $v = $args[$key] ?? null;
        if (!is_string($v) || $v === '') {
            throw new UserError("Missing required parameter: $key");
        }
        return $v;
    }
}
