<?php
// CLI: imports the Google refresh token from the Node store (secrets.enc: JSON {iv,tag,data} base64, AES-256-GCM,
// key = base64_decode(SECRET_KEY from config.env)) into the settings table (encrypted with the PHP key).
// Usage: php tools/import-google-token.php /path/secrets.enc [/path/config.env]
// Keys in the Node store: google_refresh_token (string), google_scopes (list); the e-mail is taken from config 'allowed_email'.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
} // CLI-only script, never over the web

$file = $argv[1] ?? null;
$envFile = $argv[2] ?? (getenv('GADS_CONFIG_ENV') ?: __DIR__ . '/dev-config.env');
if (!$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php tools/import-google-token.php secrets.enc [config.env]\n");
    exit(1);
}
preg_match('/^SECRET_KEY=["\']?([^"\'\s]+)/m', (string) file_get_contents($envFile), $m);
$key = base64_decode($m[1] ?? '', true);
$box = json_decode((string) file_get_contents($file), true);
$plain = openssl_decrypt(base64_decode($box['data']), 'aes-256-gcm', (string) $key, OPENSSL_RAW_DATA, base64_decode($box['iv']), base64_decode($box['tag']));
if ($plain === false) {
    fwrite(STDERR, "Could not decrypt secrets.enc (wrong key?)\n");
    exit(1);
}
$secrets = json_decode($plain, true);
if (empty($secrets['google_refresh_token'])) {
    fwrite(STDERR, "Missing google_refresh_token in secrets.enc\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';
App\Google\Token::save($secrets['google_refresh_token'], (array) ($secrets['google_scopes'] ?? []), (string) config('allowed_email', ''));
echo 'Zaimportowano refresh token; zakresy: ' . count((array) ($secrets['google_scopes'] ?? [])) . "\n";
