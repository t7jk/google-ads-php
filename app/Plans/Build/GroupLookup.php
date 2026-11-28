<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Plans\Text;

/** Resolves a PMax asset group by ID (digits only) or exact name. Shared by the asset-group and signals plans. */
final class GroupLookup
{
    /**
     * @param array $snapshot PmaxSnapshot snapshot @param list<string> $errors messages appended (not found / ambiguous)
     * @return array|null assetGroup row from the snapshot, or null
     */
    public static function find(array $snapshot, string $ref, array &$errors): ?array
    {
        $ref = trim($ref);
        $groups = $snapshot['assetGroups'];
        $found = preg_match('/^\d+$/', $ref) ? array_filter($groups, fn($g) => (string) ($g['assetGroup']['id'] ?? '') === $ref) : [];
        $found = $found ?: array_filter($groups, fn($g) => Text::same((string) ($g['assetGroup']['name'] ?? ''), $ref));
        if (count($found) === 1) {
            return reset($found)['assetGroup'];
        }
        if (count($found) > 1) {
            $errors[] = "Name \"{$ref}\" matches several groups; give the group ID";
        } else {
            $errors[] = "Asset group \"{$ref}\" not found. Available: " . (implode(', ', array_map(fn($g) => ($g['assetGroup']['name'] ?? '') . ' [' . ($g['assetGroup']['id'] ?? '') . ']', $groups)) ?: 'none');
        }
        return null;
    }
}
