<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Google\Token;
use App\Lib\Events;

/** Connection to a Google account (OAuth, access_type=offline). Only the e-mail from config 'allowed_email'. */
final class GoogleController
{
    public function connect(): void
    {
        require_admin();
        if ((string) config('google_client_id', '') === '') {
            flash('error', 'google_client_id is missing from config.php.');
            redirect('/connections');
        }
        $_SESSION['google_state'] = bin2hex(random_bytes(16));
        header('Location: ' . Token::authorizeUrl($_SESSION['google_state']));
        exit;
    }

    public function callback(): void
    {
        require_admin();
        $state = (string) ($_GET['state'] ?? '');
        $expected = (string) ($_SESSION['google_state'] ?? '');
        unset($_SESSION['google_state']);
        if ($expected === '' || !hash_equals($expected, $state) || isset($_GET['error']) || empty($_GET['code'])) {
            $this->fail('Google sign-in failed or was cancelled.');
        }
        try {
            $r = Token::exchangeCode((string) $_GET['code']);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
        if (!$r['email_verified'] || strtolower($r['email']) !== strtolower((string) config('allowed_email', ''))) {
            Events::log('google_login', ['ok' => false, 'reason' => 'email_not_allowed']);
            $this->fail('This Google account does not have access to this server.');
        }
        $missing = array_diff(Token::REQUIRED_SCOPES, $r['scopes']);
        if ($missing) {
            $this->fail('The required permissions were not granted (Google Ads and Analytics — read access). Try again and tick all of them.');
        }
        if (empty($r['refresh_token'])) {
            $this->fail('Google did not return a refresh token. Remove the app\'s access in your Google account and connect again.');
        }
        Token::save($r['refresh_token'], $r['scopes'], $r['email']);
        Events::log('google_login', ['ok' => true]);
        flash('ok', 'Connected to Google as ' . $r['email'] . '.');
        redirect('/connections');
    }

    public function disconnect(): void
    {
        require_admin();
        Token::disconnect();
        Events::log('google_login', ['ok' => true, 'event' => 'disconnect']);
        flash('ok', 'Disconnected from Google.');
        redirect('/connections');
    }

    private function fail(string $message): never
    {
        flash('error', $message);
        redirect('/connections');
    }
}
