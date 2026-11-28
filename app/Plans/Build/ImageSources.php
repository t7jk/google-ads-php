<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Media\{FetchImage, Ratio};

/**
 * Images from the tool input (url | uploadId | base64) -> ResolvedImage (port of resolveImageSource + the loop in tools-plan.ts).
 * ResolvedImage: ['sha','width','height','bytes','role'=>'image'|'logo','fieldType','name'?]. Source errors are collected separately:
 * the tool prepends them to the plan errors (the plan is then invalid, but the builder still shows the rest).
 */
final class ImageSources
{
    /** @param list<array> $items items of images.add / logos.add @param string|null $forceRole 'logo' (campaign extensions) or null = role taken from the item */
    public static function resolve(array $items, ?string $forceRole = null): array
    {
        $images = [];
        $errors = [];
        foreach ($items as $im) {
            $r = FetchImage::resolve($im['url'] ?? null, $im['uploadId'] ?? null, $im['base64'] ?? null);
            if (isset($r['error'])) {
                $errors[] = $r['error'];
                continue;
            }
            $role = $forceRole ?? (string) $im['role'];
            $cls = Ratio::classify($r['info']['width'], $r['info']['height'], $role);
            if (isset($cls['error'])) {
                $errors[] = $cls['error'];
                continue;
            }
            $images[] = ['sha' => $r['sha'], 'width' => $r['info']['width'], 'height' => $r['info']['height'], 'bytes' => $r['bytes'],
                'role' => $role, 'fieldType' => $cls['fieldType']] + (isset($im['name']) && $im['name'] !== '' ? ['name' => $im['name']] : []);
        }
        return ['images' => $images, 'errors' => $errors];
    }

    /** Image asset name (Google requires it, and it is unique per account): the given one, or built from size, date (UTC) and hash. */
    public static function assetName(array $im, \DateTimeImmutable $now): string
    {
        if (isset($im['name']) && trim($im['name']) !== '') {
            return mb_substr(trim($im['name']), 0, 128);
        }
        $label = $im['role'] === 'logo' ? 'Logo' : 'Image';
        return "$label {$im['width']}x{$im['height']} " . $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') . ' ' . substr($im['sha'], 0, 8);
    }
}
