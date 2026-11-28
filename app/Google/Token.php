<?php
declare(strict_types=1);

namespace App\Google;

use App\Lib\Http;
use App\Lib\Settings;

/**
 * Google tokens (a single owner account). Refresh token: settings table, encrypted (Crypto).
 * Access token: cached in settings (encrypted) until it expires, because PHP has no process shared between requests.
 * Port src/auth/google.ts.
 */
final class Token
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REFRESH_KEY = 'google_refresh_token';
    private const SCOPES_KEY = 'google_scopes';
    private const EMAIL_KEY = 'google_email';
    private const CACHE_KEY = 'google_access_cache';
    private const EXPIRY_MARGIN = 60;

    public const GA4_EDIT_SCOPE = 'https://www.googleapis.com/auth/analytics.edit';
    public const REQUIRED_SCOPES = ['https://www.googleapis.com/auth/adwords', 'https://www.googleapis.com/auth/analytics.readonly'];
    // analytics.edit is optional (only prepare_ga4_key_events): sign-in works without it.
    private const FULL_SCOPES = ['openid', 'email', ...self::REQUIRED_SCOPES, self::GA4_EDIT_SCOPE];

    public static function redirectUri(): string
    {
        return base_url() . '/oauth/google/callback';
    }

    public static function authorizeUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => config('google_client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::FULL_SCOPES),
            'include_granted_scopes' => 'true',
            'access_type' => 'offline',
            'prompt' => 'consent', // always: only then does Google return a refresh token
            'state' => $state,
        ]);
    }

    /** @return array{email:string, email_verified:bool, refresh_token:?string, scopes:list<string>} */
    public static function exchangeCode(string $code): array
    {
        [$status, $body] = Http::form(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('google_client_id'),
            'client_secret' => config('google_client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if ($status >= 400) {
            throw new \RuntimeException("Google rejected the code exchange ($status)");
        }
        if (empty($body['id_token'])) {
            throw new \RuntimeException('No id_token in the Google response');
        }
        // No signature verification: the id_token came directly from Google over TLS (the response to our own request).
        $payloadPart = explode('.', (string) $body['id_token'])[1] ?? '';
        $payload = json_decode((string) base64_decode(strtr($payloadPart, '-_', '+/')), true) ?: [];
        return [
            'email' => (string) ($payload['email'] ?? ''),
            'email_verified' => ($payload['email_verified'] ?? false) === true,
            'refresh_token' => $body['refresh_token'] ?? null,
            'scopes' => array_values(array_filter(preg_split('/\s+/', (string) ($body['scope'] ?? '')))),
        ];
    }

    public static function save(string $refreshToken, array $scopes, string $email): void
    {
        Settings::setSecret(self::REFRESH_KEY, $refreshToken);
        Settings::set(self::SCOPES_KEY, json_encode(array_values($scopes)));
        Settings::set(self::EMAIL_KEY, $email);
        Settings::delete(self::CACHE_KEY);
    }

    public static function disconnect(): void
    {
        foreach ([self::REFRESH_KEY, self::SCOPES_KEY, self::EMAIL_KEY, self::CACHE_KEY] as $key) {
            Settings::delete($key);
        }
    }

    public static function hasRefreshToken(): bool
    {
        return Settings::getSecret(self::REFRESH_KEY) !== null;
    }

    public static function email(): string
    {
        return (string) Settings::get(self::EMAIL_KEY);
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        return (array) json_decode((string) Settings::get(self::SCOPES_KEY), true);
    }

    public static function hasGa4EditScope(): bool
    {
        return in_array(self::GA4_EDIT_SCOPE, self::scopes(), true);
    }

    /** Drops the access token from the cache (e.g. after HTTP 401 from the API) so the next call fetches a new one. */
    public static function invalidate(): void
    {
        Settings::delete(self::CACHE_KEY);
    }

    /** A valid access token; no connection or revoked access -> ReauthRequiredError. */
    public static function accessToken(): string
    {
        $cached = json_decode((string) Settings::getSecret(self::CACHE_KEY), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time()) {
            return (string) $cached['token'];
        }
        $refresh = Settings::getSecret(self::REFRESH_KEY);
        if ($refresh === null) {
            throw new ReauthRequiredError();
        }
        [$status, $body] = Http::form(self::TOKEN_URL, [
            'client_id' => config('google_client_id'),
            'client_secret' => config('google_client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if (($body['error'] ?? '') === 'invalid_grant') {
            // We delete the dead token so that the next sign-in forces consent and delivers a new refresh token.
            Settings::delete(self::REFRESH_KEY);
            Settings::delete(self::CACHE_KEY);
            throw new ReauthRequiredError();
        }
        if ($status >= 400 || empty($body['access_token'])) {
            throw new \RuntimeException("Refreshing the Google token failed ($status)");
        }
        $exp = time() + (int) ($body['expires_in'] ?? 3600) - self::EXPIRY_MARGIN;
        Settings::setSecret(self::CACHE_KEY, json_encode(['token' => $body['access_token'], 'exp' => $exp]));
        return (string) $body['access_token'];
    }
}
