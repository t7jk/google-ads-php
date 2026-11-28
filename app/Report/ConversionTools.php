<?php
declare(strict_types=1);

namespace App\Report;

use App\Google\Ads;
use App\Lib\Mcp;
use App\Lp2\ConversionTag;

/** Logic of the list_conversion_actions and get_conversion_tag tools (port of tools-read.ts + findConversionTag from plans/build-misc.ts). */
final class ConversionTools
{
    public static function list(): array
    {
        $actions = array_map(fn($r) => [
            'id' => (string) Js::get($r, 'conversionAction.id', 'undefined'), 'name' => Js::get($r, 'conversionAction.name'), 'status' => Js::get($r, 'conversionAction.status'),
            'type' => Js::get($r, 'conversionAction.type'), 'category' => Js::get($r, 'conversionAction.category'),
        ], Ads::search(Queries::conversionActions()));
        $tracking = self::trackingSettings();
        $list = implode("\n", array_map(fn($a) => "- {$a['name']} [{$a['status']}, {$a['type']}" . (Goals::isNonMutableActionType($a['type']) ? ', manual change in the Google Ads UI only' : '') . ']', $actions));
        $settings = $tracking ? self::settingsText($tracking) : '';
        return Mcp::json('Conversion actions (' . count($actions) . "):\n$list$settings", ['actions' => $actions] + ($tracking ? ['conversionTracking' => $tracking] : []));
    }

    /** Account conversion tracking settings; null when they cannot be read (the settings are an add-on: an error does not break the list). */
    private static function trackingSettings(): ?array
    {
        try {
            $cs = Js::get(Ads::search(Queries::conversionTrackingSetting())[0] ?? [], 'customer.conversionTrackingSetting');
            if (!is_array($cs)) {
                return null;
            }
            return [
                'acceptedCustomerDataTerms' => $cs['acceptedCustomerDataTerms'] ?? null, 'enablementSetting' => $cs['enablementSetting'] ?? null,
                'effectiveEnablementSetting' => $cs['effectiveEnablementSetting'] ?? null, 'googleAdsConversionCustomer' => $cs['googleAdsConversionCustomer'] ?? null,
                'conversionTrackingStatus' => $cs['conversionTrackingStatus'] ?? null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function hint(mixed $v): string
    {
        return $v === true || $v === 'ENABLED' ? 'enabled' : ($v === null ? 'no data' : ($v === false ? 'NO' : Js::str($v)));
    }

    private static function settingsText(array $t): string
    {
        $terms = $t['acceptedCustomerDataTerms'];
        $enabled = self::hint($t['enablementSetting']) === 'enabled';
        return "\n\nConversion tracking settings (read-only in the API):\n- Customer data terms accepted: "
            . ($terms === true ? 'yes' : ($terms === false ? 'NO' : 'no data'))
            . "\n- Enhanced conversions (account setting): " . self::hint($t['enablementSetting']) . ', effective: ' . self::hint($t['effectiveEnablementSetting'])
            . "\n- Conversion-managing account: " . ($t['googleAdsConversionCustomer'] ?? 'no data')
            . ($terms === true && $enabled ? '' : "\nTo enable manually: Goals → Settings → Enhanced conversions for leads and accepting the customer data terms.");
    }

    /** The description of the get_conversion_tag tool depends on configuration (action name and tag settings URL). */
    public static function tagDescription(): string
    {
        $url = (string) config('conversion_tag_settings_url', '');
        return 'Returns the conversion ID (AW-...) and the label of the "' . config('conversion_action_name', '') . '" action, to be entered' . ($url !== '' ? " in $url" : '') . '.';
    }

    public static function tag(): array
    {
        $name = (string) config('conversion_action_name', '');
        $t = ConversionTag::find();
        if (!$t['found']) {
            return Mcp::json("The conversion action \"{$name}\" does not exist yet - use prepare_conversion_action.", ['exists' => false]);
        }
        if (empty($t['conversionId']) || empty($t['label'])) {
            return Mcp::json("The action \"{$name}\" exists, but Google has not returned the tag data yet.", ['exists' => true, 'conversionId' => null, 'label' => null]);
        }
        $url = (string) config('conversion_tag_settings_url', '');
        $hint = $url !== '' ? " Enter them in $url" : '';
        return Mcp::json("Conversion ID: {$t['conversionId']}, label: {$t['label']}.$hint", ['exists' => true, 'conversionId' => $t['conversionId'], 'label' => $t['label']]);
    }
}
