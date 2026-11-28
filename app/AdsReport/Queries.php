<?php
declare(strict_types=1);

namespace App\AdsReport;

/**
 * GAQL queries for the ads_report views (port of src/ads/queries-ads-report.ts, v26 fields).
 * Pitfalls: segments.conversion_action* cannot be combined with impression/cost metrics; click_view requires a filter on a SINGLE day
 * and cannot be combined with segments.hour; $b = ['from','to'] or null (ALL_TIME).
 */
final class Queries
{
    public const VIEWS = ['conversions', 'offline_uploads', 'impression_share', 'recommendations', 'landing_pages', 'clicks', 'segments', 'policy', 'audiences', 'budget_simulation', 'billing', 'account_hygiene'];
    public const SEGMENT_KINDS = ['device', 'day_of_week', 'hour', 'age', 'gender'];

    private const M6 = 'metrics.clicks, metrics.impressions, metrics.ctr, metrics.cost_micros, metrics.conversions, metrics.all_conversions, metrics.cost_per_conversion';
    private const M_AUD = 'metrics.clicks, metrics.impressions, metrics.ctr, metrics.cost_micros, metrics.conversions, metrics.cost_per_conversion';

    private static function cid(string $c): string
    {
        if (!preg_match('/^\d+$/', $c)) {
            throw new \InvalidArgumentException('campaignId must consist of digits only');
        }
        return $c;
    }

    private static function dateCond(?array $b): string
    {
        if (!$b) {
            return '';
        }
        return " AND segments.date BETWEEN '" . \App\Report\Range::assertDate($b['from']) . "' AND '" . \App\Report\Range::assertDate($b['to']) . "'";
    }

    private static function campCond(?string $c): string
    {
        return $c ? ' AND campaign.id = ' . self::cid($c) : '';
    }

    public static function conversions(?string $campaignId, ?array $b, bool $byDay): string
    {
        return 'SELECT campaign.id, campaign.name, segments.conversion_action, segments.conversion_action_name' . ($byDay ? ', segments.date' : '')
            . ', metrics.conversions, metrics.all_conversions, metrics.conversions_value FROM campaign WHERE campaign.status != \'REMOVED\''
            . self::campCond($campaignId) . self::dateCond($b);
    }

    public static function conversionActionsState(): string
    {
        return "SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.type, conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric FROM conversion_action WHERE conversion_action.status != 'REMOVED'";
    }

    public static function offlineActionSummary(): string
    {
        $p = 'offline_conversion_upload_conversion_action_summary';
        $f = array_map(fn($x) => "$p.$x", ['conversion_action_name', 'conversion_action_id', 'client', 'status', 'total_event_count', 'successful_event_count', 'pending_event_count', 'last_upload_date_time', 'alerts', 'daily_summaries', 'job_summaries']);
        return 'SELECT ' . implode(', ', $f) . " FROM $p";
    }

    public static function offlineClientSummary(): string
    {
        $p = 'offline_conversion_upload_client_summary';
        $f = array_map(fn($x) => "$p.$x", ['client', 'status', 'total_event_count', 'successful_event_count', 'success_rate', 'pending_event_count', 'pending_rate', 'last_upload_date_time', 'alerts', 'daily_summaries', 'job_summaries']);
        return 'SELECT ' . implode(', ', $f) . " FROM $p";
    }

    public static function impressionShare(?string $campaignId, ?array $b): string
    {
        return "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.primary_status, campaign.primary_status_reasons, campaign.bidding_strategy_system_status, campaign.start_date_time, campaign.end_date_time, campaign_budget.amount_micros, metrics.search_impression_share, metrics.search_budget_lost_impression_share, metrics.search_rank_lost_impression_share, metrics.search_top_impression_share, metrics.search_absolute_top_impression_share, metrics.impressions, metrics.clicks, metrics.cost_micros FROM campaign WHERE campaign.status != 'REMOVED'"
            . self::campCond($campaignId) . self::dateCond($b);
    }

    /** v26: recommendation.impact is a single field (a message with base_metrics and potential_metrics), with no subfields in GAQL. */
    public static function recommendations(?string $campaignId, bool $dismissed): string
    {
        return 'SELECT recommendation.type, recommendation.dismissed, recommendation.campaign, campaign.id, campaign.name, recommendation.impact FROM recommendation WHERE recommendation.dismissed = '
            . ($dismissed ? 'TRUE' : 'FALSE') . self::campCond($campaignId);
    }

    public static function optimizationScore(): string
    {
        return 'SELECT customer.optimization_score, customer.optimization_score_weight FROM customer';
    }

