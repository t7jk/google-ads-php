<?php
declare(strict_types=1);

namespace App\Plans;

use App\Google\Ads;

/** Configuration needed by plans (counterpart of the Node Config object), read from config.php via config(). */
final class Cfg
{
    public static function customerId(): string
    {
        return Ads::customerId();
    }

    public static function maxDailyBudgetPln(): float
    {
        return (float) config('max_daily_budget_pln', 30);
    }

    /** @return list<string> */
    public static function allowedUrlHosts(): array
    {
        return array_values((array) config('allowed_url_hosts', []));
    }

    public static function landingPathPrefix(): string
    {
        return (string) config('landing_path_prefix', '/lp2/');
    }

    public static function planTtlHours(): int
    {
        return (int) config('plan_ttl_hours', 24);
    }

    /** Always false on dev (see CLAUDE.md): the plan waits for approval. */
    public static function autoExecute(): bool
    {
        return (bool) config('auto_execute', false);
    }

    public static function allowPreviewOnly(): bool
    {
        return (bool) config('allow_preview_only', false);
    }

    /** @return array{lat: float, lng: float, radiusKm: float, label: string} */
    public static function defaultGeo(): array
    {
        return [
            'lat' => (float) config('default_geo_lat', 52.2297), 'lng' => (float) config('default_geo_lng', 21.0122),
            'radiusKm' => (float) config('default_geo_radius_km', 20), 'label' => (string) config('default_geo_label', 'Warszawa'),
        ];
    }

    public static function conversionActionName(): string
    {
        return (string) config('conversion_action_name', 'Website lead form');
    }

    public static function conversionActionId(): string
    {
        return (string) config('conversion_action_id', '');
    }

    public static function conversionTagSettingsUrl(): string
    {
        return (string) config('conversion_tag_settings_url', '');
    }

    public static function ga4PropertyId(): string
    {
        return (string) config('ga4_property_id', '');
    }

    public static function adsApiVersion(): string
    {
        return (string) config('ads_api_version', 'v26');
    }

    public static function accountTimeZone(): string
    {
        return (string) config('account_time_zone', 'Europe/Warsaw');
    }

    /**
     * Whether the MCP tool approve_plan exists at all (config 'allow_tool_approve', default false). A tool call is made by the
     * model itself, so enabling it lets the model approve its own plans; the default keeps approval a human act on the plan
     * page (admin session required). See docs/adr/0005-zatwierdzanie-planow.md.
     */
    public static function toolApprovalEnabled(): bool
    {
        return config('allow_tool_approve', false) === true;
    }

    /** Whether the MCP client (client_id) is a client with an MCP Apps window (prefix from app_clients). */
    public static function isAppClient(?string $clientId): bool
    {
        if ($clientId === null || $clientId === '') {
            return false;
        }
        foreach ((array) config('app_clients', ['claude-ai']) as $prefix) {
            if ($clientId === $prefix || str_starts_with($clientId, $prefix . '-')) {
                return true;
            }
        }
        return false;
    }
}
