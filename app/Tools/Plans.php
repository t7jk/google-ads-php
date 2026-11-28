<?php
// Change-plan tools, core (port of part of src/mcp/tools-plan.ts): show_plan, approve_plan, ui_event, prepare_selftest,
// prepare_undo, prepare_campaign_pause/changes/settings, prepare_negative_keywords, prepare_account_exclusions,
// prepare_new_campaign, prepare_conversion_goals. The D2 tools live in a NEW file (e.g. Tools/Plans2.php) registered via Prepare::tool().
// Note: approve_plan and ui_event are for the preview window (MCP Apps). approve_plan is OFF by default (config
// 'allow_tool_approve'): a tool call is made by the model, so approval normally happens on the plan page with an admin session
// (docs/adr/0005-zatwierdzanie-planow.md). Lib\McpServer hides both tools from clients that may not use them and refuses the
// call by name; the handlers below repeat the check (defence in depth).
declare(strict_types=1);

use App\Lib\{Events, Mcp, ToolRegistry};
use App\Plans\{Cfg, Executor, Kinds, Plan, PlanStore, Prepare, SchemaCheck, Schemas, SelftestProbes, Undo};
use App\Plans\Build\{Changes, Exclusions, GoalsPlan, Misc, Negatives, NewCampaign, Settings};
use App\Plans\Snapshot\{CampaignSnapshot, PmaxSnapshot};

$notFound = fn(string $t) => Mcp::error($t);

