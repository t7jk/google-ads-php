<?php
declare(strict_types=1);

namespace App\Report;

use DateTimeInterface;

/**
 * GAQL queries of the read tools (port of src/ads/queries.ts, queries-reports.ts, queries-pmax.ts).
 * A campaign ID enters the query only after "digits only" validation (GAQL has no bind parameters).
 */
final class Queries
{
    private const M = 'metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.ctr, metrics.average_cpc';
    private const M_REPORT = 'metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions';

    /** @throws \InvalidArgumentException when the id does not consist of digits only */
    public static function id(string $campaignId): string
    {
        if (!preg_match('/^\d+$/', $campaignId)) {
            throw new \InvalidArgumentException('campaignId must consist of digits only');
        }
        return $campaignId;
    }

    public static function campaignMetrics(string $cid, string|array $range, DateTimeInterface $now): string
    {
        return 'SELECT campaign.id, ' . self::M . ' FROM campaign WHERE campaign.id = ' . self::id($cid) . Range::dateClause($range, $now);
    }

    /** Campaign metrics by day (only days with any data - the caller fills in zeros). */
    public static function campaignDaily(string $cid, ?array $bounds): string
    {
        $where = $bounds ? " AND segments.date BETWEEN '{$bounds['from']}' AND '{$bounds['to']}'" : '';
        return 'SELECT campaign.id, segments.date, ' . self::M . ' FROM campaign WHERE campaign.id = ' . self::id($cid) . "$where ORDER BY segments.date";
    }

    public static function campaignList(string|array $range, DateTimeInterface $now): string
    {
        return 'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros, ' . self::M
            . " FROM campaign WHERE campaign.status != 'REMOVED'" . Range::dateClause($range, $now) . ' ORDER BY metrics.cost_micros DESC';
    }

    /** @return array<string,string> campaign, adGroups, ads, keywords */
    public static function campaignDetails(string $cid, string|array $range, DateTimeInterface $now): array
    {
        $c = self::id($cid);
        $d = Range::dateClause($range, $now);
        $m = self::M;
        return [
            'campaign' => "SELECT campaign.id, campaign.name, campaign.status, campaign.bidding_strategy_type, campaign_budget.amount_micros, $m FROM campaign WHERE campaign.id = $c$d",
            'adGroups' => "SELECT ad_group.id, ad_group.name, ad_group.status, $m FROM ad_group WHERE campaign.id = $c AND ad_group.status != 'REMOVED'$d",
            'ads' => "SELECT ad_group.id, ad_group_ad.ad.id, ad_group_ad.status, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group_ad.ad.responsive_search_ad.path1, ad_group_ad.ad.responsive_search_ad.path2, ad_group_ad.policy_summary.approval_status, $m FROM ad_group_ad WHERE campaign.id = $c AND ad_group_ad.status != 'REMOVED'$d",
            'keywords' => "SELECT ad_group.id, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, ad_group_criterion.quality_info.quality_score, $m FROM keyword_view WHERE campaign.id = $c AND ad_group_criterion.status != 'REMOVED'$d",
        ];
    }

    /** Extra campaign_details queries (Search): negative keywords, locations, network settings. */
    public static function campaignDetailsExtra(string $cid): array
    {
        $c = self::id($cid);
        return [
            'negatives' => "SELECT campaign_criterion.criterion_id, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.id = $c AND campaign_criterion.type = 'KEYWORD' AND campaign_criterion.negative = TRUE",
            'locations' => "SELECT campaign_criterion.criterion_id, campaign_criterion.location.geo_target_constant, campaign_criterion.proximity.radius, campaign_criterion.proximity.radius_units, campaign_criterion.negative FROM campaign_criterion WHERE campaign.id = $c AND campaign_criterion.type IN ('LOCATION','PROXIMITY')",
            'settings' => "SELECT campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.url_custom_parameters, campaign.tracking_url_template, campaign.final_url_suffix FROM campaign WHERE campaign.id = $c",
        ];
    }

    public static function searchTerms(string $cid, string|array $range, int $limit, DateTimeInterface $now): string
    {
        return 'SELECT search_term_view.search_term, campaign.id, ' . self::M . ' FROM search_term_view WHERE campaign.id = '
            . self::id($cid) . Range::dateClause($range, $now) . ' ORDER BY metrics.clicks DESC LIMIT ' . self::positive($limit);
    }

