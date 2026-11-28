<?php
/**
 * Settings tab (SettingsController): administrator address and SMTP.
 * The SMTP password is never written into the form — an empty field = no change.
 * @var string $adminEmail @var string $pendingEmail @var array<string,string> $smtp @var bool $hasPassword
 * @var array<string,string> $encryptions @var string $fallbackFile
 */
?>
<h1>Settings</h1>

<section class="card">
    <h2>Administrator</h2>
    <p>The only user of the panel. Passwordless login: a one-time link is sent to this address.</p>
    <?php if ($pendingEmail !== ''): ?>
        <div class="flash ok" role="status">
            Awaiting confirmation: <strong><?= e($pendingEmail) ?></strong>. Click the link in the e-mail sent to that address.
            <form method="post" action="<?= e(url('/settings/email/cancel')) ?>" class="inline">
                <?= csrf_field() ?><button type="submit" class="link">Cancel change</button>
            </form>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/settings/email')) ?>">
        <?= csrf_field() ?>
        <label for="admin_email">Administrator e-mail address (login)</label>
        <input id="admin_email" name="admin_email" type="email" value="<?= e($adminEmail) ?>" autocomplete="email" required
               aria-describedby="admin_email_help">
        <p id="admin_email_help" class="help">After the change we will send a confirmation link to the new address. Until you click it, the current address stays valid —
            a typo will not lock you out.</p>
        <button type="submit">Change address</button>
    </form>
</section>

<section class="card">
    <h2>Mail server (SMTP)</h2>
    <p>Used to send login links. You will find the details in your hosting panel (e.g. cPanel → Email Accounts → "Connect Devices").
        Without SMTP the login link is saved on the server in the file <code><?= e($fallbackFile) ?></code> (data directory, FTP access).</p>
    <form method="post" action="<?= e(url('/settings/smtp')) ?>" class="grid-form">
        <?= csrf_field() ?>
        <?php require __DIR__ . '/_smtp_fields.php'; ?>
        <div class="span-2"><button type="submit">Save SMTP settings</button></div>
    </form>
    <form method="post" action="<?= e(url('/settings/smtp/test')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="secondary">Send test e-mail to <?= e($adminEmail) ?></button>
    </form>
</section>
