<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Google\Ads;
use App\Media\Download;

/**
 * Finds IMAGE assets that already exist in the account (port of existing-images.ts). Google requires a unique image name,
 * so the plan first checks names and (optionally) identical content (file size + dimensions, then sha256 of the downloaded file).
 * ExistingImage: ['id','name','resourceName','width'?,'height'?,'contentMatch'? => sha of the confirmed identical content].
 */
final class ExistingImages
{
    private const DOWNLOAD_TIMEOUT_SEC = 8;
    private const DOWNLOAD_SLACK_BYTES = 1024;

    private static function gaqlStr(string $s): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
    }

    /**
     * @param list<string> $names image asset names (e.g. generated from size, date and hash)
     * @param list<array{sha:string,width:int,height:int,bytes:int}>|null $contentImages also check for identical content (null = skip)
     * @return list<array>
     */
    public static function find(array $names, ?array $contentImages = null): array
    {
        $out = [];
        $unique = array_values(array_unique(array_filter(array_map('trim', $names), fn($n) => $n !== '')));
        foreach ($unique as $name) {
            $rows = Ads::search("SELECT asset.id, asset.name, asset.resource_name, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels FROM asset WHERE asset.type = 'IMAGE' AND asset.name = '" . self::gaqlStr($name) . "'");
            foreach ($rows as $r) {
                $a = $r['asset'] ?? [];
                $fs = $a['imageAsset']['fullSize'] ?? null;
                $out[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? $name), 'resourceName' => (string) ($a['resourceName'] ?? '')]
                    + (!empty($fs['widthPixels']) ? ['width' => (int) $fs['widthPixels']] : [])
                    + (!empty($fs['heightPixels']) ? ['height' => (int) $fs['heightPixels']] : []);
            }
        }
        foreach ($contentImages ?? [] as $im) {
            array_push($out, ...self::findByContent($im, $out));
        }
        return $out;
    }

    /** Assets with the same file size and dimensions whose downloaded content has the same sha256. No certainty = no reuse. */
    private static function findByContent(array $im, array $already): array
    {
        $found = [];
        try {
            $rows = Ads::search('SELECT asset.id, asset.name, asset.resource_name, asset.image_asset.full_size.url, asset.image_asset.full_size.width_pixels, asset.image_asset.full_size.height_pixels FROM asset WHERE asset.type = \'IMAGE\''
                . ' AND asset.image_asset.file_size = ' . (int) $im['bytes'] . ' AND asset.image_asset.full_size.width_pixels = ' . (int) $im['width'] . ' AND asset.image_asset.full_size.height_pixels = ' . (int) $im['height']);
            foreach ($rows as $r) {
                $a = $r['asset'] ?? [];
                $url = $a['imageAsset']['fullSize']['url'] ?? null;
                if (!$url || self::alreadyMatched(array_merge($already, $found), (string) $a['id'], $im['sha'])) {
                    continue;
                }
                $buf = self::downloadGoogleImage((string) $url, (int) $im['bytes']);
                if ($buf === null || hash('sha256', $buf) !== $im['sha']) {
                    continue;
                }
                $found[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? ''), 'resourceName' => (string) ($a['resourceName'] ?? ''),
                    'width' => (int) $im['width'], 'height' => (int) $im['height'], 'contentMatch' => $im['sha']];
            }
        } catch (\Throwable) {
            // no certainty = no reuse; the plan carries on
        }
        return $found;
    }

    private static function alreadyMatched(array $list, string $id, string $sha): bool
    {
        foreach ($list as $e) {
            if ($e['id'] === $id && ($e['contentMatch'] ?? null) === $sha) {
                return true;
            }
        }
        return false;
    }

    /** Whether the (https) URL points to a Google image host (tpc.googlesyndication.com or *.googleusercontent.com). */
    public static function isGoogleImageUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower($p['host'] ?? '');
        return $host === 'tpc.googlesyndication.com' || str_ends_with($host, '.googleusercontent.com');
    }

    /** Downloads an image from a Google host (https only, allowlist, size limit, timeout, no redirects). null = failed. */
    public static function downloadGoogleImage(string $url, int $expectedBytes): ?string
    {
        if (!self::isGoogleImageUrl($url)) {
            return null;
        }
        try {
            $max = max($expectedBytes, 1) + self::DOWNLOAD_SLACK_BYTES;
            $r = Download::get($url, [], $max, self::DOWNLOAD_TIMEOUT_SEC);
        } catch (\RuntimeException) {
            return null;
        }
        return ($r['status'] >= 200 && $r['status'] < 300 && !$r['truncated']) ? $r['body'] : null;
    }

    /**
     * Matches an image to an existing asset: null (none), ['reuse' => ExistingImage] or ['error' => message] (same name, different size).
     * @param list<array> $existing
     */
    public static function match(string $name, int $w, int $h, array $existing, ?string $sha = null): ?array
    {
        foreach ($existing as $e) {
            if ($sha !== null && ($e['contentMatch'] ?? null) === $sha) {
                return ['reuse' => $e];
            }
        }
        foreach ($existing as $e) {
            if (!empty($e['contentMatch']) || mb_strtolower(trim($e['name'])) !== mb_strtolower(trim($name))) {
                continue;
            }
            if (!empty($e['width']) && !empty($e['height']) && ($e['width'] !== $w || $e['height'] !== $h)) {
                return ['error' => "The account already has an image named \"{$e['name']}\" but with a different size ({$e['width']}×{$e['height']}). Use a different name"];
            }
            return ['reuse' => $e];
        }
        return null;
    }
}
