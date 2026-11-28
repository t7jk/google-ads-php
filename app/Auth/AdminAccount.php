<?php
declare(strict_types=1);

namespace App\Auth;

use App\Lib\{Mailer, Settings};

/**
 * Account of the single administrator and the mail settings (the Settings tab).
 *
 * Storage: the settings table (configuration outside the code — ready for multiple tenants in the future).
 * Admin address: settings.admin_email, and when empty — config.php 'admin_email' (the starting address at installation).
 * SMTP: settings.smtp_*, and when missing — the same keys from config.php (convenient for deployment without the wizard).
 * SMTP password: encrypted (Settings::setSecret), never shown in the form.
 *
 * "Clean install" = no admin address in the database and in config.php, and no installed.lock. Then everyone who arrives lands in
 * the /setup wizard (SetupController) without logging in — so the wizard must be completed right after the app is uploaded.
 * Mail without SMTP: the installer may store settings.mail_transport = 'php' (PHP mail() fallback, see usesPhpMail()).
 */
final class AdminAccount
{
    /** SMTP setting keys (except the password) and their defaults. */
    public const SMTP_DEFAULTS = [
        'smtp_host' => '',
        'smtp_port' => '465',
        'smtp_encryption' => 'ssl', // ssl | tls | none
        'smtp_user' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => 'Google Ads MCP',
    ];
    /** Written last by the installer (Install\Installer): once it exists the wizard is closed for good, whatever the database holds. */
    public const LOCK_FILE = 'installed.lock';
    /** settings.mail_transport value that enables the PHP mail() fallback while smtp_host is empty. */
    public const MAIL_TRANSPORT_PHP = 'php';
    public const ENCRYPTIONS = ['ssl' => 'SSL/TLS (usually port 465)', 'tls' => 'STARTTLS (usually port 587)', 'none' => 'None (local server only)'];

    public static function email(): string
    {
        return strtolower(Settings::get('admin_email') ?? (string) config('admin_email', ''));
    }

    /**
     * Whether the app is already configured: installed.lock exists, or there is an admin address (config.php or database).
     * The placeholder from config.example.php does not count. The database file is not opened when it does not exist yet:
     * opening it would create it (Db), and a clean install must stay clean until the wizard creates the database on purpose.
     */
    public static function isInstalled(): bool
    {
        if (is_file(DATA_PATH . '/' . self::LOCK_FILE)) {
            return true;
        }
        if (self::isRealAddress(strtolower((string) config('admin_email', '')))) {
            return true;
        }
        return is_file(DATA_PATH . '/app.sqlite') && self::isRealAddress(self::email());
    }

    private static function isRealAddress(string $email): bool
    {
        return $email !== '' && $email !== 'admin@example.com';
    }

    public static function setEmail(string $email): void
    {
        Settings::set('admin_email', strtolower($email));
    }

    /** Whether the address from the login form is the admin address (comparison resistant to timing attacks). */
    public static function isAdminEmail(string $email): bool
    {
        $admin = self::email();
        return $admin !== '' && hash_equals($admin, strtolower(trim($email)));
    }

    /** @return array<string,string> current SMTP settings without the password */
    public static function smtp(): array
    {
        $out = [];
        foreach (self::SMTP_DEFAULTS as $key => $default) {
            $out[$key] = Settings::get($key) ?? (string) config($key, $default);
        }
        return $out;
    }

    public static function hasSmtpPassword(): bool
    {
        return Settings::get('smtp_password') !== null || (string) config('smtp_password', '') !== '';
    }

    /** Saves the SMTP settings. An empty password = keep the existing one (the password field in the form is always empty). */
    public static function saveSmtp(array $values, string $password): void
    {
        foreach (self::SMTP_DEFAULTS as $key => $_) {
            Settings::set($key, (string) ($values[$key] ?? ''));
        }
        if ($password !== '') {
            Settings::setSecret('smtp_password', $password);
        }
    }

    public static function clearSmtpPassword(): void
    {
        Settings::delete('smtp_password');
    }

    /**
     * Validation of SMTP data from the form. Returns a list of errors (empty = OK).
     * An empty host is allowed: it means "mail disabled" (login via the emergency link in a file).
     */
    public static function validateSmtp(array $v): array
    {
        $errors = [];
        if ($v['smtp_host'] === '') {
            return $errors;
        }
        if (!preg_match('/^[a-z0-9.-]+$/i', $v['smtp_host'])) {
            $errors[] = 'The SMTP host may contain only letters, digits, dots and hyphens (e.g. mail.example.com).';
        }
        $port = (int) $v['smtp_port'];
        if ((string) $port !== $v['smtp_port'] || $port < 1 || $port > 65535) {
            $errors[] = 'The SMTP port must be a number 1–65535 (usually 465 for SSL or 587 for STARTTLS).';
        }
        if (!isset(self::ENCRYPTIONS[$v['smtp_encryption']])) {
            $errors[] = 'Choose an encryption type from the list.';
        }
        if (!filter_var($v['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid sender address (usually the same as the SMTP username).';
        }
        if (preg_match('/[\r\n]/', $v['smtp_from_name'])) {
            $errors[] = 'The sender name must not contain newline characters.';
        }
        return $errors;
    }

    /** Whether mail goes through PHP mail() (no SMTP host, and the installer enabled the fallback). */
    public static function usesPhpMail(): bool
    {
        return self::smtp()['smtp_host'] === '' && Settings::get('mail_transport') === self::MAIL_TRANSPORT_PHP;
    }

    /** Mailer built from the current settings (SMTP, else PHP mail() when enabled), or null when mail is not configured. */
    public static function mailer(): ?Mailer
    {
        $s = self::smtp();
        if ($s['smtp_host'] !== '' && $s['smtp_from_email'] !== '') {
            return new Mailer([
                'host' => $s['smtp_host'],
                'port' => (int) $s['smtp_port'],
                'encryption' => $s['smtp_encryption'],
                'user' => $s['smtp_user'],
                'password' => (string) (Settings::getSecret('smtp_password') ?? config('smtp_password', '')),
                'from_email' => $s['smtp_from_email'],
                'from_name' => $s['smtp_from_name'],
            ]);
        }
        return self::usesPhpMail() ? self::phpMailMailer($s['smtp_from_name']) : null;
    }

    /** Mailer for the PHP mail() fallback. Sender: noreply@<host of public_base_url> (the only address we can be sure is "ours"). */
    private static function phpMailMailer(string $fromName): Mailer
    {
        $host = (string) parse_url(base_url(), PHP_URL_HOST);
        return new Mailer(['host' => '', 'port' => 0, 'encryption' => 'none', 'user' => '', 'password' => '',
            'from_email' => 'noreply@' . ($host !== '' ? $host : 'localhost'), 'from_name' => $fromName]);
    }
}
