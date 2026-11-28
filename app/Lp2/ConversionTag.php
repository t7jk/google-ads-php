<?php
declare(strict_types=1);

namespace App\Lp2;

use App\Google\Ads;

/**
 * Tag data of the LP2 conversion action (port of findConversionTag/parseSendTo from src/plans/build-misc.ts).
 * Note for the Plans agent: if you build the same thing in app/Plans, you may replace this class with yours (verify_lp2 only calls find()).
 */
final class ConversionTag
{
    public const QUERY = 'SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.tag_snippets FROM conversion_action';

    /** @return array{conversionId:string,label:string}|null */
    public static function parseSendTo(string $snippet): ?array
    {
        return preg_match('/[\'"]?send_to[\'"]?\s*:\s*[\'"](AW-\d+)\/([^\'"\s]+)[\'"]/', $snippet, $m) ? ['conversionId' => $m[1], 'label' => $m[2]] : null;
    }

    /** Whether this is the action named in the configuration: by ID or by name. */
    public static function isConfigured(?array $a): bool
    {
        if (!$a || ($a['status'] ?? '') === 'REMOVED') {
            return false;
        }
        $id = (string) config('conversion_action_id', '');
        return ($id !== '' && (string) ($a['id'] ?? '') === $id) || trim((string) ($a['name'] ?? '')) === (string) config('conversion_action_name', '');
    }

    /** @return array{found:bool, conversionId?:string, label?:string} */
    public static function find(?string $actionId = null): array
    {
        $actions = array_map(fn($r) => $r['conversionAction'] ?? null, Ads::search(self::QUERY));
        if ($actionId !== null && $actionId !== '') {
            $one = null;
            foreach ($actions as $a) {
                if ($a && ($a['status'] ?? '') !== 'REMOVED' && (string) ($a['id'] ?? '') === $actionId) {
                    $one = $a;
                    break;
                }
            }
            return $one ? self::fromAction($one) : ['found' => false];
        }
        $active = array_values(array_filter($actions, fn($a) => self::isConfigured($a)));
        $cfgId = (string) config('conversion_action_id', '');
        $hit = null;
        foreach ($active as $a) {
            if ((string) $a['id'] === $cfgId) {
                $hit = $a;
                break;
            }
        }
        $hit ??= $active[0] ?? null;
        return $hit ? self::fromAction($hit) : ['found' => false];
    }

    private static function fromAction(array $a): array
    {
        $snips = (array) ($a['tagSnippets'] ?? []);
        $pick = null;
        foreach ($snips as $x) {
            if (($x['type'] ?? '') === 'WEBPAGE' && ($x['pageFormat'] ?? '') === 'HTML' && ($x['eventSnippet'] ?? '') !== '') {
                $pick = $x;
                break;
            }
        }
        if ($pick === null) {
            foreach ($snips as $x) {
                if (($x['eventSnippet'] ?? '') !== '') {
                    $pick = $x;
                    break;
                }
            }
        }
        $parsed = $pick ? self::parseSendTo((string) $pick['eventSnippet']) : null;
        return $parsed ? ['found' => true] + $parsed : ['found' => true];
    }
}
