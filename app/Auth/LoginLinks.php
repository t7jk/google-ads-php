<?php
declare(strict_types=1);

namespace App\Auth;

use App\Lib\{Crypto, Db, Events, MailError, RateLimiter};

/**
 * Passwordless login: a one-time link sent by e-mail (login_links table).
 *
 * Security:
 * - a 256-bit token, only its SHA-256 in the database; the link is valid for LOGIN_TTL and works once (used_at);
 * - clicking the link shows a page with a "Log in" button (POST). Mail scanners (Outlook Safe Links, antivirus)
 *   open links with GET — if GET logged in, they would use up the token before the human does;
 * - rate limits (spam from the login form, flooding the admin's mailbox): see allowAttempt() for why they are shaped as they are;
 * - the form response is the same for the admin address and any other (it does not reveal who the admin is).
 *
 * When SMTP is not configured or sending fails, the link goes to an emergency file
 * DATA_PATH/logs/login-link.txt (outside the web root; access only via FTP/SSH = only the server owner).
 */
final class LoginLinks
{
    public const LOGIN = 'login';
    public const EMAIL_CHANGE = 'email_change';
    public const LOGIN_TTL = 15 * 60;
    public const EMAIL_CHANGE_TTL = 60 * 60;
    private const MAX_ATTEMPTS_PER_IP = 5;     // login form submissions per IP and ATTEMPT_WINDOW, whatever address was typed
    private const ATTEMPT_WINDOW = 15 * 60;
    private const MAX_MAILS_PER_HOUR = 60;     // login e-mails to the admin, all IPs together
    /** The only page a login link may return to: a plan page (the approval flow). Anything else would be an open redirect. */
    private const NEXT_PATH_PATTERN = '/^\/plan\?id=[0-9a-f]{32}&t=[A-Za-z0-9._-]{1,200}$/';
    public const FALLBACK_FILE = 'logs/login-link.txt';

    /**
     * Whether this form submission may proceed. Two limits, chosen so that an attacker cannot lock the admin out:
     *  - per IP: EVERY attempt counts, for the admin's address and for any other alike, so the limit throttles guessing
     *    and does not reveal which address is the admin's (the old per-row count only ever counted real admin links);
     *  - global: a ceiling on e-mails actually sent (mailbox flooding). It is deliberately well above what one person needs.
     *    A botnet could still exhaust it for an hour; the owner then has the emergency routes (SSH, login-link file).
     */
    public static function allowAttempt(string $ip): bool
    {
        $withinIpLimit = RateLimiter::hit('login_ip:' . $ip, self::MAX_ATTEMPTS_PER_IP, self::ATTEMPT_WINDOW);
        return $withinIpLimit && !RateLimiter::isLimited('login_mails', self::MAX_MAILS_PER_HOUR);
    }

    /** Where the browser goes after a successful login with this link row. next_path was validated when the link was created, so it cannot point off-site. */
    public static function returnPath(array $link): string
    {
        if ($link['purpose'] === self::EMAIL_CHANGE) {
            return '/settings';
        }
        return (string) ($link['next_path'] ?? '') ?: '/';
    }

    /** $path if it is a plan page path a login link may return to (see NEXT_PATH_PATTERN), otherwise null. */
    public static function validNextPath(string $path): ?string
    {
        return preg_match(self::NEXT_PATH_PATTERN, $path) === 1 ? $path : null;
    }

