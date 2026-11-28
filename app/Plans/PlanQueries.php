<?php
declare(strict_types=1);

namespace App\Plans;

/** GAQL queries used by plans (ports of ads/queries.ts and ads/queries-pmax.ts: campaign snapshots, no metrics). */
final class PlanQueries
{
    /** The campaign ID must be numeric because it goes into GAQL (injection protection). */
    public static function id(string $campaignId): string
    {
        if (!preg_match('/^\d+$/', $campaignId)) {
            throw new \InvalidArgumentException('campaignId must consist of digits only');
        }
        return $campaignId;
    }

    /** @return array<string, string> campaign, ad groups, RSA ads, keywords, negatives, locations */
    public static function campaignSnapshot(string $campaignId): array
    {
        $c = self::id($campaignId);
        return [
            'campaign' => "SELECT campaign.resource_name, campaign.id, campaign.name, campaign.status, campaign.bidding_strategy_type, campaign.advertising_channel_type, campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.tracking_url_template, campaign.url_custom_parameters, campaign.manual_cpc.enhanced_cpc_enabled, campaign.target_spend.cpc_bid_ceiling_micros, campaign_budget.resource_name, campaign_budget.explicitly_shared, campaign_budget.amount_micros FROM campaign WHERE campaign.id = {$c}",
            'locations' => "SELECT campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.location.geo_target_constant, campaign_criterion.proximity.radius, campaign_criterion.proximity.radius_units, campaign_criterion.proximity.geo_point.latitude_in_micro_degrees, campaign_criterion.proximity.geo_point.longitude_in_micro_degrees, campaign_criterion.negative FROM campaign_criterion WHERE campaign.id = {$c} AND campaign_criterion.type IN ('LOCATION', 'PROXIMITY') AND campaign_criterion.negative = FALSE",
            'adGroups' => "SELECT ad_group.resource_name, ad_group.id, ad_group.name, ad_group.status, ad_group.cpc_bid_micros, ad_group.url_custom_parameters FROM ad_group WHERE campaign.id = {$c} AND ad_group.status != 'REMOVED'",
            'ads' => "SELECT ad_group_ad.resource_name, ad_group_ad.ad.id, ad_group_ad.status, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions FROM ad_group_ad WHERE campaign.id = {$c} AND ad_group_ad.status != 'REMOVED'",
            'keywords' => "SELECT ad_group_criterion.resource_name, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.negative, ad_group_criterion.status FROM ad_group_criterion WHERE campaign.id = {$c} AND ad_group_criterion.type = 'KEYWORD' AND ad_group_criterion.status != 'REMOVED'",
            'negatives' => "SELECT campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.id = {$c} AND campaign_criterion.type = 'KEYWORD' AND campaign_criterion.negative = TRUE",
        ];
    }

