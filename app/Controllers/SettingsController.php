<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\{AdminAccount, LoginLinks};
use App\Lib\{Events, MailError, Settings};

/**
 * Settings tab: the administrator e-mail (login) and the SMTP server used to send login links.
 *
 * Changing the admin address requires confirmation via a link sent to the NEW address — a typo will not lock you out.
 * Exception: when SMTP does not work, the confirmation cannot be sent; then the link goes to the emergency file (as with login).
 */
final class SettingsController
{
    public function index(): void
    {
        require_admin();
        view('settings', [
            'title' => 'Settings',
            'adminEmail' => AdminAccount::email(),
            'pendingEmail' => (string) Settings::get('admin_email_pending'),
            'smtp' => AdminAccount::smtp(),
            'hasPassword' => AdminAccount::hasSmtpPassword(),
            'encryptions' => AdminAccount::ENCRYPTIONS,
            'fallbackFile' => LoginLinks::FALLBACK_FILE,
        ]);
    }

    public function saveSmtp(): void
    {
        require_admin();
        $values = [];
        foreach (AdminAccount::SMTP_DEFAULTS as $key => $_) {
            $values[$key] = post($key);
        }
        $errors = AdminAccount::validateSmtp($values);
        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            redirect('/settings');
        }
        AdminAccount::saveSmtp($values, (string) ($_POST['smtp_password'] ?? ''));
        if (!empty($_POST['smtp_password_clear'])) {
            AdminAccount::clearSmtpPassword();
        }
        Events::log('settings_smtp', ['ok' => true]);
        flash('ok', $values['smtp_host'] === ''
            ? 'Saved. Mail is disabled — login links will be written to an emergency file on the server.'
            : 'SMTP settings saved. Click "Send test e-mail" to check that they work.');
        redirect('/settings');
    }

    public function testMail(): void
    {
        require_admin();
        $mailer = AdminAccount::mailer();
        if ($mailer === null) {
            flash('error', 'First fill in and save the SMTP host and the sender address.');
            redirect('/settings');
        }
        try {
            $mailer->send(AdminAccount::email(), 'Test poczty — Google Ads MCP',
                "This is a test message from the Settings tab.\nIf you can read it, login links will reach this address.\n");
            flash('ok', 'Test e-mail sent to ' . AdminAccount::email() . '. If it does not arrive within a few minutes, check your spam folder.');
        } catch (MailError $e) {
            flash('error', 'Could not send: ' . $e->getMessage());
        }
        redirect('/settings');
    }

    public function saveEmail(): void
    {
        require_admin();
        $email = strtolower(post('admin_email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid administrator e-mail address.');
            redirect('/settings');
        }
        if ($email === AdminAccount::email()) {
            Settings::delete('admin_email_pending');
            flash('ok', 'That is already the administrator address — no change.');
            redirect('/settings');
        }
        Settings::set('admin_email_pending', $email);
        $sent = LoginLinks::send(LoginLinks::EMAIL_CHANGE, $email, client_ip());
        Events::log('admin_email_change_requested', ['ok' => true, 'sent' => $sent]);
        flash($sent ? 'ok' : 'error', $sent
            ? "We sent a confirmation link to $email (valid for " . intdiv(LoginLinks::EMAIL_CHANGE_TTL, 60) . ' min). Until you confirm it, keep logging in with your current address.'
            : 'Could not send the e-mail (check SMTP). The confirmation link was saved on the server in the file ' . LoginLinks::FALLBACK_FILE . '.');
        redirect('/settings');
    }

    public function cancelEmail(): void
    {
        require_admin();
        Settings::delete('admin_email_pending');
        flash('ok', 'Admin address change cancelled.');
        redirect('/settings');
    }
}
