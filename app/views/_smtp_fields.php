<?php
/**
 * SMTP fields shared by the Settings tab and the install wizard (setup.php). Inserted inside <form class="grid-form">.
 * @var array<string,string> $smtp current values (without the password) @var bool $hasPassword @var array<string,string> $encryptions
 * @var bool $requireHost true when the SMTP host is required (not in the installer, where mail is optional)
 * @var string $hostHelp help under the host field (default: "Empty = sending disabled.")
 */
$requireHost ??= false;
$hostHelp ??= 'Empty = sending disabled.';
?>
        <div class="span-2">
            <label for="smtp_host">SMTP host</label>
            <input id="smtp_host" name="smtp_host" value="<?= e($smtp['smtp_host']) ?>" placeholder="mail.example.com" autocomplete="off" spellcheck="false"<?= $requireHost ? ' required' : '' ?>>
            <?php if (!$requireHost): ?><p class="help"><?= e($hostHelp) ?></p><?php endif; ?>
        </div>
        <div>
            <label for="smtp_encryption">Encryption</label>
            <select id="smtp_encryption" name="smtp_encryption">
                <?php foreach ($encryptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $smtp['smtp_encryption'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="smtp_port">Port</label>
            <input id="smtp_port" name="smtp_port" value="<?= e($smtp['smtp_port']) ?>" inputmode="numeric" pattern="[0-9]{1,5}" required>
        </div>
        <div>
            <label for="smtp_user">SMTP username</label>
            <input id="smtp_user" name="smtp_user" value="<?= e($smtp['smtp_user']) ?>" autocomplete="off" spellcheck="false">
            <p class="help">Usually the full mailbox address, e.g. noreply@example.com.</p>
        </div>
        <div>
            <label for="smtp_password">SMTP password</label>
            <input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password"
                   placeholder="<?= $hasPassword ? '•••••••• (saved)' : '' ?>">
            <p class="help"><?= $hasPassword ? 'Leave empty to keep it unchanged. The password is stored encrypted.' : 'The password is stored encrypted.' ?></p>
            <?php if ($hasPassword): ?>
                <label class="check"><input type="checkbox" name="smtp_password_clear" value="1"> Delete the saved password</label>
            <?php endif; ?>
        </div>
        <div>
            <label for="smtp_from_email">Sender address</label>
            <input id="smtp_from_email" name="smtp_from_email" type="email" value="<?= e($smtp['smtp_from_email']) ?>" placeholder="noreply@example.com"<?= $requireHost ? ' required' : '' ?>>
            <p class="help">Best to use the same as the username — otherwise mail may end up in spam.</p>
        </div>
        <div>
            <label for="smtp_from_name">Sender name</label>
            <input id="smtp_from_name" name="smtp_from_name" value="<?= e($smtp['smtp_from_name']) ?>" maxlength="80">
        </div>
