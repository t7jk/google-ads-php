<?php
/**
 * Home page. Logged out: passwordless login (e-mail -> link). Logged in: shortcuts to the tabs.
 * @var bool $loggedIn @var ?string $sentTo @var int $ttlMinutes @var bool $mailReady @var string $fallbackFile @var bool $needsSetup @var bool $phpMail
 */
?>
<h1 class="hero">Server Google Ads MCP PHP working...</h1>
<?php if (!$loggedIn && $sentTo !== null): ?>
    <section class="card narrow" aria-live="polite">
        <h2>Check your inbox</h2>
        <p>If <strong><?= e($sentTo) ?></strong> is the administrator address, we have sent a login link to it.
            The link works once and is valid for <?= e($ttlMinutes) ?> min.</p>
        <ul class="muted">
            <li>Can't see the message? Check your spam / promotions folder.</li>
            <li>Open the link in the same browser you want to work in.</li>
            <?php if (!$mailReady): ?>
                <li>Mail sending is not configured yet — the link was saved on the server in the file
                    <code><?= e($fallbackFile) ?></code> in the data directory (access via FTP/SSH).</li>
            <?php endif; ?>
        </ul>
        <p><a href="<?= e(url('/')) ?>">Send the link again / use another address</a></p>
    </section>
<?php elseif (!$loggedIn): ?>
    <form method="post" action="<?= e(url('/login')) ?>" class="card narrow">
        <?= csrf_field() ?>
        <h2>Log in</h2>
        <p class="muted">No password: enter the administrator's e-mail address and we will send you a login link.</p>
        <label for="email">E-mail address</label>
        <input id="email" name="email" type="email" autocomplete="username" inputmode="email" required autofocus>
        <button type="submit">Send login link</button>
    </form>
<?php else: ?>
    <?php if ($needsSetup): ?>
        <p class="flash error" role="alert">Mail is not configured — login links go only to a file on the server.
            Fill in the details in <a href="<?= e(url('/settings')) ?>">Settings</a>.</p>
    <?php endif; ?>
    <?php if ($phpMail): ?>
        <p class="flash error" role="note">E-mail is sent with PHP mail(), which is not trusted: messages are often lost or marked as spam.
            Configure SMTP in <a href="<?= e(url('/settings')) ?>">Settings</a>.</p>
    <?php endif; ?>
    <div class="tiles">
        <a class="card tile" href="<?= e(url('/connections')) ?>"><strong>Connections</strong><span class="muted">Google account, MCP clients (claude.ai, Claude Code), active connections.</span></a>
        <a class="card tile" href="<?= e(url('/upload')) ?>"><strong>Upload</strong><span class="muted">Images and YouTube videos for campaigns.</span></a>
        <a class="card tile" href="<?= e(url('/settings')) ?>"><strong>Settings</strong><span class="muted">Administrator address and mail server (SMTP).</span></a>
    </div>
<?php endif; ?>
