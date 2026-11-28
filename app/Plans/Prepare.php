<?php
declare(strict_types=1);

namespace App\Plans;

use App\Ads\{AdsApiError, Errors, MutationRefused, UserError};
use App\Google\Ads;
use App\Lib\{Events, Mcp, McpServer};

/**
 * Shared logic of the prepare_* tools (port of the registration and finalize from mcp/tools-plan.ts).
 *
 * API for the D2 tools (new file Tools/Plans2.php):
 *   Prepare::tool('prepare_xxx', 'Title', 'Description', $inputSchema, function (array $input, callable $finalize): array {
 *       $plan = ...builder...;            // the plan array (see Plan), ending with Undo::attach(...)
 *       return $finalize($plan);          // validateOnly in Google -> save -> preview window (or auto-execute when auto_execute)
 *   });
 * Prepare::tool adds the previewOnly field to the schema, validates arguments, catches exceptions (UserError -> the bare message,
 * others -> Errors::describe 'read') and adds the preview window _meta. A plan kind is registered by the file Plans/Kinds/<Name>.php.
 */
final class Prepare
{
    private const MAX_DIFF_LINES = 60;
    private const PREVIEW_IGNORED = 'Preview without execution is disabled; the change was executed automatically.';

    /** Shared description sentence about automatic execution (AUTO in Node). */
    public static function autoText(): string
    {
        return 'Validates the plan in Google (validateOnly) and executes it right away' . (Cfg::allowPreviewOnly() ? ' (unless previewOnly: true)' : '')
            . '. Never enables a campaign; max budget PLN ' . Cfg::maxDailyBudgetPln() . '/day.';
    }

    /**
     * _meta that attaches the preview window (MCP Apps) to a tool. The window's Approve button calls the approve_plan tool,
     * so without allow_tool_approve the window would only show a button that cannot work: no window then, and the
     * client gets the plan page link instead. (An empty _meta is dropped by ToolRegistry::listing.)
     */
    public static function uiMeta(array $extra = []): array
    {
        if (!Cfg::toolApprovalEnabled()) {
            return [];
        }
        return ['ui' => ['resourceUri' => McpServer::PREVIEW_URI] + $extra, 'ui/resourceUri' => McpServer::PREVIEW_URI];
    }

    /** Definition of a prepare_* tool, ready to be returned from a Tools/*.php file. */
    public static function tool(string $name, string $title, string $description, array $inputSchema, callable $handler): array
    {
        $inputSchema['properties'] = ($inputSchema['properties'] ?? []) + ['previewOnly' => [
            'description' => Cfg::allowPreviewOnly()
                ? 'true = only build and validate the plan, change nothing in the account; set only on explicit user request'
                : 'ignored (preview without execution is disabled)',
            'type' => 'boolean',
        ]];
        return [
            'name' => $name, 'title' => $title, 'description' => $description, 'inputSchema' => $inputSchema,
            '_meta' => self::uiMeta(),
            'handler' => function (array $args) use ($inputSchema, $handler): array {
                if ($errs = SchemaCheck::validate($inputSchema, $args)) {
                    return Mcp::error('Invalid parameters: ' . implode('; ', $errs));
                }
                $preview = ($args['previewOnly'] ?? false) === true;
                unset($args['previewOnly']);
                try {
                    return $handler($args, fn(array $plan): array => self::finalize($plan, $preview));
                } catch (UserError $e) {
                    return Mcp::error($e->getMessage());
                } catch (\Throwable $e) {
                    return Mcp::error(Errors::describe($e, 'read'));
                }
            },
        ];
    }

    /** Campaign ID from a number or an exact name (UserError when not found). */
    public static function resolveCampaignId(string $campaign): string
    {
        if (preg_match('/^\d+$/', $campaign)) {
            return $campaign;
        }
        foreach (Ads::search(PlanQueries::CAMPAIGN_ID_NAMES) as $r) {
            if (($r['campaign']['name'] ?? null) === $campaign) {
                return (string) $r['campaign']['id'];
            }
        }
        throw new UserError("Campaign not found: \"{$campaign}\"");
    }

    /** validateOnly in Google for a valid plan; errors go to plan.validation. The guard in Ads::mutate rejects any campaign enabling. */
    public static function validateWithGoogle(array &$plan): void
    {
        if (!$plan['validation']['ok']) {
            return;
        }
        $def = Kinds::get($plan['kind']) ?? [];
        if (isset($def['validate']) && $def['validate']($plan)) {
            return;
        }
        $ops = Plan::ops($plan);
        if (!$ops) {
            return; // nothing to send to Google (e.g. manual instructions only)
        }
        try {
            $materialize = Kinds::hook('materialize');
            $send = $materialize ? $materialize($ops) : $ops;
            isset($def['mutate']) ? $def['mutate']($send, true) : Ads::mutate($send, true);
        } catch (MissingBlob | AdsApiError | MutationRefused $e) {
            Events::log('plan', ['event' => 'validate_failed', 'planId' => $plan['id'], 'ok' => false, 'message' => $e->getMessage()]
                + ($e instanceof AdsApiError ? ['status' => $e->status, 'codes' => $e->codes] : []));
            $plan['validation']['errors'][] = $e instanceof MissingBlob ? $e->getMessage() : Errors::describe($e);
            $plan['validation']['ok'] = false;
        }
    }