    public static function landingPages(?string $campaignId, ?array $b, bool $expanded): string
    {
        $q = $expanded
            ? 'SELECT campaign.id, campaign.name, campaign.status, expanded_landing_page_view.expanded_final_url, ' . self::M6 . " FROM expanded_landing_page_view WHERE campaign.status != 'REMOVED'"
            : 'SELECT campaign.id, campaign.name, campaign.status, landing_page_view.unexpanded_final_url, ' . self::M6 . ", metrics.speed_score, metrics.mobile_friendly_clicks_percentage, metrics.valid_accelerated_mobile_pages_clicks_percentage FROM landing_page_view WHERE campaign.status != 'REMOVED'";
        return $q . self::campCond($campaignId) . self::dateCond($b);
    }

    public static function clicks(?string $campaignId, string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}\z/', $date)) {
            throw new \InvalidArgumentException('Date must have the format YYYY-MM-DD');
        }
        return "SELECT click_view.gclid, campaign.id, campaign.name, ad_group.id, ad_group.name, segments.device, segments.click_type, click_view.keyword_info.text, click_view.keyword_info.match_type, click_view.location_of_presence.city, click_view.location_of_presence.region, click_view.area_of_interest.city, click_view.area_of_interest.region, metrics.clicks FROM click_view WHERE segments.date = '$date'"
            . self::campCond($campaignId);
    }

    /** @param list<string> $ids */
    public static function geoNames(array $ids): string
    {
        $clean = array_values(array_filter($ids, fn($i) => preg_match('/^\d+$/', (string) $i)));
        if (!$clean) {
            throw new \InvalidArgumentException('No valid location IDs');
        }
        return 'SELECT geo_target_constant.id, geo_target_constant.name FROM geo_target_constant WHERE geo_target_constant.id IN (' . implode(', ', $clean) . ')';
    }

    /** B6: device/day_of_week/hour from campaign, age from age_range_view, gender from gender_view (PMax does not return them). */
    public static function segments(string $kind, ?string $campaignId, ?array $b): string
    {
        $base = 'campaign.id, campaign.name, campaign.status';
        $m = 'metrics.clicks, metrics.impressions, metrics.cost_micros, metrics.conversions';
        $tail = "WHERE campaign.status != 'REMOVED'" . self::campCond($campaignId) . self::dateCond($b);
        return match ($kind) {
            'device' => "SELECT $base, segments.device, $m FROM campaign $tail",
            'day_of_week' => "SELECT $base, segments.day_of_week, $m FROM campaign $tail",
            'hour' => "SELECT $base, segments.hour, $m FROM campaign $tail",
            'age' => "SELECT $base, ad_group_criterion.age_range.type, $m FROM age_range_view $tail",
            'gender' => "SELECT $base, ad_group_criterion.gender.type, $m FROM gender_view $tail",
            default => throw new \InvalidArgumentException('Nieznany segment'),
        };
    }

    public static function adPolicy(?string $campaignId): string
    {
        return "SELECT campaign.id, campaign.name, campaign.status, ad_group.id, ad_group.name, ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad.ad.name, ad_group_ad.status, ad_group_ad.policy_summary.approval_status, ad_group_ad.policy_summary.review_status, ad_group_ad.policy_summary.policy_topic_entries FROM ad_group_ad WHERE campaign.status != 'REMOVED' AND ad_group_ad.status != 'REMOVED'"
            . self::campCond($campaignId);
    }

    public static function assetPolicy(?string $campaignId): string
    {
        return "SELECT campaign.id, campaign.name, campaign.status, asset_group.id, asset_group.name, asset_group_asset.field_type, asset_group_asset.status, asset_group_asset.primary_status, asset_group_asset.primary_status_reasons, asset_group_asset.policy_summary.approval_status, asset_group_asset.policy_summary.review_status, asset_group_asset.policy_summary.policy_topic_entries, asset.id, asset.name, asset.type, asset.text_asset.text FROM asset_group_asset WHERE campaign.status != 'REMOVED' AND asset_group_asset.status != 'REMOVED'"
            . self::campCond($campaignId);
    }

    /** Daily campaign cost (used to find the first day with spend in the range). */
    public static function dailyCost(?string $campaignId, ?array $b): string
    {
        return "SELECT campaign.id, segments.date, metrics.cost_micros FROM campaign WHERE campaign.status != 'REMOVED'" . self::campCond($campaignId) . self::dateCond($b);
    }

    private static function audFields(string $t): string
    {
        return "$t.criterion_id, $t.type, $t.display_name, $t.bid_modifier, $t.negative, $t.user_list.user_list, $t.user_interest.user_interest_category, $t.custom_audience.custom_audience, $t.combined_audience.combined_audience";
    }

    /** $level: 'campaign' (campaign_audience_view) or 'ad_group' (ad_group_audience_view, with the ad group targeting settings). */
    public static function audiences(string $level, ?string $campaignId, ?array $b): string
    {
        $head = 'campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type';
        $where = self::campCond($campaignId) . self::dateCond($b);
        return $level === 'campaign'
            ? "SELECT $head, " . self::audFields('campaign_criterion') . ', ' . self::M_AUD . " FROM campaign_audience_view WHERE campaign.status != 'REMOVED'$where"
            : "SELECT $head, ad_group.id, ad_group.name, ad_group.targeting_setting.target_restrictions, " . self::audFields('ad_group_criterion') . ', ' . self::M_AUD . " FROM ad_group_audience_view WHERE campaign.status != 'REMOVED' AND ad_group.status != 'REMOVED'$where";
    }

    public static function assetGroupSignals(?string $campaignId): string
    {
        return "SELECT campaign.id, campaign.name, asset_group.id, asset_group.name, asset_group_signal.audience.audience, asset_group_signal.search_theme.text, asset_group_signal.approval_status FROM asset_group_signal WHERE campaign.status != 'REMOVED' AND asset_group.status != 'REMOVED'"
            . self::campCond($campaignId);
    }

    public const AUDIENCE_NAME_QUERIES = [
        'userList' => 'SELECT user_list.id, user_list.name FROM user_list',
        'customAudience' => 'SELECT custom_audience.id, custom_audience.name FROM custom_audience',
        'combinedAudience' => 'SELECT combined_audience.id, combined_audience.name FROM combined_audience',
        'audience' => 'SELECT audience.id, audience.name FROM audience',
    ];

    /** Campaign names are fetched by a separate query (campaign_simulation has only campaign_id). */
    public static function campaignSimulation(?string $campaignId): string
    {
        $f = 'campaign_simulation.campaign_id, campaign_simulation.type, campaign_simulation.modification_method, campaign_simulation.start_date, campaign_simulation.end_date, campaign_simulation.budget_point_list.points, campaign_simulation.target_cpa_point_list.points, campaign_simulation.target_roas_point_list.points, campaign_simulation.target_impression_share_point_list.points, campaign_simulation.cpc_bid_point_list.points';
        return "SELECT $f FROM campaign_simulation" . ($campaignId ? ' WHERE campaign_simulation.campaign_id = ' . self::cid($campaignId) : '');
    }

    public static function simulationCampaigns(?string $campaignId): string
    {
        return "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros FROM campaign WHERE campaign.status != 'REMOVED'" . self::campCond($campaignId);
    }

    public static function accountBudget(): string
    {
        return 'SELECT account_budget.id, account_budget.name, account_budget.status, account_budget.billing_setup, account_budget.approved_spending_limit_micros, account_budget.approved_spending_limit_type, account_budget.proposed_spending_limit_micros, account_budget.proposed_spending_limit_type, account_budget.adjusted_spending_limit_micros, account_budget.adjusted_spending_limit_type, account_budget.amount_served_micros, account_budget.total_adjustments_micros, account_budget.approved_start_date_time, account_budget.proposed_start_date_time, account_budget.approved_end_date_time, account_budget.proposed_end_date_time, account_budget.approved_end_time_type, account_budget.proposed_end_time_type, account_budget.purchase_order_number FROM account_budget';
    }

    public static function billingSetup(): string
    {
        return 'SELECT billing_setup.id, billing_setup.status, billing_setup.payments_account_info.payments_account_id, billing_setup.payments_account_info.payments_account_name, billing_setup.payments_account_info.payments_profile_id, billing_setup.payments_account_info.payments_profile_name, billing_setup.start_date_time, billing_setup.end_date_time, billing_setup.end_time_type FROM billing_setup';
    }

    public static function experiments(): string
    {
        return "SELECT experiment.experiment_id, experiment.name, experiment.type, experiment.status, experiment.start_date, experiment.end_date FROM experiment WHERE experiment.status != 'REMOVED'";
    }

    public static function labels(): string
    {
        return "SELECT label.id, label.name, label.status FROM label WHERE label.status != 'REMOVED'";
    }

    public static function campaignLabels(): string
    {
        return 'SELECT campaign_label.label, campaign_label.campaign FROM campaign_label';
    }

    public static function adGroupLabels(): string
    {
        return 'SELECT ad_group_label.label, ad_group_label.ad_group FROM ad_group_label';
    }

    public static function userAccess(): string
    {
        return 'SELECT customer_user_access.user_id, customer_user_access.email_address, customer_user_access.access_role, customer_user_access.access_creation_date_time FROM customer_user_access';
    }

    public static function userAccessInvitation(): string
    {
        return 'SELECT customer_user_access_invitation.invitation_id, customer_user_access_invitation.email_address, customer_user_access_invitation.access_role, customer_user_access_invitation.invitation_status, customer_user_access_invitation.creation_date_time FROM customer_user_access_invitation';
    }
}
