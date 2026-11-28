<?php
declare(strict_types=1);

namespace App\Ads;

/** Port src/ads/slug.ts. */
final class Slug
{
    public static function toParamSlug(string $s): string
    {
        // Polish letters are transliterated explicitly on purpose (campaign names are often Polish).
        $s = strtr(mb_strtolower($s), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', self::stripAccents($s));
        $s = trim($s, '-');
        return rtrim(mb_substr($s, 0, 100), '-');
    }

    /** Equivalent of normalize("NFD") + removing combining marks; without intl: iconv transliteration, otherwise unchanged. */
    private static function stripAccents(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            return (string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));
        }
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        return $t === false ? $s : $t;
    }
}
