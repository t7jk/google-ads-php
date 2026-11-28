<?php
/**
 * Page shown after clicking the link from the e-mail. Login happens only after clicking the button (POST),
 * because mail scanners open links automatically (see Auth/LoginLinks.php).
 * @var string $token @var string $purpose @var string $email
 */
$isChange = $purpose === \App\Auth\LoginLinks::EMAIL_CHANGE;
?>
<form method="post" action="<?= e(url('/login/verify')) ?>" class="card narrow">
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <h1><?= $isChange ? 'Confirm new address' : 'Confirm login' ?></h1>
    <p><?= $isChange
        ? 'After confirmation, <strong>' . e($email) . '</strong> becomes the administrator address and you will be logged in.'
        : 'You are logging in as <strong>' . e($email) . '</strong>.' ?></p>
    <button type="submit" autofocus><?= $isChange ? 'Confirm and log in' : 'Log in' ?></button>
    <p class="muted">Didn't request this link? Just close this page.</p>
</form>
