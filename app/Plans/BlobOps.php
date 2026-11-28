<?php
declare(strict_types=1);

namespace App\Plans;

use App\Media\Blobs;

/**
 * References to images in plan operations (port of blob-ops.ts). A plan stores ['$blob' => sha256] instead of bytes;
 * only right before sending to Google (the '*'/materialize hook in Kinds/Pmax.php) are they replaced with base64.
 * Pitfall: a reference is an array with EXACTLY one key '$blob' (other objects stay unchanged).
 */
final class BlobOps
{
    public const MISSING_MESSAGE = 'Image file from the preview is missing; prepare the plan again';

    public static function ref(string $sha): array
    {
        return ['$blob' => $sha];
    }

    private static function isRef(mixed $v): bool
    {
        return is_array($v) && array_keys($v) === ['$blob'] && is_string($v['$blob']);
    }

    private static function walk(mixed $v, callable $f): mixed
    {
        if (self::isRef($v)) {
            return $f($v['$blob']);
        }
        return is_array($v) ? array_map(fn($x) => self::walk($x, $f), $v) : $v;
    }

    /** Unique sha256 of the images referenced by the operations. @return list<string> */
    public static function collect(array $ops): array
    {
        $out = [];
        self::walk($ops, function (string $sha) use (&$out) {
            $out[$sha] = true;
            return null;
        });
        return array_keys($out);
    }

    /** Deep copy of the operations with images as base64. @throws MissingBlob when the file is gone */
    public static function materialize(array $ops): array
    {
        return self::walk($ops, function (string $sha): string {
            $bytes = Blobs::get($sha);
            if ($bytes === null) {
                throw new MissingBlob(self::MISSING_MESSAGE);
            }
            return base64_encode($bytes);
        });
    }
}