    /** validateOnly -> save -> auto-execute (only when auto_execute and no previewOnly) or the preview window. */
    public static function finalize(array $plan, bool $previewRequested = false): array
    {
        $previewOnly = $previewRequested && Cfg::allowPreviewOnly();
        $ignoredPreview = $previewRequested && !Cfg::allowPreviewOnly();
        if ($plan['kind'] !== 'selftest') {
            self::validateWithGoogle($plan);
        }
        PlanStore::save($plan);
        $plan = PlanStore::get($plan['id']); // read back from the DB: the plan hash is computed from the same form the approver will see
        Events::log('plan', [
            'event' => 'created', 'planId' => $plan['id'], 'kind' => $plan['kind'], 'summary' => $plan['summary'], 'ops' => count(Plan::ops($plan)),
            'ok' => $plan['validation']['ok'], 'validationOk' => $plan['validation']['ok'], 'validationErrors' => array_slice($plan['validation']['errors'], 0, 5),
            'previewOnly' => $previewOnly, 'autoExecute' => Cfg::autoExecute(),
        ]);
        if ($plan['validation']['ok'] && $plan['kind'] !== 'selftest' && !Plan::ops($plan)) {
            return self::noop($plan);
        }
        if ($previewOnly || !Cfg::autoExecute()) {
            return self::present($plan);
        }
        if (!$plan['validation']['ok']) {
            $out = self::presentExecuted($plan, ['ok' => false, 'message' => implode('; ', $plan['validation']['errors']) ?: 'The plan has validation errors']);
        } else {
            try {
                $r = Executor::approve($plan['id'], Plan::hash($plan));
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'message' => 'Plan execution error: ' . $e->getMessage()];
            }
            $out = self::presentExecuted(PlanStore::get($plan['id']) ?? $plan, $r);
        }
        if ($ignoredPreview) {
            $out['content'][0]['text'] .= "\n" . self::PREVIEW_IGNORED;
        }
        return $out;
    }

    /** A plan without operations (e.g. manual instructions only): closed as refused with an explanation. */
    private static function noop(array $plan): array
    {
        $message = in_array(self::SAME_IMAGE_NOOP, $plan['warnings'], true)
            ? 'Nothing to change: this image is already in the campaign (identical file).'
            : 'Nothing to change via the API; make the required settings manually in the Google Ads panel (instructions in the notes).';
        PlanStore::finish($plan['id'], 'refused', ['message' => $message]);
        $view = Plan::view($plan, self::token($plan));
        unset($view['hash']);
        $notes = array_map(fn($d) => '- ' . $d['note'], array_filter($plan['diff'], fn($d) => !empty($d['note'])));
        return Mcp::text(implode("\n", [$view['summary'], '', $message, ...$notes]), $view + ['executed' => false, 'result' => ['ok' => true, 'message' => $message]]);
    }

    /** D2 plan warning "same image" (existing-images.ts); the text must be identical to the one in its builder. */
    public const SAME_IMAGE_NOOP = 'This image is already in the campaign (identical file); nothing to change';

    private static function token(array $plan): string
    {
        return PlanLink::make(PlanLink::purpose($plan['id']));
    }

    /**
     * Response with a preview to approve (window or link). The plan hash goes only to a window client and only when the
     * approve_plan tool exists (without it the model has nothing to submit for approval). The link in the text opens the
     * plan page; approving there needs the admin's login.
     */
    public static function present(array $plan): array
    {
        $isApp = Cfg::isAppClient(Mcp::$clientId) && Cfg::toolApprovalEnabled();
        $view = Plan::view($plan, self::token($plan));
        if (!$isApp) {
            unset($view['hash']);
        }
        if (!$plan['validation']['ok']) {
            $text = "{$view['summary']}\n\nNot executed: the plan did not pass validation: " . (implode('; ', $plan['validation']['errors']) ?: 'validation errors') . ". Fix it and prepare a new plan.\nDetails: {$view['approveUrl']}";
        } elseif ($isApp) {
            $text = "{$view['summary']}\n\nCheck the preview and click Approve. Without a window: {$view['approveUrl']}";
        } else {
            $text = "{$view['summary']}\n\nOpen the plan page, log in as the administrator if asked, and click Approve: {$view['approveUrl']}";
        }
        return Mcp::text($text, $view);
    }

    /** Response after an auto-execute attempt: Before -> After + result. It does not reveal the hash (the plan is closed). */
    private static function presentExecuted(array $plan, array $r): array
    {
        $view = Plan::view($plan, self::token($plan));
        unset($view['hash']);
        $dash = fn($v) => ($v === null || $v === '') ? '—' : $v;
        $rows = array_map(fn($d) => "{$d['section']} | {$d['label']} | " . $dash($d['before']) . ' → ' . $dash($d['after']), array_slice($plan['diff'], 0, self::MAX_DIFF_LINES));
        if (count($plan['diff']) > self::MAX_DIFF_LINES) {
            $rows[] = '… and ' . (count($plan['diff']) - self::MAX_DIFF_LINES) . ' more (see details)';
        }
        $parts = [$view['summary'], ...($rows ? ['', ...$rows] : []), ...($plan['warnings'] ? ['', ...array_map(fn($w) => "Warning: $w", $plan['warnings'])] : []),
            '', ($r['ok'] ? 'Done: ' : 'Not executed: ') . $r['message'], "Details: {$view['approveUrl']}"];
        $out = Mcp::text(implode("\n", $parts), $view + ['executed' => $r['ok'], 'result' => ['ok' => $r['ok'], 'message' => $r['message']]]);
        return $r['ok'] ? $out : $out + ['isError' => true];
    }
}
