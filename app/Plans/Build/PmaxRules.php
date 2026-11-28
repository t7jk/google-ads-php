<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Plans\Text;

/**
 * Pure validation rules for a PMax asset group (port of validate-pmax.ts): text lengths, asset counts, YouTube video ID.
 * Compare texts with Text::same / Text::len (NFC, case-insensitive, counted in code points).
 */
final class PmaxRules
{
    public const TEXT_TYPES = ['HEADLINE', 'LONG_HEADLINE', 'DESCRIPTION'];
    public const IMAGE_TYPES = ['MARKETING_IMAGE', 'SQUARE_MARKETING_IMAGE', 'PORTRAIT_MARKETING_IMAGE'];
    public const LOGO_TYPES = ['LOGO', 'LANDSCAPE_LOGO'];
    public const MAX_IMAGES = 20;

    /** field type => [max characters, name used in messages] */
    private const TEXT_RULES = [
        'HEADLINE' => [30, 'Headline'], 'LONG_HEADLINE' => [90, 'Long headline'], 'DESCRIPTION' => [90, 'Description'], 'BUSINESS_NAME' => [25, 'Business name'],
    ];
    /** key, label (plural), min, max */
    private const COUNT_RULES = [
        ['HEADLINE', 'headlines', 3, 15], ['LONG_HEADLINE', 'long headlines', 1, 5], ['DESCRIPTION', 'descriptions', 2, 5],
        ['MARKETING_IMAGE', '1.91:1 images', 1, 20], ['SQUARE_MARKETING_IMAGE', '1:1 images', 1, 20],
        ['LOGO', '1:1 logos', 1, 5], ['LANDSCAPE_LOGO', '4:1 logos', 0, 5], ['YOUTUBE_VIDEO', 'videos', 0, 15],
    ];

    public static function textLimit(string $fieldType): ?int
    {
        return self::TEXT_RULES[$fieldType][0] ?? null;
    }

    /** Error message or null. */
    public static function checkText(string $fieldType, string $text): ?string
    {
        if (!isset(self::TEXT_RULES[$fieldType])) {
            return "Unsupported text type: $fieldType";
        }
        [$max, $name] = self::TEXT_RULES[$fieldType];
        $n = Text::len($text);
        if ($n === 0) {
            return "$name cannot be empty";
        }
        return $n > $max ? "$name has $n characters (max $max): \"" . Text::norm($text) . '"' : null;
    }

    /**
     * Counts after the change: current state plus deltas (may be negative).
     * @param array<string,int> $current @param array<string,int> $delta @return array<string,int>
     */
    public static function countAfter(array $current, array $delta): array
    {
        foreach ($delta as $k => $v) {
            $current[$k] = ($current[$k] ?? 0) + $v;
        }
        return $current;
    }

    /** Asset count errors after the change; $brandAtCampaign means logos come from campaign-level assets. @param array<string,int> $after @return list<string> */
    public static function checkGroupCounts(array $after, bool $brandAtCampaign): array
    {
        $e = [];
        foreach (self::COUNT_RULES as [$key, $label, $min, $max]) {
            $n = $after[$key] ?? 0;
            $suffix = $brandAtCampaign && ($key === 'LOGO' || $key === 'LANDSCAPE_LOGO') ? ' (including campaign assets)' : '';
            if ($n < $min) {
                $e[] = "Too few $label after the change: $n (min $min)$suffix";
            } elseif ($n > $max) {
                $e[] = "Too many $label after the change: $n (max $max)$suffix";
            }
        }
        $imgs = array_sum(array_map(fn($k) => $after[$k] ?? 0, self::IMAGE_TYPES));
        if ($imgs > self::MAX_IMAGES) {
            $e[] = "Too many images after the change: $imgs (max " . self::MAX_IMAGES . ')';
        }
        return $e;
    }

    /** Extracts the 11-character video ID from a YouTube URL or from a bare ID; null if it is not a video. */
    public static function youtubeId(string $src): ?string
    {
        $s = trim($src);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $s)) {
            return $s;
        }
        $u = parse_url($s);
        if (!$u || empty($u['host']) || empty($u['scheme'])) {
            return null;
        }
        $host = preg_replace('/^(www\.|m\.)/', '', strtolower($u['host']));
        $path = $u['path'] ?? '';
        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', $path)[1] ?? null;
        } elseif ($host === 'youtube.com' || $host === 'music.youtube.com') {
            if ($path === '/watch') {
                parse_str($u['query'] ?? '', $q);
                $id = is_string($q['v'] ?? null) ? $q['v'] : null;
            } elseif (preg_match('#^/(?:shorts|embed|live)/([^/]+)#', $path, $m)) {
                $id = $m[1];
            }
        }
        return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }
}
