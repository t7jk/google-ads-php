<?php
// Change-plan tools, group D2 (port of part of src/mcp/tools-plan.ts): prepare_conversion_action, prepare_asset_group_changes,
// prepare_campaign_assets, prepare_asset_group_signals, prepare_custom_audience, prepare_ga4_key_events, prepare_offline_conversions.
// Plan core and the pattern: Tools/Plans.php; shared registration via Prepare::tool (previewOnly, schema validation, exceptions).
declare(strict_types=1);

use App\Ads\Time;
use App\Ga4\Tools as Ga4Tools;
use App\Google\{Ads, Ga4Admin, Token};
use App\Lib\Mcp;
use App\Lp2\LeadsClient;
use App\Plans\Build\{AssetGroup, CampaignAssets, ConversionActions, ExistingImages, Ga4KeyEventsPlan, ImageSources, Misc, Offline, Signals};
use App\Plans\{Cfg, Clock, OfflineLog, PlanQueries, Prepare, Schemas};
use App\Plans\Snapshot\PmaxSnapshot;

$auto = Prepare::autoText();

/** Campaign with a PMax snapshot, or null (no such campaign). */
$loadCampaign = function (array $input): array {
    $id = Prepare::resolveCampaignId((string) $input['campaign']);
    return [$id, PmaxSnapshot::load($id)];
};

/**
 * Images from the input -> ResolvedImage + existing assets in the account (reuse by name/content). Source errors are returned separately.
 * @return array{images: list<array>, errors: list<string>, existing: list<array>}
 */
$resolveImages = function (array $items, ?string $role): array {
    $r = ImageSources::resolve($items, $role);
    $now = Clock::now();
    $names = array_map(fn($im) => ImageSources::assetName($im, $now), $r['images']);
    $content = array_map(fn($im) => ['sha' => $im['sha'], 'width' => $im['width'], 'height' => $im['height'], 'bytes' => $im['bytes']], $r['images']);
    return $r + ['existing' => $r['images'] ? ExistingImages::find($names, $content) : []];
};

/** Image source errors go to the front of the plan's errors (the plan becomes invalid but still shows the rest). */
$withSourceErrors = function (array $plan, array $errors): array {
    if ($errors) {
        array_unshift($plan['validation']['errors'], ...$errors);
        $plan['validation']['ok'] = false;
    }
    return $plan;
};