    /** Search terms of a Performance Max campaign: campaign-level view (search_term_view does not cover PMax). */
    public static function campaignSearchTerms(string $cid, string|array $range, int $limit, DateTimeInterface $now): string
    {
        return 'SELECT campaign_search_term_view.search_term, campaign.id, ' . self::M . ' FROM campaign_search_term_view WHERE campaign.id = '
            . self::id($cid) . Range::dateClause($range, $now) . ' ORDER BY metrics.impressions DESC LIMIT ' . self::positive($limit);
    }

    /** Total campaign impressions in the range (used to compute search term coverage). */
    public static function campaignImpressions(string $cid, string|array $range, DateTimeInterface $now): string
    {
        return 'SELECT campaign.id, metrics.impressions FROM campaign WHERE campaign.id = ' . self::id($cid) . Range::dateClause($range, $now);
    }

    public static function campaignChannel(string $cid): string
    {
        return 'SELECT campaign.id, campaign.advertising_channel_type FROM campaign WHERE campaign.id = ' . self::id($cid);
    }

    public static function conversionActions(): string
    {
        return "SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category FROM conversion_action WHERE conversion_action.status != 'REMOVED'";
    }

    /** Account conversion tracking settings (read-only fields, Google Ads API v26). */
    public static function conversionTrackingSetting(): string
    {
        return 'SELECT customer.conversion_tracking_setting.accepted_customer_data_terms, customer.conversion_tracking_setting.enablement_setting, customer.conversion_tracking_setting.effective_enablement_setting, customer.conversion_tracking_setting.google_ads_conversion_customer, customer.conversion_tracking_setting.conversion_tracking_status FROM customer LIMIT 1';
    }

    public static function account(): string
    {
        return 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.auto_tagging_enabled, customer.final_url_suffix, customer.tracking_url_template FROM customer LIMIT 1';
    }

    /** Search campaign configuration state (no metrics) - the part of campaignSnapshotQueries used by the audit. */
    public static function searchSnapshot(string $cid): array
    {
        $c = self::id($cid);
        return [
            'ads' => "SELECT ad_group_ad.resource_name, ad_group_ad.ad.id, ad_group_ad.status, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions FROM ad_group_ad WHERE campaign.id = $c AND ad_group_ad.status != 'REMOVED'",
            'keywords' => "SELECT ad_group_criterion.resource_name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.negative, ad_group_criterion.status FROM ad_group_criterion WHERE campaign.id = $c AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status != 'REMOVED'",
            'negatives' => "SELECT campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.id = $c AND campaign_criterion.type = 'KEYWORD' AND campaign_criterion.negative = TRUE",
            'networks' => "SELECT campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network FROM campaign WHERE campaign.id = $c",
        ];
    }

    /** Account change events (change_event) in a time window of the account time zone; $cid = optional campaign filter. */
    public static function changeEvents(string $from, string $to, ?string $cid, int $limit, string $customerId): string
    {
        $camp = $cid !== null ? " AND change_event.campaign = 'customers/$customerId/campaigns/" . self::id($cid) . "'" : '';
        return "SELECT change_event.change_date_time, change_event.user_email, change_event.client_type, change_event.change_resource_type, change_event.change_resource_name, change_event.resource_change_operation, change_event.changed_fields, change_event.old_resource, change_event.new_resource, change_event.campaign FROM change_event WHERE change_event.change_date_time >= '$from' AND change_event.change_date_time <= '$to'$camp ORDER BY change_event.change_date_time DESC LIMIT " . min(max($limit, 1), 200);
    }

    /** Latest change_event entries for a campaign change (used to determine the enable time). */
    public static function campaignChangeEvents(string $cid, string $from, string $to, string $customerId): string
    {
        return "SELECT change_event.change_date_time, change_event.changed_fields, change_event.old_resource, change_event.new_resource FROM change_event WHERE change_event.change_date_time >= '$from' AND change_event.change_date_time <= '$to' AND change_event.change_resource_type = 'CAMPAIGN' AND change_event.campaign = 'customers/$customerId/campaigns/" . self::id($cid) . "' ORDER BY change_event.change_date_time DESC LIMIT 100";
    }