    /** @return array<string, string> full campaign configuration (SEARCH and Performance Max) + the account's conversion goals and audiences */
    public static function pmaxSnapshot(string $campaignId): array
    {
        $c = self::id($campaignId);
        return [
            'campaign' => "SELECT campaign.resource_name, campaign.id, campaign.name, campaign.status, campaign.serving_status, campaign.primary_status, campaign.primary_status_reasons, campaign.advertising_channel_type, campaign.start_date_time, campaign.end_date_time, campaign.bidding_strategy_type, campaign.campaign_budget, campaign.network_settings.target_google_search, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.geo_target_type_setting.positive_geo_target_type, campaign.geo_target_type_setting.negative_geo_target_type, campaign.asset_automation_settings, campaign.brand_guidelines_enabled, campaign.contains_eu_political_advertising, campaign_budget.resource_name, campaign_budget.amount_micros, campaign_budget.status FROM campaign WHERE campaign.id = {$c}",
            'criteria' => "SELECT campaign.id, campaign_criterion.resource_name, campaign_criterion.criterion_id, campaign_criterion.type, campaign_criterion.status, campaign_criterion.negative, campaign_criterion.location.geo_target_constant, campaign_criterion.proximity.radius, campaign_criterion.proximity.radius_units, campaign_criterion.proximity.geo_point.latitude_in_micro_degrees, campaign_criterion.proximity.geo_point.longitude_in_micro_degrees, campaign_criterion.keyword.text, campaign_criterion.keyword.match_type FROM campaign_criterion WHERE campaign.id = {$c} AND campaign_criterion.type IN ('LOCATION', 'PROXIMITY', 'LOCATION_GROUP', 'KEYWORD') AND campaign_criterion.status != 'REMOVED'",
            'assetGroups' => "SELECT asset_group.resource_name, asset_group.id, asset_group.name, asset_group.status, asset_group.primary_status, asset_group.primary_status_reasons, asset_group.final_urls, asset_group.final_mobile_urls, asset_group.path1, asset_group.path2, asset_group.ad_strength, campaign.id FROM asset_group WHERE campaign.id = {$c} AND asset_group.status != 'REMOVED'",
            'groupAssets' => "SELECT campaign.id, asset_group.id, asset_group_asset.resource_name, asset_group_asset.field_type, asset_group_asset.status, asset_group_asset.primary_status, asset_group_asset.primary_status_reasons, asset_group_asset.primary_status_details, asset_group_asset.source, asset.resource_name, asset.id, asset.type, asset.name, asset.text_asset.text, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels, asset.image_asset.file_size, asset.image_asset.full_size.url, asset.youtube_video_asset.youtube_video_id, asset.youtube_video_asset.youtube_video_title FROM asset_group_asset WHERE campaign.id = {$c} AND asset_group_asset.status != 'REMOVED'",
            'signals' => "SELECT campaign.id, asset_group.id, asset_group_signal.resource_name, asset_group_signal.search_theme.text, asset_group_signal.approval_status, asset_group_signal.disapproval_reasons, asset_group_signal.audience.audience FROM asset_group_signal WHERE campaign.id = {$c}",
            'campaignAssets' => "SELECT campaign.id, campaign_asset.resource_name, campaign_asset.field_type, campaign_asset.status, campaign_asset.primary_status, campaign_asset.primary_status_reasons, campaign_asset.primary_status_details, campaign_asset.source, asset.resource_name, asset.id, asset.type, asset.name, asset.sitelink_asset.link_text, asset.sitelink_asset.description1, asset.sitelink_asset.description2, asset.final_urls, asset.callout_asset.callout_text, asset.call_asset.phone_number, asset.call_asset.country_code, asset.text_asset.text, asset.lead_form_asset.privacy_policy_url, asset.lead_form_asset.headline, asset.lead_form_asset.business_name, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels, asset.image_asset.full_size.url, asset.image_asset.file_size FROM campaign_asset WHERE campaign.id = {$c} AND campaign_asset.field_type IN ('SITELINK', 'CALLOUT', 'CALL', 'LEAD_FORM', 'BUSINESS_NAME', 'LOGO', 'LANDSCAPE_LOGO') AND campaign_asset.status != 'REMOVED'",
            'goalConfig' => "SELECT conversion_goal_campaign_config.resource_name, conversion_goal_campaign_config.goal_config_level, conversion_goal_campaign_config.custom_conversion_goal, campaign.id FROM conversion_goal_campaign_config WHERE campaign.id = {$c}",
            'campaignGoals' => "SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.category, campaign_conversion_goal.origin, campaign_conversion_goal.biddable, campaign.id FROM campaign_conversion_goal WHERE campaign.id = {$c}",
            'customerGoals' => "SELECT customer_conversion_goal.resource_name, customer_conversion_goal.category, customer_conversion_goal.origin, customer_conversion_goal.biddable FROM customer_conversion_goal",
            'conversionActions' => "SELECT conversion_action.resource_name, conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric FROM conversion_action WHERE conversion_action.status != 'REMOVED'",
            'audiences' => "SELECT audience.resource_name, audience.id, audience.name, audience.scope, audience.status, audience.dimensions, audience.exclusion_dimension, audience.asset_group FROM audience WHERE audience.status != 'REMOVED'",
        ];
    }

    public const CONVERSION_TAG = 'SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.tag_snippets FROM conversion_action';

    public const CONVERSION_ACTIONS = "SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.category FROM conversion_action WHERE conversion_action.status != 'REMOVED'";

    public const CONVERSION_TRACKING = 'SELECT customer.conversion_tracking_setting.accepted_customer_data_terms, customer.conversion_tracking_setting.enablement_setting, customer.conversion_tracking_setting.effective_enablement_setting, customer.conversion_tracking_setting.google_ads_conversion_customer, customer.conversion_tracking_setting.conversion_tracking_status FROM customer LIMIT 1';

    public const CAMPAIGN_NAMES = "SELECT campaign.name FROM campaign WHERE campaign.status != 'REMOVED'";

    public const CAMPAIGN_ID_NAMES = "SELECT campaign.id, campaign.name FROM campaign WHERE campaign.status != 'REMOVED'";

    public const CAMPAIGN_GOAL_CONFIGS = 'SELECT campaign.id, campaign.name, campaign.status, conversion_goal_campaign_config.goal_config_level FROM conversion_goal_campaign_config';

    public const EXCLUSIONS = 'SELECT customer_negative_criterion.resource_name, customer_negative_criterion.id, customer_negative_criterion.type, customer_negative_criterion.content_label.type, customer_negative_criterion.mobile_application.app_id, customer_negative_criterion.mobile_application.name, customer_negative_criterion.mobile_app_category.mobile_app_category_constant, customer_negative_criterion.placement.url, customer_negative_criterion.youtube_channel.channel_id, customer_negative_criterion.youtube_video.video_id FROM customer_negative_criterion';
}