    /**
     * Creates the link and sends it by e-mail. Returns true when the e-mail was sent; false = the emergency file was used.
     * $strict = true (installation wizard): no emergency file — a sending error is thrown as MailError, and the link is deleted.
     * Call only for a verified address (the admin, or a new admin address being confirmed).
     * $nextPath (validated by validNextPath) is where the link leads after login, e.g. back to the plan page.
     */
    public static function send(string $purpose, string $email, string $ip, bool $strict = false, ?string $nextPath = null): bool
    {
        self::prune();
        RateLimiter::hit('login_mails', self::MAX_MAILS_PER_HOUR, 3600);
        $token = Crypto::randomHex(32);
        $ttl = $purpose === self::EMAIL_CHANGE ? self::EMAIL_CHANGE_TTL : self::LOGIN_TTL;
        Db::exec('INSERT INTO login_links (token_hash, purpose, email, ip, created_at, expires_at, next_path) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Crypto::sha256($token), $purpose, $email, $ip, now_iso(), now_iso($ttl), $nextPath]);
        $link = base_url() . '/login/verify?t=' . $token;

        $mailer = AdminAccount::mailer();
        if ($mailer !== null) {
            try {
                [$subject, $text, $html] = self::message($purpose, $link, $ttl);
                $mailer->send($email, $subject, $text, $html);
                Events::log('login_link', ['ok' => true, 'purpose' => $purpose]);
                return true;
            } catch (MailError $e) {
                Events::log('login_link', ['ok' => false, 'purpose' => $purpose, 'error' => $e->getMessage()]);
                if ($strict) {
                    Db::exec('DELETE FROM login_links WHERE token_hash = ?', [Crypto::sha256($token)]);
                    throw $e;
                }
            }
        }
        self::writeFallback($purpose, $email, $link, $ttl);
        return false;
    }

    /** Checks the token without consuming it (the page with the button). Returns the row or null. */
    public static function peek(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return Db::one('SELECT * FROM login_links WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [Crypto::sha256($token), now_iso()]);
    }

    /**
     * Consumes the token (one time). UPDATE with the used_at IS NULL condition is atomic — two simultaneous
     * clicks will not log in twice. Returns the link row or null.
     */
    public static function consume(string $token): ?array
    {
        $row = self::peek($token);
        if ($row === null) {
            return null;
        }
        $done = Db::exec('UPDATE login_links SET used_at = ? WHERE id = ? AND used_at IS NULL', [now_iso(), $row['id']]);
        return $done === 1 ? $row : null;
    }

    /** @return array{string,string,string} subject, text, HTML */
    private static function message(string $purpose, string $link, int $ttl): array
    {
        $minutes = intdiv($ttl, 60);
        if ($purpose === self::EMAIL_CHANGE) {
            $subject = 'Confirm your new administrator address — Google Ads MCP';
            $intro = 'This address was entered as the new administrator address of the Google Ads MCP server. Click to confirm it:';
            $button = 'Confirm address';
        } else {
            $subject = 'Your login link — Google Ads MCP';
            $intro = 'Click to log in to the Google Ads MCP server dashboard:';
            $button = 'Log in';
        }
        $outro = "The link is single-use and valid for $minutes min. If this wasn't you, just ignore this message — nothing will happen.";
        $text = "$intro\n\n$link\n\n$outro\n";
        $html = '<!doctype html><html lang="en"><body style="font-family:system-ui,sans-serif;font-size:16px;color:#1c2230">'
            . '<p>' . e($intro) . '</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;padding:12px 20px;background:#1a56c4;color:#fff;'
            . 'border-radius:6px;text-decoration:none;font-weight:600">' . e($button) . '</a></p>'
            . '<p style="color:#5a6275;font-size:14px">' . e($outro) . '<br>If the button does not work, copy this address: '
            . e($link) . '</p></body></html>';
        return [$subject, $text, $html];
    }

    private static function writeFallback(string $purpose, string $email, string $link, int $ttl): void
    {
        $file = DATA_PATH . '/' . self::FALLBACK_FILE;
        @mkdir(dirname($file), 0775, true);
        $body = "Emergency link ($purpose) for $email, created " . now_iso() . ', valid for ' . intdiv($ttl, 60) . " min, single-use:\n$link\n";
        file_put_contents($file, $body, LOCK_EX);
        @chmod($file, 0660);
    }

    /** Deletes the emergency link file: once a link has been used, the plaintext copy must not stay on disk. */
    public static function clearFallback(): void
    {
        $file = DATA_PATH . '/' . self::FALLBACK_FILE;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Cleanup on request (no cron): links older than a day. */
    private static function prune(): void
    {
        Db::exec('DELETE FROM login_links WHERE created_at < ?', [now_iso(-86400)]);
    }
}