    // ---- pmax_report: views (queries-reports.ts) ----

    public const VIEWS = ['assets', 'channels', 'placements', 'locations', 'hours', 'search_categories'];

    /** Metric columns that the given view actually fetches. */
    public static function viewMetrics(string $view): array
    {
        return match ($view) {
            'placements' => ['impressions'],
            'search_categories' => ['impressions', 'clicks', 'conversions'],
            default => ['impressions', 'clicks', 'cost', 'conversions'],
        };
    }

    /** Query of a PMax report view. $b = date bounds (null = no date filter). */
    public static function view(string $view, string $cid, ?array $b, int $limit): string
    {
        $c = self::id($cid);
        $dc = $b ? " AND segments.date BETWEEN '{$b['from']}' AND '{$b['to']}'" : '';
        $m = self::M_REPORT;
        return match ($view) {
            'assets' => "SELECT asset_group.id, asset_group.name, asset_group_asset.field_type, asset_group_asset.primary_status, asset.id, asset.type, asset.name, asset.text_asset.text, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels, asset.youtube_video_asset.youtube_video_title, $m FROM asset_group_asset WHERE campaign.id = $c AND asset_group_asset.status != 'REMOVED'$dc ORDER BY metrics.cost_micros DESC LIMIT $limit",
            'channels' => "SELECT segments.ad_network_type, $m FROM campaign WHERE campaign.id = $c$dc ORDER BY metrics.cost_micros DESC LIMIT $limit",
            'placements' => "SELECT campaign.id, performance_max_placement_view.display_name, performance_max_placement_view.placement, performance_max_placement_view.placement_type, performance_max_placement_view.target_url, metrics.impressions FROM performance_max_placement_view WHERE campaign.id = $c$dc ORDER BY metrics.impressions DESC LIMIT $limit",
            'locations' => "SELECT campaign.id, user_location_view.country_criterion_id, user_location_view.targeting_location, $m FROM user_location_view WHERE campaign.id = $c$dc ORDER BY metrics.cost_micros DESC LIMIT $limit",
            'hours' => "SELECT segments.day_of_week, segments.hour, $m FROM campaign WHERE campaign.id = $c$dc ORDER BY metrics.cost_micros DESC LIMIT $limit",
            'search_categories' => "SELECT campaign_search_term_insight.category_label, campaign_search_term_insight.id, metrics.impressions, metrics.clicks, metrics.conversions FROM campaign_search_term_insight WHERE campaign_search_term_insight.campaign_id = $c$dc ORDER BY metrics.impressions DESC LIMIT $limit",
        };
    }

    // ---- Performance Max campaign snapshot (queries-pmax.ts) ----

    public const PMAX_KEYS = ['campaign', 'criteria', 'assetGroups', 'groupAssets', 'signals', 'campaignAssets', 'goalConfig', 'campaignGoals', 'customerGoals', 'conversionActions', 'audiences'];

