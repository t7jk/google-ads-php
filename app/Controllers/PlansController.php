<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Events;
use App\Media\{Blobs, ImageInfo};
use App\Plans\{Executor, Plan, PlanLink, PlanRenderer, PlanStore};

/**
 * Plan preview and approval page. VIEWING needs only a valid link token (24 h, purpose "plan:<id>"): the link is given to the
 * model in tool results, so it must not be enough to change anything. APPROVING (POST /plan/approve) needs all three of:
 * an admin session, the link token (it doubles as the form's CSRF secret, so the route skips the session CSRF check) and the plan
 * hash from the page. Without a session the page offers an e-mail login link that returns here (LoginLinks next_path).
 * Threat model and alternatives: docs/adr/0005-zatwierdzanie-planow.md.
 * Routes: GET /plan?id=&t= ; POST /plan/approve ; GET /plan/img?id=&sha=&t= (images referenced by the plan's operations).
 */
final class PlansController
{
    private const EXPIRED = 'The link has expired or is invalid — ask Claude for a new one.';

    private function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' https://tpc.googlesyndication.com https://*.googleusercontent.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }

    private function message(int $status, string $title, string $text): never
    {
        $this->headers();
        view('error', ['title' => $title, 'message' => $text], $status);
    }

    private function authorized(string $id, ?string $token): bool
    {
        return Plan::validId($id) && PlanLink::valid(PlanLink::purpose($id), $token);
    }

    public function show(): void
    {
        $id = (string) ($_GET['id'] ?? '');
        $t = isset($_GET['t']) ? (string) $_GET['t'] : null;
        $ok = $this->authorized($id, $t);
        Events::log('web', ['event' => 'plan_page', 'planId' => $id, 'linkStatus' => $t === null ? 'missing' : ($ok ? 'valid' : 'invalid or expired'), 'ok' => $ok]);
        if (!$ok) {
            $this->message(403, 'Access denied', self::EXPIRED);
        }
        $plan = PlanStore::get($id);
        if (!$plan) {
            $this->message(404, 'Not found', 'No such plan.');
        }
        $this->headers();
        $login = is_admin() ? null : $this->loginForm($id, (string) $t);
        $html = PlanRenderer::html(Plan::view($plan, $t), (string) $t, url('/plan/approve'), $login);
        view('plan', ['title' => 'Google Ads change plan', 'html' => $html]);
    }

    /** The sign-in block shown instead of the Approve button: the link returns to this page after the e-mail login. */
    private function loginForm(string $id, string $token): string
    {
        return '<form method="post" action="' . e(url('/login')) . '">' . csrf_field()
            . '<input type="hidden" name="next" value="' . e(Plan::pagePath($id, $token)) . '">'
            . '<p class="exp">Approving a plan needs the administrator. Enter the administrator e-mail address: we will send a login link that brings you back to this page.</p>'
            . '<input type="email" name="email" required autocomplete="email" placeholder="administrator e-mail" aria-label="Administrator e-mail">'
            . '<button type="submit">Send login link</button></form>';
    }

    public function approve(): void
    {
        $id = post('planId');
        $csrf = post('csrf');
        if (!$this->authorized($id, $csrf)) {
            $this->message(403, 'Access denied', self::EXPIRED);
        }
        if (!is_admin()) {
            // Fail closed BEFORE touching the plan: a link token alone (which the model can see) never executes anything.
            Events::log('web', ['event' => 'approve_post', 'planId' => $id, 'ok' => false, 'resultMessage' => 'no admin session']);
            $this->message(403, 'Login required', 'Approving a plan needs the administrator to be logged in. Open the plan page again and log in with the e-mail link: ' . Plan::pageUrl($id, $csrf));
        }
        $hash = post('planHash');
        if ($hash === '') {
            $this->message(404, 'Not found', 'No such plan.');
        }
        $r = Executor::approve($id, $hash);
        Events::log('web', ['event' => 'approve_post', 'planId' => $id, 'ok' => $r['ok'], 'resultMessage' => $r['message']]);
        $this->headers();
        view('error', ['title' => $r['ok'] ? 'Done' : 'Not executed', 'message' => ($r['ok'] ? 'Done: ' : 'Not executed: ') . $r['message'] . ' Plan: ' . Plan::pageUrl($id, $csrf)]);
    }

    /** Serves only images referenced by this plan's operations (the sha256 appears in the operations JSON). */
    public function image(): void
    {
        $id = (string) ($_GET['id'] ?? '');
        $sha = (string) ($_GET['sha'] ?? '');
        if (!$this->authorized($id, $_GET['t'] ?? null)) {
            http_response_code(403);
            exit;
        }
        $plan = PlanStore::get($id);
        $bytes = ($plan && preg_match('/^[0-9a-f]{64}$/', $sha) && str_contains(json_encode($plan['steps']), $sha)) ? Blobs::get($sha) : null;
        $info = $bytes !== null ? ImageInfo::read($bytes) : null;
        if (!$info || isset($info['error'])) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $info['mime']);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}
