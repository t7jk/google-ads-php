<?php
declare(strict_types=1);

namespace App\Media;

/** Choosing the PMax field type from the image aspect ratio (port of src/media/ratio.ts). */
final class Ratio
{
    /** role, ratio, description, fieldType, minimum, recommended */
    private const SPECS = [
        ['image', 1.91, '1,91:1', 'MARKETING_IMAGE', [600, 314], [1200, 628]],
        ['image', 1.0, '1:1', 'SQUARE_MARKETING_IMAGE', [300, 300], [1200, 1200]],
        ['image', 0.8, '4:5', 'PORTRAIT_MARKETING_IMAGE', [480, 600], [960, 1200]],
        ['logo', 1.0, '1:1', 'LOGO', [128, 128], [1200, 1200]],
        ['logo', 4.0, '4:1', 'LANDSCAPE_LOGO', [512, 128], [1200, 300]],
    ];

    /** @return array{fieldType:string, ratio:string, belowMin:bool, belowRecommended:bool}|array{error:string} */
    public static function classify(int|float $w, int|float $h, string $role): array
    {
        if ($w <= 0 || $h <= 0) {
            return ['error' => 'Invalid image dimensions'];
        }
        $ar = $w / $h;
        $forRole = array_values(array_filter(self::SPECS, fn($s) => $s[0] === $role));
        foreach ($forRole as [, $r, $label, $field, $min, $rec]) {
            if (abs($ar - $r) / $r <= 0.01) {
                return ['fieldType' => $field, 'ratio' => $label, 'belowMin' => $w < $min[0] || $h < $min[1], 'belowRecommended' => $w < $rec[0] || $h < $rec[1]];
            }
        }
        $ok = implode(', ', array_map(fn($s) => $s[2], $forRole));
        return ['error' => "Aspect ratio {$w}×{$h} does not match any allowed format ($ok, tolerance ±1%)"];
    }

    /** Description for the upload page and recent_uploads: PMax field types matching the dimensions (image and logo), like describe() in Node. */
    public static function describe(int $w, int $h): string
    {
        $kinds = [];
        foreach (['image', 'logo'] as $role) {
            $k = self::classify($w, $h, $role);
            if (!isset($k['error'])) {
                $kinds[] = "{$k['fieldType']} ({$k['ratio']})" . ($k['belowMin'] ? ' — too small' : ($k['belowRecommended'] ? ' — below the recommended size' : ''));
            }
        }
        return $kinds ? implode('; ', $kinds) : 'aspect ratio does not match any PMax format';
    }
}
