<?php
/**
 * Installer step 2 (SetupController::adminStep): administrator, public address, optional SMTP. Saving runs the installation.
 * @var string $adminEmail @var string $publicUrl @var array<string,string> $smtp @var array<string,string> $encryptions
 */
$hasPassword = false;
$requireHost = false;
$hostHelp = 'Leave empty to use PHP mail() instead (see the warning above).';
?>
<h1>Installation</h1>
<p class="muted">Step 2 of 2: administrator. <a href="<?= e(url('/setup?step=1')) ?>">Back to the checks</a></p>
<p class="flash error" role="note">Finish the installation right away: until then anyone who knows this address could register as the administrator.</p>

<form method="post" action="<?= e(url('/setup')) ?>" class="card grid-form">
    <?= csrf_field() ?>
    <div class="span-2">
        <h2>Administrator</h2>
        <label for="admin_email">Your e-mail address</label>
        <input id="admin_email" name="admin_email" type="email" value="<?= e($adminEmail) ?>" autocomplete="email" required autofocus
               aria-describedby="admin_email_help">
        <p id="admin_email_help" class="help">The only account in the panel. You will be logged in right after the installation;
            later, login links are sent to this address (no password).</p>
        <h2>Address of this site</h2>
        <label for="public_base_url">Public address</label>
        <input id="public_base_url" name="public_base_url" type="url" value="<?= e($publicUrl) ?>" required spellcheck="false"
               aria-describedby="url_help">
        <p id="url_help" class="help">Exactly what people type in the browser, without a trailing "/". Links in e-mails and the connection
            to Google and Claude are built from it, so it must be right. We recommend https://.
            <?php if (str_starts_with($publicUrl, 'http://')): ?><strong class="warn">This address uses http:// (not encrypted).</strong><?php endif; ?></p>
        <h2>E-mail sending (optional)</h2>
        <p class="flash error" role="note">If you leave the mail server empty, PHP mail() is used. PHP mail() is not trusted (messages are often
            lost or marked as spam); we recommend configuring SMTP in Settings.</p>
        <p class="help">The details are in your hosting panel (e.g. cPanel &rarr; Email Accounts &rarr; "Connect Devices"). Most often: port 465 + SSL/TLS or
            587 + STARTTLS, username = the full mailbox address. If you fill them in, the installer sends you a test message first.</p>
    </div>
    <?php require __DIR__ . '/_smtp_fields.php'; ?>
    <div class="span-2">
        <button type="submit">Install and log me in</button>
        <p class="help">This creates the settings file and an empty database. It takes a few seconds.</p>
    </div>
</form>