return [
    Prepare::tool('prepare_conversion_action', 'Prepare LP2 conversion',
        'Prepares creation of the conversion action "' . Cfg::conversionActionName() . '" (a form submission on the page). If such an action already exists, it creates no plan and returns the tag data (AW-ID and label) to enter' . (Cfg::conversionTagSettingsUrl() !== '' ? ' in ' . Cfg::conversionTagSettingsUrl() : '')
        . '. Additionally: mode="create" with type WEBPAGE, AD_CALL (calls, category PHONE_CALL_LEAD) or UPLOAD_CLICKS (click import, NOT primary by default), and mode="update" by conversionActionId (name, primary conversion, counting, value, window, status ENABLED/HIDDEN; never deletion). Actions that are read-only in the API (e.g. WEBPAGE_CODELESS) are not changed, only the manual path is given. Undo: a change yes, creating an action is not undone (hide it manually, status HIDDEN, or via mode=update). ' . $auto,
        Schemas::conversionAction(),
        function (array $input, callable $finalize) {
            if ($errs = ConversionActions::inputErrors($input)) {
                return Mcp::error('Invalid parameters: ' . implode('; ', $errs));
            }
            $now = Clock::now();
            if (($input['mode'] ?? null) === 'update') {
                $row = Ads::search(ConversionActions::detailQuery($input['conversionActionId']))[0]['conversionAction'] ?? null;
                $r = ConversionActions::updatePlan($input, $row, $now);
                return isset($r['refused']) ? Mcp::error($r['refused']) : $finalize($r['plan']);
            }
            $existing = array_values(array_filter(array_map(fn($r) => $r['conversionAction'] ?? null, Ads::search(PlanQueries::CONVERSION_ACTIONS))));
            if (($input['mode'] ?? null) === 'create') {
                return $finalize(ConversionActions::createPlan($input, $existing, $now));
            }
            if (array_filter($existing, [Misc::class, 'isConfiguredConversionAction'])) {
                $t = Misc::findConversionTag();
                $data = !empty($t['conversionId']) && !empty($t['label']) ? ' ' . Misc::conversionInstruction($t['conversionId'], $t['label']) : ' Tag data is not available yet.';
                return Mcp::text('The conversion action "' . Cfg::conversionActionName() . '" already exists.' . $data,
                    ['exists' => true, 'conversionId' => $t['conversionId'] ?? null, 'label' => $t['label'] ?? null]);
            }
            return $finalize(Misc::conversionActionPlan($existing));
        }),

    Prepare::tool('prepare_asset_group_changes', 'Prepare asset group changes',
        'Prepares a plan of changes to a Performance Max asset group (or a new, always paused group): headlines, long headlines, descriptions, business name, images, logo, YouTube videos, name, status, final URL and paths. Texts are immutable in Google: replace creates a new asset, adds the link and removes the old one. Images are given as uploadId (upload_link provides the upload link) or as a URL from an allowed domain (JPG/PNG; the field type follows from the aspect ratio). With brand guidelines enabled, the logo and business name go to the campaign assets. The plan checks character and asset count limits. ' . $auto,
        Schemas::assetGroupChanges(),
        function (array $input, callable $finalize) use ($loadCampaign, $resolveImages, $withSourceErrors) {
            [$campaignId, $snapshot] = $loadCampaign($input);
            if (!$snapshot['campaign']) {
                return Mcp::error('Campaign not found');
            }
            $img = $resolveImages($input['images']['add'] ?? [], null);
            $plan = AssetGroup::plan(['campaignId' => $campaignId] + $input, $snapshot, $img['images'], $img['existing'], Clock::now());
            return $finalize($withSourceErrors($plan, $img['errors']));
        }),

    Prepare::tool('prepare_campaign_assets', 'Prepare campaign assets',
        'Prepares a plan of changes to campaign assets: sitelinks (add, remove, change the URL via replaceUrl), callouts, call (phone number or removal), removal of the lead form and, only with brand guidelines enabled, business name and logo (add: uploadId (upload_link provides the upload link) or a URL from an allowed domain; remove: logos.remove by name or assetId; at least one 1:1 logo must remain, so a replacement is add + remove in one plan). Assets cannot be changed in place in Google, so changing a URL creates a new asset, adds the link and removes the old one. The plan checks character limits and URLs. ' . $auto,
        Schemas::campaignAssets(),
        function (array $input, callable $finalize) use ($loadCampaign, $resolveImages, $withSourceErrors) {
            [$campaignId, $snapshot] = $loadCampaign($input);
            if (!$snapshot['campaign']) {
                return Mcp::error('Campaign not found');
            }
            $img = $resolveImages($input['logos']['add'] ?? [], 'logo');
            $plan = CampaignAssets::plan(['campaignId' => $campaignId] + $input, $snapshot, $img['images'], $img['existing'], Clock::now());
            return $finalize($withSourceErrors($plan, $img['errors']));
        }),

    Prepare::tool('prepare_asset_group_signals', 'Prepare asset group signals',
        'Prepares a plan of changes to the signals of a Performance Max asset group: search themes (add and remove; up to 50 per group, text up to 80 characters and 10 words, no duplicates) and audiences: a new audience from dimensions (custom segments, user lists, age, gender, parental status), an existing audience (existingAudienceId) or removal of audience signals (removeAudience). Create a custom segment first with prepare_custom_audience. ' . $auto,
        Schemas::assetGroupSignals(),
        function (array $input, callable $finalize) use ($loadCampaign) {
            [$campaignId, $snapshot] = $loadCampaign($input);
            if (!$snapshot['campaign']) {
                return Mcp::error('Campaign not found');
            }
            return $finalize(Signals::plan(['campaignId' => $campaignId] + $input, $snapshot, Clock::now()));
        }),

    Prepare::tool('prepare_custom_audience', 'Prepare custom audience',
        'Prepares a plan to create a custom segment (keywords up to 80 characters and 10 words, and page URLs http/https up to 2048 characters; URLs may be competitor pages, so the ad domain list does not apply to them). The segment is created by a separate plan and shows nothing by itself. Give its ID afterwards in prepare_asset_group_signals (audience.customAudienceIds). ' . $auto,
        Schemas::customAudience(),
        function (array $input, callable $finalize) {
            $names = array_map(fn($r) => (string) ($r['customAudience']['name'] ?? ''), Ads::search(Signals::CUSTOM_AUDIENCE_NAMES_QUERY));
            return $finalize(Signals::customAudiencePlan($input, $names, Clock::now()));
        }),

    Prepare::tool('prepare_ga4_key_events', 'Prepare GA4 key events',
        'Prepares a plan to mark (mark) and unmark (unmark) key events in GA4 (written to the GA4 Admin API, not Google Ads; requires the Analytics (edit) permission; if it is missing, the response explains how to reconnect Google). GA4 has no validation mode, so the plan is checked locally: duplicates, events absent from the last 30 days of data (warning), automatic/enhanced GA4 events (warning), the limit of 30 key events. Shows before -> after, executes operation by operation (not atomic), undo via prepare_undo (mark <-> unmark). The GA4 change history is in the GA4 panel (Admin -> Change history) and in server_events; Google Ads change_history will not show it. ' . $auto,
        Schemas::ga4KeyEvents(),
        function (array $input, callable $finalize) {
            if (!Cfg::ga4PropertyId()) {
                return Mcp::error('GA4_PROPERTY_ID is missing in the configuration');
            }
            if (!Token::hasGa4EditScope()) {
                return Mcp::error(Ga4Admin::EDIT_MISSING);
            }
            try {
                $current = Ga4Admin::listKeyEvents();
            } catch (\Throwable $e) {
                return Mcp::error($e->getMessage());
            }
            try {
                $recent = array_fill_keys(array_column(Ga4Tools::recentEvents(30), 'eventName'), true);
            } catch (\Throwable) {
                $recent = null; // skip the warning about absent events
            }
            return $finalize(Ga4KeyEventsPlan::plan($input, $current, $recent, Clock::now()));
        }),

    Prepare::tool('prepare_offline_conversions', 'Prepare offline conversions (lp2 leads)',
        'Prepares a plan to upload offline conversions to Google Ads (uploadClickConversions) from lp2 leads: only leads with gclid or wbraid (gbraid is not sent) and with consent ad_user_data = granted, click younger than 90 days, not yet sent (upload log in DATA_DIR). Data comes from the lp2-admin endpoint (LP2_ADMIN_API_URL, LP2_ADMIN_API_TOKEN; without a deployed endpoint it answers that it is not deployed). The target action must have type UPLOAD_CLICKS (create it with prepare_conversion_action; it is not a primary conversion by default so a sign-up is not counted twice together with the tag; orderId = lead ID = the tag\'s eid parameter, deduplication works only within the same action). Max 200 conversions per plan, sent with partialFailure; orderIds already sent are not sent again. Cannot be undone: uploaded conversions cannot be withdrawn through the API. The preview contains no click identifiers or personal data. ' . $auto,
        Schemas::offlineConversions(),
        function (array $input, callable $finalize) {
            $now = Clock::now();
            $to = $input['to'] ?? Time::dateInZone($now, Cfg::accountTimeZone());
            $from = $input['from'] ?? (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('-29 days')->format('Y-m-d');
            $actions = array_map(fn($r) => [
                'id' => (string) ($r['conversionAction']['id'] ?? ''), 'name' => (string) ($r['conversionAction']['name'] ?? ''),
                'type' => (string) ($r['conversionAction']['type'] ?? ''), 'status' => (string) ($r['conversionAction']['status'] ?? ''),
            ], Ads::search(PlanQueries::CONVERSION_ACTIONS));
            $picked = Offline::pickUploadAction($actions, $input['conversionActionId'] ?? null);
            if (isset($picked['error'])) {
                return Mcp::error($picked['error']);
            }
            $leads = LeadsClient::forUpload($from, $to); // Lp2NotAvailable (UserError) = a message that the endpoint is missing/not deployed
            return $finalize(Offline::plan($picked['action'], $leads, OfflineLog::sentIds(), $now));
        }),
];