return [
    Prepare::tool('prepare_selftest', 'Preview window test',
        'Read-only, changes nothing in the account: creates a trial plan to check the preview window (with auto-execute, "execution" changes nothing) and shows the configuration state: GA4_PROPERTY_ID, API version, allowed domains. The "Tools" section uses read-only queries to check groups of tools: Google Ads reads, GA4 Data API, GA4 Admin API, the analytics.edit scope (prepare_ga4_key_events), the UPLOAD_CLICKS action (prepare_offline_conversions), the lp2-admin endpoint (lp2_leads) and the number and list of the connector\'s tools. A failure of one probe does not spoil the rest.',
        Schemas::empty(),
        fn(array $input, callable $finalize) => $finalize(Misc::selftestPlan(SelftestProbes::run(array_keys(ToolRegistry::all()))))),

    [
        'name' => 'show_plan', 'title' => 'Show plan', 'description' => 'Opens the preview of a stored change plan.',
        'inputSchema' => Schemas::showPlan(), 'annotations' => ['readOnlyHint' => true], '_meta' => Prepare::uiMeta(),
        'handler' => function (array $args): array {
            $plan = PlanStore::get((string) $args['planId']);
            // A plan of another client is reported as missing (not as forbidden), so its existence is not revealed.
            return $plan && Mcp::canSee($plan['clientId'] ?? null) ? Prepare::present($plan) : Mcp::error('No such plan');
        },
    ],

    [
        'name' => 'ui_event', 'title' => 'Preview window event',
        'description' => 'Called only by the preview window: reports to the server what the window showed (for diagnostics). Changes nothing in the account.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['render', 'approve_click', 'approve_result', 'error']],
            'planId' => ['type' => 'string', 'maxLength' => 64], 'planStatus' => ['type' => 'string', 'maxLength' => 32], 'validationOk' => ['type' => 'boolean'],
            'approveButton' => ['type' => 'string', 'enum' => ['none', 'active', 'disabled'], 'description' => 'whether the Approve button was shown'],
            'outcome' => ['type' => 'string', 'enum' => ['none', 'executed', 'not_executed'], 'description' => 'whether Done / Not executed was shown'],
            'messages' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 300], 'maxItems' => 10, 'description' => 'warning and error texts visible in the window'],
            'uiVersion' => ['type' => 'string', 'maxLength' => 40], 'width' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20000],
            'height' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20000], 'ok' => ['type' => 'boolean'], 'error' => ['type' => 'string', 'maxLength' => 500],
        ], 'required' => ['kind'], 'additionalProperties' => false],
        'annotations' => ['readOnlyHint' => true], '_meta' => Prepare::uiMeta(['visibility' => ['app']]),
        'handler' => function (array $args): array {
            if (!Cfg::isAppClient(Mcp::$clientId)) {
                return Mcp::error('Preview window events are accepted only from the window');
            }
            if ($errs = SchemaCheck::validate(ToolRegistry::find('ui_event')['inputSchema'], $args)) {
                return Mcp::error('Invalid parameters: ' . implode('; ', $errs));
            }
            if (strlen(json_encode($args)) > 4000) {
                return Mcp::error('Event too large');
            }
            Events::log('ui', $args + ['client' => Mcp::$clientId] + (($args['ok'] ?? true) === false || $args['kind'] === 'error' ? ['ok' => false] : []));
            return Mcp::text('ok');
        },
    ],

    [
        'name' => 'approve_plan', 'title' => 'Approve plan',
        'description' => 'Called only by the button in the preview window; used only with AUTO_EXECUTE=false (or previewOnly: true with ALLOW_PREVIEW_ONLY=true).',
        'inputSchema' => ['type' => 'object', 'properties' => ['planId' => ['type' => 'string', 'minLength' => 1], 'planHash' => ['type' => 'string', 'minLength' => 1]], 'required' => ['planId', 'planHash'], 'additionalProperties' => false],
        'annotations' => ['destructiveHint' => true], '_meta' => Prepare::uiMeta(['visibility' => ['app']]),
        'handler' => function (array $args): array {
            if (!Cfg::toolApprovalEnabled() || !Cfg::isAppClient(Mcp::$clientId)) {
                return Mcp::error('Approval is possible only on the plan page, by the administrator.');
            }
            $plan = PlanStore::get((string) $args['planId']);
            if ($plan === null || !Mcp::canSee($plan['clientId'] ?? null)) {
                return Mcp::error('No such plan');
            }
            $r = Executor::approve((string) $args['planId'], (string) $args['planHash']);
            $res = Mcp::text($r['message'], ['ok' => $r['ok'], 'message' => $r['message']]);
            return !empty($r['alreadyUsed']) || $r['ok'] ? $res : $res + ['isError' => true];
        },
    ],

    Prepare::tool('prepare_campaign_changes', 'Prepare campaign changes',
        'Prepares a plan of changes to an existing campaign (budget, name, strategy, networks, location, parameters, negatives, ad groups, keywords, ads). ' . Prepare::autoText() . ' RSA ad texts are immutable: to change a text, add a new ad (ads.add) and pause the old one (ads.pauseIds).',
        Schemas::campaignChanges(),
        function (array $input, callable $finalize) {
            $ref = $input['campaign'] ?? $input['campaignId'] ?? null;
            if (!$ref) {
                return Mcp::error('Provide campaign (ID or name) or campaignId');
            }
            unset($input['campaign']);
            $input['campaignId'] = Prepare::resolveCampaignId((string) $ref);
            $snapshot = CampaignSnapshot::load($input['campaignId']);
            if (!$snapshot['campaign']) {
                return Mcp::error('Campaign not found');
            }
            return $finalize(Changes::build($input, $snapshot));
        }),

    Prepare::tool('prepare_new_campaign', 'Prepare new campaign',
        'Prepares a plan for a new Search campaign: budget, strategy, location, ad groups, keywords and RSA ads. The campaign is created PAUSED; you launch it manually in Google Ads. By default only the Google Search network (no partners or Display network) and a radius of ' . Cfg::defaultGeo()['radiusKm'] . ' km around: ' . Cfg::defaultGeo()['label'] . '. ' . Prepare::autoText(),
        Schemas::newCampaign(),
        function (array $input, callable $finalize) {
            $names = array_map(fn($r) => (string) ($r['campaign']['name'] ?? ''), App\Google\Ads::search(App\Plans\PlanQueries::CAMPAIGN_NAMES));
            return $finalize(NewCampaign::build($input, $names));
        }),

    Prepare::tool('prepare_campaign_pause', 'Prepare campaign pause',
        'Prepares a plan to PAUSE a campaign (status PAUSED), including Performance Max. ' . Prepare::autoText(),
        Schemas::campaignRef(),
        function (array $input, callable $finalize) {
            $id = Prepare::resolveCampaignId((string) $input['campaign']);
            $snapshot = CampaignSnapshot::load($id);
            if (!$snapshot['campaign']) {
                return Mcp::error('Campaign not found');
            }
            if (($snapshot['campaign']['advertisingChannelType'] ?? null) === 'PERFORMANCE_MAX') {
                return $finalize(Misc::pmaxPausePlan(PmaxSnapshot::load($id)));
            }
            return $finalize(Misc::pausePlan($snapshot));
        }),

    Prepare::tool('prepare_campaign_settings', 'Prepare campaign settings',
        'Prepares a plan to change the settings of a SEARCH or Performance Max campaign: start and end dates (endDate "none" removes the end), the location option (geoTargetType), locations (add, remove, exclude, radius) and asset automation (assetAutomation). Location names are resolved to IDs by Google; an ambiguous name must be clarified with geoTargetConstantId. Does not change the campaign status or budget. ' . Prepare::autoText(),
        Schemas::campaignSettings(),
        function (array $input, callable $finalize) {
            $id = Prepare::resolveCampaignId((string) $input['campaign']);
            $snapshot = PmaxSnapshot::load($id);
            if (!$snapshot['campaign']) {
                return Mcp::error('Campaign not found');
            }
            $names = Settings::locationNamesToResolve($input);
            $geo = $names ? Settings::groupGeoByName($names, App\Google\Ads::suggestGeoTargetConstants($names)) : [];
            return $finalize(Settings::build(['campaignId' => $id] + $input, $snapshot, $geo));
        }),

    Prepare::tool('prepare_conversion_goals', 'Prepare conversion goals',
        'Prepares a plan to change conversion goals: the campaign goal level (goalLevel CAMPAIGN/CUSTOMER), which category/origin pairs take part in bidding (biddable or the onlyBiddable shortcut) and primary/secondary actions (primaryForGoal), for one campaign (scope=campaign) or the whole account (scope=account, affects campaigns that use account goals). The response shows which conversion actions are bid on before and after the change. ' . Prepare::autoText(),
        Schemas::conversionGoals(),
        function (array $input, callable $finalize) {
            if ($input['scope'] === 'campaign') {
                if (empty($input['campaign'])) {
                    return Mcp::error('scope=campaign requires the campaign field');
                }
                $id = Prepare::resolveCampaignId((string) $input['campaign']);
                $s = PmaxSnapshot::load($id);
                if (!$s['campaign']) {
                    return Mcp::error('Campaign not found');
                }
                return $finalize(GoalsPlan::build(['campaign' => $id] + $input, $s, ['customerGoals' => $s['customerGoals'], 'conversionActions' => $s['conversionActions'], 'campaignsAtCustomerLevel' => []]));
            }
            return $finalize(GoalsPlan::build($input, null, PmaxSnapshot::accountGoalsData()));
        }),

    Prepare::tool('prepare_negative_keywords', 'Prepare negative keywords',
        'Prepares a plan to add (and remove) negative keywords: at campaign level (Search and Performance Max; in PMax they only apply to Search and Shopping) or in a shared list (created if it does not exist; optionally attached to campaigns via attachTo). Match types: EXACT, PHRASE (default) or BROAD; up to 80 characters and 10 words, no duplicates (including existing ones). Warns when a negative blocks an active keyword of a Search campaign. By default (checkCollisions: true) it also checks collisions with the offer: it fetches RSA headlines and descriptions, PMax asset group texts and names, ad group names and keywords, and for every negative that matches as whole words (ignoring Polish diacritics and case, with simple inflection, e.g. "gry" ~ "gier") adds a warning row quoting the text; the warning does not block execution. First use search_terms (e.g. terms with cost but no conversions), then this tool. ' . Prepare::autoText(),
        Schemas::negativeKeywords(),
        function (array $input, callable $finalize) {
            $target = $input['target'];
            $campaignId = isset($target['campaign']) ? Prepare::resolveCampaignId((string) $target['campaign']) : null;
            $attachIds = isset($target['sharedList']) ? array_map(fn($c) => Prepare::resolveCampaignId((string) $c), $target['attachTo'] ?? []) : [];
            $snapshot = Negatives::load($campaignId, $attachIds);
            if (($input['checkCollisions'] ?? true) !== false) {
                [$snapshot, $errors] = Negatives::withOffer($snapshot, $target, $attachIds, $campaignId);
                if ($errors) {
                    Events::log('plan', ['event' => 'offer_collisions_partial', 'ok' => false, 'message' => substr(implode('; ', $errors), 0, 300)]);
                }
            }
            return $finalize(Negatives::build($input + ($campaignId ? ['campaignId' => $campaignId] : []), $snapshot));
        }),

    Prepare::tool('prepare_undo', 'Undo a change',
        'Prepares a plan to undo a change made by the connector: by default the last executed plan, or the plan with the given planId. Only plans executed within the last 7 days; an undone plan cannot be undone again (undoing the undo itself is allowed). Restores old setting values, re-adds removed links, criteria and negatives, detaches added ones; deletes nothing (resources stay, a new ad group stays paused, a new campaign and asset group you remove manually). Does not resume campaigns (you undo a pause manually in Google Ads) and does not undo custom segments or conversion actions; the response lists what cannot be undone. Refuses if the account has changed since that change. ' . Prepare::autoText(),
        Schemas::undo(),
        function (array $input, callable $finalize) {
            $planId = $input['planId'] ?? null;
            $fail = function (string $text) use ($planId): array {
                Events::log('plan', ['event' => 'undo_refused', 'planId' => $planId, 'ok' => false, 'message' => $text]);
                return Mcp::error($text);
            };
            $p = $planId ? PlanStore::get($planId) : Undo::latestExecuted(PlanStore::recent(Mcp::$clientId));
            if ($p && !Mcp::canSee($p['clientId'] ?? null)) {
                $p = null; // another client's plan: reported as missing
            }
            if (!$p) {
                return $fail($planId ? 'No such plan' : 'There are no executed plans to undo');
            }
            if ($refusal = Undo::refusal($p)) {
                return $fail($refusal);
            }
            if (isset(Kinds::get($p['kind'])['undo'])) { // the kind's own undo (e.g. GA4)
                $g = Kinds::get($p['kind'])['undo']($p);
                if (isset($g['plan'])) {
                    return $finalize($g['plan']);
                }
                return !empty($g['refused']) ? $fail($g['message']) : Mcp::text($g['message'], ['undoable' => false, 'planId' => $p['id']]);
            }
            $current = null;
            if (!empty($p['undo']['ops']) && !empty($p['afterFingerprint'])) {
                $target = Undo::target($p);
                $def = Kinds::get($target['kind']);
                if (!empty($def['fingerprint']) && (empty($def['needsCampaign']) || !empty($target['campaignId']))) {
                    $current = $def['fingerprint'](array_merge($p, $target));
                }
            }
            $r = Undo::buildPlan($p, $current);
            if (isset($r['message'])) {
                return $r['refused'] ? $fail($r['message']) : Mcp::text($r['message'], ['undoable' => false, 'planId' => $p['id'], 'notUndoable' => $r['notUndoable']]);
            }
            return $finalize($r['plan']);
        }),

    Prepare::tool('prepare_account_exclusions', 'Prepare account exclusions',
        'Prepares a plan of account-level exclusions (CustomerNegativeCriterion; they apply to all campaigns, including Performance Max): placements (domains), YouTube channels and videos, mobile apps (1-<iOS number> / 2-<Android package>), app categories (constant IDs) and content labels. Presets: kids_games_and_apps (app categories: games, family, kids on Google Play and the App Store), sensitive_content (content labels: sexually suggestive, profanity, tragedy, social issues). Exclusions cannot be edited, only added or removed. Shows the current exclusions (before -> after). ' . Prepare::autoText(),
        Schemas::accountExclusions(),
        fn(array $input, callable $finalize) => $finalize(Exclusions::build($input, Exclusions::load()))),
];
