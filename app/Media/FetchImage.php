<?php
declare(strict_types=1);

namespace App\Media;

use App\Lp2\UrlHost;

/** Fetching an image from an allowed domain and resolving the image source (url | uploadId | base64) — port of src/media/fetch-image.ts. */
final class FetchImage
{
    public const MAX_BYTES = 5_242_880;
    private const ERR_SIZE = 'Image exceeds 5120 KB';
    private const MAX_REDIRECTS = 3;

    /** @return string|array{error:string} image bytes or an error */
    public static function fetch(string $url): string|array
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $bad = UrlHost::check($current);
            if ($bad !== null) {
                return ['error' => $bad];
            }
            try {
                $r = Download::get($current, [], self::MAX_BYTES, 15);
            } catch (\RuntimeException $e) {
                return ['error' => 'Could not fetch the image: ' . $e->getMessage()];
            }
            if ($r['status'] >= 300 && $r['status'] < 400) {
                $loc = $r['headers']['location'] ?? '';
                if ($loc === '') {
                    return ['error' => 'Redirect without a Location header'];
                }
                $next = UrlHost::resolve($loc, $current);
                if ($next === null) {
                    return ['error' => "Invalid redirect URL: $loc"];
                }
                $current = $next;
                continue;
            }
            if ($r['status'] < 200 || $r['status'] >= 300) {
                return ['error' => "Fetching the image failed (HTTP {$r['status']})"];
            }
            if ($r['truncated']) {
                return ['error' => self::ERR_SIZE];
            }
            return $r['body'] === '' ? ['error' => 'Empty response when fetching the image'] : $r['body'];
        }
        return ['error' => 'Too many redirects (max 3)'];
    }

    /**
     * Exactly one source: url, uploadId or base64. Saves the image in the store.
     * @return array{sha:string, info:array, bytes:int}|array{error:string}
     */
    public static function resolve(?string $url, ?string $uploadId, ?string $base64): array
    {
        $given = array_filter([$url, $uploadId, $base64], fn($x) => $x !== null && $x !== '');
        if (count($given) !== 1) {
            return ['error' => 'Provide exactly one image source: url, uploadId or base64'];
        }
        if ($uploadId !== null && $uploadId !== '') {
            $found = preg_match('/^[0-9a-f]{64}$/', $uploadId) ? Blobs::get($uploadId) : null;
            if ($found === null) {
                return ['error' => 'Uploaded image not found (uploadId) — upload it again'];
            }
            $buf = $found;
        } elseif ($base64 !== null && $base64 !== '') {
            $raw = preg_replace('#^data:image/[a-zA-Z0-9.+-]+;base64,#', '', $base64);
            if ((int) floor(strlen($raw) * 3 / 4) > self::MAX_BYTES + 3) {
                return ['error' => self::ERR_SIZE];
            }
            $buf = base64_decode($raw, false);
            if ($buf === false || strlen($buf) > self::MAX_BYTES) {
                return ['error' => $buf === false ? ImageInfo::ERR_BROKEN : self::ERR_SIZE];
            }
        } else {
            $r = self::fetch((string) $url);
            if (is_array($r)) {
                return $r;
            }
            $buf = $r;
        }
        $info = ImageInfo::read($buf);
        if (isset($info['error'])) {
            return $info;
        }
        return ['sha' => Blobs::put($buf, $info['ext']), 'info' => $info, 'bytes' => strlen($buf)];
    }
}
