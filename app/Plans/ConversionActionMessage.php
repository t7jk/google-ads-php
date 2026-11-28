<?php
declare(strict_types=1);

namespace App\Plans;

use App\Plans\Build\Misc;

/** Message after a "conversion action" plan runs (from executor.ts): tag instructions depending on the type of the created action. */
final class ConversionActionMessage
{
    public static function build(array $planOps, array $results, string $default): string
    {
        $created = null;
        foreach ($planOps as $o) {
            if (!empty($o['conversionActionOperation']['create'])) {
                $created = $o['conversionActionOperation']['create'];
                break;
            }
        }
        $type = (string) ($created['type'] ?? 'WEBPAGE');
        $rn = '';
        foreach ((array) ($results[0] ?? []) as $v) {
            if (is_array($v) && !empty($v['resourceName'])) {
                $rn = (string) $v['resourceName'];
                break;
            }
        }
        $newId = $rn !== '' ? basename($rn) : '';
        if ($type === 'UPLOAD_CLICKS') {
            return 'Conversion action created (ID ' . ($newId ?: '?') . '). This is a click import action: it has no page tag, do not enter anything in lp2-admin. Conversions are sent by prepare_offline_conversions.';
        }
        if ($type === 'AD_CALL') {
            return 'Conversion action created (ID ' . ($newId ?: '?') . '). Calls from ads do not need a page tag.';
        }
        try {
            // tag data of the NEWLY created action (by ID from the mutate result), not the action from the config
            $t = Misc::findConversionTag($newId ?: null);
            // LP2 action from the config (old mode): when the new one is not visible by ID, look it up by the configured name
            if (!$t['found'] && $newId && ($created['name'] ?? null) === Cfg::conversionActionName()) {
                $t = Misc::findConversionTag();
            }
            return $default . (!empty($t['conversionId']) && !empty($t['label'])
                ? ' ' . Misc::conversionInstruction($t['conversionId'], $t['label'])
                : ' Tag data is not available yet; use get_conversion_tag.');
        } catch (\Throwable) {
            return $default . ' Could not fetch the tag data; use get_conversion_tag.';
        }
    }
}
