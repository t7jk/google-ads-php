<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Version;

/**
 * About tab: author, links, disclaimer, version and the full licence text.
 * The licence is read from the LICENSE file in the private directory (the repository root), so the text is never duplicated by hand.
 * Pitfall: production uploads only app/ and the data directory, so LICENSE may be missing there; the view then points to the repository.
 */
final class AboutController
{
    public function index(): void
    {
        require_admin();
        view('about', [
            'title' => 'About',
            'version' => Version::current(),
            'license' => self::licenseText(),
        ]);
    }

    /** The LICENSE file as text, or null when it is not available on this installation. */
    private static function licenseText(): ?string
    {
        $file = PRIVATE_PATH . '/LICENSE';
        $text = is_file($file) ? file_get_contents($file) : false;
        return $text === false ? null : trim($text);
    }
}
