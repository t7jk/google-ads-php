<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\{AdminAccount, LoginLinks};

/** Home page: the login form (e-mail only) or shortcuts for a logged-in admin. */
final class HomeController
{
    public function index(): void
    {
        if (!AdminAccount::isInstalled()) {
            redirect('/setup');
        }
        $loggedIn = is_admin();
        $sentTo = $loggedIn ? null : ($_SESSION['login_sent_to'] ?? null);
        unset($_SESSION['login_sent_to']);
        view('home', [
            'title' => 'Google Ads MCP PHP',
            'loggedIn' => $loggedIn,
            'sentTo' => $sentTo,
            'ttlMinutes' => intdiv(LoginLinks::LOGIN_TTL, 60),
            'mailReady' => AdminAccount::mailer() !== null,
            'fallbackFile' => LoginLinks::FALLBACK_FILE,
            'needsSetup' => $loggedIn && AdminAccount::mailer() === null,
            'phpMail' => $loggedIn && AdminAccount::usesPhpMail(),
        ]);
    }
}
