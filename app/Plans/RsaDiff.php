<?php
declare(strict_types=1);

namespace App\Plans;

/** Rows of the RSA ad preview (port of rsa-diff.ts). */
final class RsaDiff
{
    public const SUFFIX_NOTE = 'the account suffix will be appended automatically';

    /** @return list<array> rows: every headline and description with a character count, path and final URL */
    public static function rows(string $section, array $r): array
    {
        $row = fn(string $label, string $after) => ['section' => $section, 'label' => $label, 'before' => null, 'after' => $after, 'change' => 'add'];
        $out = [];
        foreach ($r['headlines'] as $i => $h) {
            $out[] = $row('Headline ' . ($i + 1), $h . ' (' . mb_strlen($h) . '/30)');
        }
        foreach ($r['descriptions'] as $i => $d) {
            $out[] = $row('Description ' . ($i + 1), $d . ' (' . mb_strlen($d) . '/90)');
        }
        $path = array_filter([$r['path1'] ?? '', $r['path2'] ?? ''], fn($p) => $p !== '');
        if ($path) {
            $out[] = $row('Display path', '/' . implode('/', $path));
        }
        $out[] = $row('Final URL', $r['finalUrl']);
        return $out;
    }

    public static function suffixNoteRow(string $section): array
    {
        return ['section' => $section, 'label' => 'URL suffix', 'before' => null, 'after' => self::SUFFIX_NOTE, 'change' => 'info'];
    }
}
