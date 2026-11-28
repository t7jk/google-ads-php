<?php
declare(strict_types=1);

namespace App\Install;

/**
 * Optional hardening against "a stranger finishes the installer before you do": if the installation directory contains
 * INSTALL_CODE.txt (one line), the wizard asks for that code before the administrator step. No file = no code (the default).
 * The file lives in the installation directory (not the data directory) so that it can be uploaded by FTP like everything else;
 * the shipped .htaccess refuses to serve it. Delete it after the installation if you like; it is not read afterwards.
 */
final class InstallCode
{
    public const FILE = 'INSTALL_CODE.txt';

    public static function expected(): ?string
    {
        $file = PRIVATE_PATH . '/' . self::FILE;
        if (!is_file($file)) {
            return null;
        }
        $code = trim((string) strtok((string) file_get_contents($file), "\r\n"));
        return $code !== '' ? $code : null;
    }

    public static function isRequired(): bool
    {
        return self::expected() !== null;
    }

    /** True when no code is required, or the given one matches (comparison resistant to timing attacks). */
    public static function accepts(string $given): bool
    {
        $expected = self::expected();
        return $expected === null || hash_equals($expected, trim($given));
    }
}
