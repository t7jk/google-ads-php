<?php
declare(strict_types=1);

namespace App\Controllers;

/** Help tab: a static user guide. The text lives in views/help.php; there is no logic here on purpose. */
final class HelpController
{
    public function index(): void
    {
        require_admin();
        view('help', ['title' => 'Help']);
    }
}
