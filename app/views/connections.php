<?php
/** @var list<array> $clients @var list<array> $tokens @var ?array $newClient @var bool $googleConnected @var string $googleEmail @var string $mcpUrl @var string $googleRedirect @var string $allowedEmail */
$codeCmd = 'MCP_CLIENT_SECRET=<CLIENT_SECRET> claude mcp add --scope user --transport http --client-id <CLIENT_ID> --client-secret google-ads-php ' . $mcpUrl;
?>
<h1>Connections</h1>

<section class="card">
    <h2>Google account</h2>
    <?php if ($googleConnected): ?>
        <p>Connected as <strong><?= e($googleEmail ?: 'unknown account') ?></strong>.</p>
        <form method="post" action="<?= e(url('/google/disconnect')) ?>" onsubmit="return confirm('Disconnect the Google account? The tools will stop working until you reconnect.')"><?= csrf_field() ?><button type="submit" class="danger">Disconnect from Google</button></form>
    <?php else: ?>
        <p>The Google account is not connected.</p>
        <ol>
            <li>One time only: in <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credentials</a>
                open the OAuth client (the same one as in the server configuration) and under "Authorized redirect URIs" add:
                <code><?= e($googleRedirect) ?></code> → Save (the change takes effect after a few minutes).</li>
            <li>Click the button below and sign in with the account <strong><?= e($allowedEmail ?: '(allowed_email is missing from the configuration)') ?></strong> —
                any other account will be rejected.</li>
            <li>On the consent screen tick <strong>all</strong> the permissions (Google Ads and Analytics).</li>
        </ol>
        <p>The error <code>redirect_uri_mismatch</code> = step 1 was not done or has not taken effect yet.</p>
        <p><a class="button" href="<?= e(url('/google/connect')) ?>">Connect with Google</a></p>
    <?php endif; ?>
</section>

<?php if ($newClient): ?>
    <section class="card highlight">
        <h2>New client: <?= e($newClient['name']) ?></h2>
        <p>Save the details now — the secret is shown only once.</p>
        <p>Client ID: <code><?= e($newClient['client_id']) ?></code></p>
        <?php if (($newClient['type'] ?? '') !== 'claude-code-token'): ?>
            <p>Client secret: <code><?= e($newClient['secret']) ?></code></p>
        <?php endif; ?>

        <?php if (($newClient['type'] ?? '') === 'claude-code-token'): ?>
            <h3>How to add to Claude Code (permanent token, no browser)</h3>
            <ol>
                <li>Paste this in a terminal (also over SSH, outside Claude Code). The first line removes any old entry — a "not found" error is then normal:
                    <?php $tokenCmd = "claude mcp remove google-ads-php -s user\nclaude mcp add --scope user --transport http google-ads-php "
                        . $mcpUrl . ' --header "Authorization: Bearer ' . $newClient['token'] . '"'; ?>
                    <pre><code><?= e($tokenCmd) ?></code></pre>
                    <button type="button" data-copy="<?= e($tokenCmd) ?>">Copy command to clipboard</button></li>
                <li>Restart Claude Code (e.g. <code>claude --continue</code>). In <code>/mcp</code>, google-ads-php should show "connected" right away — no Authenticate needed.</li>
                <li>The token expires after <?= e(\App\Lib\Clients::staticTokenDays()) ?> days (renew it under "Active connections": the token itself does not change).
                    You cut it off earlier with the "Disconnect" button under "Active connections" or "Delete" next to the client.</li>
            </ol>
        <?php endif; ?>
        <?php if (($newClient['type'] ?? '') === 'claude-code'): ?>
            <h3>How to add to Claude Code</h3>
            <ol>
                <li>Paste this in a terminal (outside Claude Code). The first line removes any old entry with this name — a "not found" error is then normal:
                    <?php $addCmd = "claude mcp remove google-ads-php -s user\nMCP_CLIENT_SECRET=" . $newClient['secret']
                        . ' claude mcp add --scope user --transport http --client-id ' . $newClient['client_id']
                        . ' --client-secret google-ads-php ' . $mcpUrl; ?>
                    <pre><code><?= e($addCmd) ?></code></pre>
                    <button type="button" data-copy="<?= e($addCmd) ?>">Copy command to clipboard</button></li>
                <li>Restart Claude Code (e.g. <code>claude --continue</code>) — a running session does not see newly added connectors.</li>
                <li>Type <code>/mcp</code> → <strong>google-ads-php</strong> → <strong>Authenticate</strong>. The browser will open and return right away
                    (no Google sign-in). The status will change to "connected", and the client will appear below under "Active connections".</li>
            </ol>
        <?php else: ?>
            <h3>How to add to claude.ai</h3>
            <ol>
                <li>claude.ai → Settings → Connectors → <strong>Add custom connector</strong>. Any name, URL: <code><?= e($mcpUrl) ?></code></li>
                <li>"Advanced settings": OAuth Client ID <code><?= e($newClient['client_id']) ?></code>, OAuth Client Secret — the secret above → Add.</li>
                <li>Click <strong>Connect</strong> (no Google sign-in), then enable the connector in a conversation or project (tools icon).</li>
            </ol>
        <?php endif; ?>
        <p>Problem? Logs are written on the server (data directory, logs/app-YYYY-MM-DD.log). Delete unused clients in the "MCP clients" table.</p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>MCP clients</h2>
    <?php if ($clients): ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Name</th><th>Client ID</th><th>Type</th><th>Access</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?></td>
                    <td><code><?= e($c['client_id']) ?></code></td>
                    <td><?= e(\App\Lib\Clients::typeOf($c)) ?></td>
                    <td><?= e($c['scope'] === 'ads.read' ? 'read-only' : 'read and write') ?></td>
                    <td><?= e($c['created_at']) ?></td>
                    <td><form method="post" action="<?= e(url('/connections/delete')) ?>" onsubmit="return confirm('Delete the client and its connections?')">
                        <?= csrf_field() ?><input type="hidden" name="client_id" value="<?= e($c['client_id']) ?>">
                        <button type="submit" class="danger">Delete</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted">No clients — add the first one in the form below.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/connections/add')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <label for="name">Name</label>
        <input id="name" name="name" maxlength="80" required>
        <label for="scope">Access</label>
        <select id="scope" name="scope">
            <option value="ads.write">read and write (can prepare plans)</option>
            <option value="ads.read">read-only (reports only, cannot prepare plans)</option>
        </select>
        <label for="type">Type</label>
        <select id="type" name="type">
            <option value="claude-ai">claude-ai (claude.ai)</option>
            <option value="claude-code">claude-code (Claude Code, OAuth in the browser)</option>
            <option value="claude-code-token">claude-code-token (Claude Code, permanent token — for SSH)</option>
        </select>
        <button type="submit">Add client</button>
    </form>
