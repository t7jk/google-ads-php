<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Google\Token;
use App\Lib\{Clients, Db, Events};

/** Connections tab: MCP clients (claude.ai / Claude Code), active tokens, Google connection status. */
final class ConnectionsController
{
    public function index(): void
    {
        require_admin();
        $this->render(null);
    }

    /** $newClient: the freshly created client with its one-time secret; passed straight to the view, never stored (e.g. in the session file). */
    private function render(?array $newClient): never
    {
        $tokens = Db::all(
            'SELECT t.id, t.client_id, t.created_at, t.last_used_at, t.last_ip, t.user_agent, t.access_expires_at, c.name, c.redirect_uris
             FROM oauth_tokens t LEFT JOIN mcp_clients c ON c.client_id = t.client_id
             WHERE t.revoked_at IS NULL AND t.refresh_expires_at > ? ORDER BY t.id DESC',
            [now_iso()]
        );
        view('connections', [
            'title' => 'Connections',
            'loggedIn' => true,
            'clients' => Clients::all(),
            'tokens' => $tokens,
            'newClient' => $newClient,
            'googleConnected' => Token::hasRefreshToken(),
            'googleEmail' => Token::email(),
            'mcpUrl' => base_url() . '/mcp',
            'googleRedirect' => base_url() . '/oauth/google/callback',
            'allowedEmail' => (string) config('allowed_email', ''),
        ]);
    }

    public function add(): void
    {
        require_admin();
        $name = mb_substr(post('name'), 0, 80);
        $type = post('type');
        $scope = post('scope') ?: Clients::SCOPE_WRITE;
        if ($name === '' || !in_array($type, Clients::TYPES, true) || !in_array($scope, Clients::SCOPES, true)) {
            flash('error', 'Enter a name and choose the client type and access.');
            redirect('/connections');
        }
        $newClient = Clients::create($name, $type, $scope) + ['name' => $name, 'type' => $type, 'scope' => $scope];
        Events::log('connections', ['event' => 'client_add', 'client' => $newClient['client_id'], 'name' => $name, 'scope' => $scope]);
        // The secret is shown once, in this response (a reload shows the list without it).
        header('Cache-Control: no-store');
        $this->render($newClient);
    }

    public function delete(): void
    {
        require_admin();
        Clients::delete(post('client_id'));
        Events::log('connections', ['event' => 'client_delete', 'client' => post('client_id')]);
        flash('ok', 'Client deleted together with its connections.');
        redirect('/connections');
    }

    /** Renews a static (permanent-token) connection for another full lifetime; the token string does not change. */
    public function renew(): void
    {
        require_admin();
        $renewed = Clients::renewStaticToken((int) post('id'));
        Events::log('connections', ['event' => 'token_renew', 'tokenRowId' => (int) post('id'), 'ok' => $renewed]);
        flash($renewed ? 'ok' : 'error', $renewed ? 'Token renewed for another ' . Clients::staticTokenDays() . ' days.' : 'This connection cannot be renewed.');
        redirect('/connections');
    }

    public function revoke(): void
    {
        require_admin();
        Db::exec('UPDATE oauth_tokens SET revoked_at = ? WHERE id = ?', [now_iso(), (int) post('id')]);
        Events::log('connections', ['event' => 'token_revoke', 'tokenRowId' => (int) post('id')]);
        flash('ok', 'Connection disconnected.');
        redirect('/connections');
    }
}
