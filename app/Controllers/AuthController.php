<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\{AdminAccount, LoginLinks};
use App\Lib\{Events, Settings};

/**
 * Passwordless login of the single admin: e-mail -> one-time link -> confirmation page (POST) -> session.
 * Security details: Auth/LoginLinks.php. Admin address and SMTP: the Settings tab (Auth/AdminAccount.php).
 */
final class AuthController
{
    /** POST /login: sends the link if the address belongs to the admin. The message is always the same (it does not reveal the admin address). */
    public function login(): void
    {
        if (!AdminAccount::isInstalled()) {
            redirect('/setup');
        }
        $email = post('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid e-mail address, e.g. jane@example.com.');
            redirect('/');
        }
        // Counted before the address is looked at, so the answer is the same for the admin's address and any other.
        if (!LoginLinks::allowAttempt(client_ip())) {
            flash('error', 'Too many attempts in a short time. Wait 15 minutes and try again.');
            redirect('/');
        }
        if (AdminAccount::isAdminEmail($email)) {
            LoginLinks::send(LoginLinks::LOGIN, AdminAccount::email(), client_ip(), false, LoginLinks::validNextPath(post('next')));
        } else {
            Events::log('admin_login', ['ok' => false, 'result' => 'unknown_email']);
            usleep(random_int(200_000, 600_000)); // response time similar to sending an e-mail
        }
        $_SESSION['login_sent_to'] = $email; // the home page will show "check your inbox"
        redirect('/');
    }

    /** GET /login/verify?t=…: only shows the button — mail scanners that open the link will not consume it. */
    public function confirm(): void
    {
        $token = (string) ($_GET['t'] ?? '');
        $link = LoginLinks::peek($token);
        if ($link === null) {
            view('error', ['title' => 'Link no longer valid', 'message' => 'This link has expired or has already been used. '
                . 'Go back to the home page and request a new link.'], 410);
        }
        view('login_confirm', ['title' => 'Confirm login', 'token' => $token, 'purpose' => $link['purpose'], 'email' => $link['email']]);
    }

    /** POST /login/verify: consumes the token and logs in (for an address change — first saves the new admin address). */
    public function verify(): void
    {
        $link = LoginLinks::consume(post('t'));
        if ($link === null) {
            flash('error', 'The link has expired or has already been used. Please request a new one.');
            redirect('/');
        }
        LoginLinks::clearFallback(); // the link was used: drop the plaintext emergency copy
        if ($link['purpose'] === LoginLinks::EMAIL_CHANGE) {
            if (Settings::get('admin_email_pending') !== $link['email']) {
                flash('error', 'This address change was cancelled or replaced by a newer one.');
                redirect('/');
            }
            AdminAccount::setEmail($link['email']);
            Settings::delete('admin_email_pending');
            Events::log('admin_email_changed', ['ok' => true]);
            flash('ok', 'New administrator address confirmed. Future login links will be sent to ' . $link['email'] . '.');
        } elseif (!AdminAccount::isAdminEmail($link['email'])) {
            // The admin address was changed after the link was sent — the old link can no longer log in.
            flash('error', 'This link was sent to an address that is no longer the administrator address.');
            redirect('/');
        }
        login_admin();
        Events::log('admin_login', ['ok' => true]);
        redirect(LoginLinks::returnPath($link));
    }

    public function logout(): void
    {
        logout_admin();
        flash('ok', 'Logged out.');
        redirect('/');
    }
}