</section>

<section class="card">
    <h2>Active connections</h2>
    <?php if ($tokens): ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Client</th><th>Created</th><th>Last used</th><th>Expires</th><th>IP</th><th>User agent</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= e($t['name'] ?? $t['client_id']) ?></td>
                    <td><?= e($t['created_at']) ?></td>
                    <td><?= e($t['last_used_at'] ?? '—') ?>
                        <?php if (\App\Lib\Clients::isUnused($t)): ?><br><span class="muted">Not used for 30+ days: disconnect it if it is no longer needed.</span><?php endif; ?></td>
                    <td><?php if (\App\Lib\Clients::isStaticToken($t)): ?>
                        <?php $until = (string) $t['access_expires_at']; ?>
                        <?= $until < '2090' ? e($until) : 'never' ?><?= $until < now_iso() ? ' <strong>(expired)</strong>' : '' ?>
                        <form method="post" action="<?= e(url('/connections/renew')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
                            <button type="submit"><?= $until >= '2090' ? 'Set expiry' : 'Renew' ?> (<?= e(\App\Lib\Clients::staticTokenDays()) ?> days)</button></form>
                        <?php else: ?>—<?php endif; ?></td>
                    <td><?= e($t['last_ip'] ?? '—') ?></td>
                    <td class="ua"><?= e($t['user_agent'] ?? '—') ?></td>
                    <td><form method="post" action="<?= e(url('/connections/revoke')) ?>" onsubmit="return confirm('Disconnect this connection? The client will lose access until it authenticates again.')">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
                        <button type="submit" class="danger">Disconnect</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="muted">Nobody is connected. Add an MCP client above and connect it following the instructions below.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>How to connect</h2>
    <p>Order: 1) connect the Google account (the "Google account" section above), 2) add an MCP client in the "MCP clients" section and save the secret (shown only once),
        3) add the connector in claude.ai or Claude Code. Once connected, the client appears under "Active connections"; problems show up in the logs/app-YYYY-MM-DD.log files on the server.</p>
    <h3>claude.ai</h3>
    <ol>
        <li>Add a client of type <strong>claude-ai</strong> and copy the Client ID and Client secret.</li>
        <li>claude.ai → Settings → Connectors → Add custom connector. URL: <code><?= e($mcpUrl) ?></code></li>
        <li>"Advanced settings": enter the Client ID and Client secret → Add → Connect.</li>
        <li>In a conversation or project, enable the connector (tools icon).</li>
    </ol>
    <h3>Claude Code</h3>
    <p>Add a client of type claude-code, put its details into the command and run it in a terminal; then restart Claude Code and in <code>/mcp</code> choose google-ads-php → Authenticate. After the client is added, a ready command with its details appears at the top of the page.</p>
    <pre><code><?= e($codeCmd) ?></code></pre>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
// The "Copy" button: puts the command on the clipboard (without JS the command can be selected by hand).
document.querySelectorAll('button[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
            var t = b.textContent; b.textContent = 'Copied!';
            setTimeout(function () { b.textContent = t; }, 2000);
        });
    });
});
</script>