    /** GAQL queries (v26) for the full Performance Max campaign configuration, without metrics. */
    public static function pmaxSnapshot(string $cid): array
    {
        $c = self::id($cid);
        return [
            'campaign' => "SELECT campaign.resource_name, campaign.id, campaign.name, campaign.status, campaign.serving_status, campaign.primary_status, campaign.primary_status_reasons, campaign.advertising_channel_type, campaign.start_date_time, campaign.end_date_time, campaign.bidding_strategy_type, campaign.campaign_budget, campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.geo_target_type_setting.positive_geo_target_type, campaign.geo_target_type_setting.negative_geo_target_type, campaign.asset_automation_settings, campaign.brand_guidelines_enabled, campaign.contains_eu_political_advertising, campaign_budget.resource_name, campaign_budget.amount_micros, campaign_budget.status FROM campaign WHERE campaign.id = $c",
            // The fields campaign_criterion.location_group.* and proximity.address.* are not selectable (API v26 rejects the SELECT).
            'criteria' => "SELECT campaign.id, campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.type, campaign_criterion.status, campaign_criterion.negative, campaign_criterion.location.geo_target_constant, campaign_criterion.proximity.radius, campaign_criterion.proximity.radius_units, campaign_criterion.proximity.geo_point.latitude_in_micro_degrees, campaign_criterion.proximity.geo_point.longitude_in_micro_degrees, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.id = $c AND campaign_criterion.type IN ('LOCATION', 'PROXIMITY', 'LOCATION_GROUP', 'KEYWORD') AND campaign_criterion.status != 'REMOVED'",
            'assetGroups' => "SELECT asset_group.resource_name, asset_group.id, asset_group.name, asset_group.status, asset_group.primary_status, asset_group.primary_status_reasons, asset_group.final_urls, asset_group.final_mobile_urls, asset_group.path1, asset_group.path2, asset_group.ad_strength, campaign.id FROM asset_group WHERE campaign.id = $c AND asset_group.status != 'REMOVED'",
            // asset_group_asset.policy_summary is not selectable: disapproval is read from primary_status(_reasons/_details).
            'groupAssets' => "SELECT campaign.id, asset_group.id, asset_group_asset.resource_name, asset_group_asset.field_type, asset_group_asset.status, asset_group_asset.primary_status, asset_group_asset.primary_status_reasons, asset_group_asset.primary_status_details, asset_group_asset.source, asset.resource_name, asset.id, asset.type, asset.name, asset.text_asset.text, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels, asset.image_asset.file_size, asset.image_asset.full_size.url, asset.youtube_video_asset.youtube_video_id, asset.youtube_video_asset.youtube_video_title FROM asset_group_asset WHERE campaign.id = $c AND asset_group_asset.status != 'REMOVED'",
            'signals' => "SELECT campaign.id, asset_group.id, asset_group_signal.resource_name, asset_group_signal.search_theme.text, asset_group_signal.approval_status, asset_group_signal.disapproval_reasons, asset_group_signal.audience.audience FROM asset_group_signal WHERE campaign.id = $c",
            'campaignAssets' => "SELECT campaign.id, campaign_asset.resource_name, campaign_asset.field_type, campaign_asset.status, campaign_asset.primary_status, campaign_asset.primary_status_reasons, campaign_asset.primary_status_details, campaign_asset.source, asset.resource_name, asset.id, asset.type, asset.name, asset.sitelink_asset.link_text, asset.sitelink_asset.description1, asset.sitelink_asset.description2, asset.final_urls, asset.callout_asset.callout_text, asset.call_asset.phone_number, asset.call_asset.country_code, asset.text_asset.text, asset.lead_form_asset.privacy_policy_url, asset.lead_form_asset.headline, asset.lead_form_asset.business_name, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels, asset.image_asset.full_size.url, asset.image_asset.file_size FROM campaign_asset WHERE campaign.id = $c AND campaign_asset.field_type IN ('SITELINK', 'CALLOUT', 'CALL', 'LEAD_FORM', 'BUSINESS_NAME', 'LOGO', 'LANDSCAPE_LOGO') AND campaign_asset.status != 'REMOVED'",
            'goalConfig' => "SELECT conversion_goal_campaign_config.resource_name, conversion_goal_campaign_config.goal_config_level, conversion_goal_campaign_config.custom_conversion_goal, campaign.id FROM conversion_goal_campaign_config WHERE campaign.id = $c",
            'campaignGoals' => "SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.category, campaign_conversion_goal.origin, campaign_conversion_goal.biddable, campaign.id FROM campaign_conversion_goal WHERE campaign.id = $c",
            'customerGoals' => 'SELECT customer_conversion_goal.resource_name, customer_conversion_goal.category, customer_conversion_goal.origin, customer_conversion_goal.biddable FROM customer_conversion_goal',
            'conversionActions' => "SELECT conversion_action.resource_name, conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric FROM conversion_action WHERE conversion_action.status != 'REMOVED'",
            'audiences' => "SELECT audience.resource_name, audience.id, audience.name, audience.scope, audience.status, audience.dimensions, audience.exclusion_dimension, audience.asset_group FROM audience WHERE audience.status != 'REMOVED'",
        ];
    }

    private static function positive(int $n): int
    {
        if ($n <= 0) {
            throw new \InvalidArgumentException('limit must be a positive number');
        }
        return $n;
    }
}
