<?php
declare(strict_types=1);

namespace App\Ga4;

/** Port of the read part of src/ga4/key-events.ts: warnings for GA4 key events (without building write plans). */
final class KeyEvents
{
    public const LEAD_EVENT = 'generate_lead';

    /** Automatic and enhanced GA4 events: marking them as key inflates conversions (every session counts many times). */
    private const NAV_EVENTS = ['first_visit', 'session_start', 'page_view', 'user_engagement', 'scroll', 'click', 'view_search_results', 'file_download'];
    private const FORM_EVENTS = ['form_start', 'form_submit'];

    /** Warning text for a key event that usually should not be one; null = no remarks. */
    public static function noisyWarning(string $eventName): ?string
    {
        if (in_array($eventName, self::FORM_EVENTS, true)) {
            return "$eventName: automatic form event - usually should not be a key event alongside " . self::LEAD_EVENT;
        }
        if (in_array($eventName, self::NAV_EVENTS, true) || str_starts_with($eventName, 'video_')) {
            return "$eventName: automatic/enhanced GA4 (navigation) event - marked as a key event it inflates the conversion count (one session counts many times)";
        }
        return null;
    }

    /**
     * Warnings for the current list of key events and the traffic of the last days.
     * @param list<array{eventName:string}> $keyEvents
     * @param list<array{eventName:string,eventCount:int|float}>|null $recent null = could not be checked
     * @return list<string>
     */
    public static function warnings(array $keyEvents, ?array $recent): array
    {
        $out = [];
        foreach ($keyEvents as $k) {
            $w = self::noisyWarning((string) $k['eventName']);
            if ($w !== null) {
                $out[] = $w;
            }
        }
        $keySet = array_column($keyEvents, 'eventName');
        $leadSeen = $recent !== null && array_filter($recent, fn($e) => $e['eventName'] === self::LEAD_EVENT && $e['eventCount'] > 0);
        if ($leadSeen && !in_array(self::LEAD_EVENT, $keySet, true)) {
            $out[] = self::LEAD_EVENT . ' appears in the data but is not a key event - form sign-ups are not counted as key events in GA4';
        }
        return $out;
    }
}
