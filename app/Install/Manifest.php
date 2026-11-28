<?php
declare(strict_types=1);

namespace App\Install;

/**
 * Integrity check of the uploaded files against MANIFEST.sha256 (written by tools/build-release.sh, `sha256sum` format:
 * "<64 hex>  <relative path>"). Catches the usual shared-hosting upload problems: an interrupted transfer, FTP text mode
 * changing line endings, a file that was skipped. Extra files (config, data, INSTALL_CODE.txt) are not a problem and are ignored.
 * A missing manifest means "installed from git / unknown origin": the caller shows a warning, not an error.
 */
final class Manifest
{
    public const FILE = 'MANIFEST.sha256';

    /**
     * @return array{state:string, changed:list<string>, missing:list<string>, files:int} state: 'absent' | 'checked'
     */
    public static function verify(string $root): array
    {
        $file = $root . '/' . self::FILE;
        if (!is_file($file)) {
            return ['state' => 'absent', 'changed' => [], 'missing' => [], 'files' => 0];
        }
        $changed = [];
        $missing = [];
        $files = 0;
        foreach (self::entries((string) file_get_contents($file)) as $path => $hash) {
            $files++;
            $full = $root . '/' . $path;
            if (!is_file($full)) {
                $missing[] = $path;
            } elseif (!hash_equals($hash, (string) hash_file('sha256', $full))) {
                $changed[] = $path;
            }
        }
        return ['state' => 'checked', 'changed' => $changed, 'missing' => $missing, 'files' => $files];
    }

    /**
     * Parses the manifest. Lines that do not look right, absolute paths and ".." are skipped: the manifest sits next to the code,
     * so it must not be able to make us hash files elsewhere.
     * @return array<string,string> relative path => lowercase sha256
     */
    public static function entries(string $content): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^([0-9a-fA-F]{64}) [ *](.+)$/', $line, $m) !== 1) {
                continue;
            }
            $path = $m[2];
            if ($path[0] === '/' || str_contains($path, '..') || str_contains($path, "\0")) {
                continue;
            }
            $entries[$path] = strtolower($m[1]);
        }
        return $entries;
    }
}
