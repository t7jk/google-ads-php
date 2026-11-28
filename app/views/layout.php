<?php /** @var string $content @var string $title */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
</head>
<body>
<?php if (is_admin()): ?>
    <?php $here = '/' . trim((string) substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen(base_path())), '/'); ?>
    <nav class="menu" aria-label="Main menu">
        <a href="<?= e(url('/')) ?>"<?= $here === '/' ? ' aria-current="page"' : '' ?>>Home</a>
        <a href="<?= e(url('/connections')) ?>"<?= $here === '/connections' ? ' aria-current="page"' : '' ?>>Connections</a>
        <a href="<?= e(url('/upload')) ?>"<?= $here === '/upload' ? ' aria-current="page"' : '' ?>>Upload</a>
        <a href="<?= e(url('/settings')) ?>"<?= $here === '/settings' ? ' aria-current="page"' : '' ?>>Settings</a>
        <a href="<?= e(url('/logs')) ?>"<?= $here === '/logs' ? ' aria-current="page"' : '' ?>>Logs</a>
        <a href="<?= e(url('/help')) ?>"<?= $here === '/help' ? ' aria-current="page"' : '' ?>>Help</a>
        <a href="<?= e(url('/about')) ?>"<?= $here === '/about' ? ' aria-current="page"' : '' ?>>About</a>
        <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit" class="link">Log out</button></form>
    </nav>
<?php endif; ?>
<main>
    <?php foreach (flash_take() as [$type, $message]): ?>
        <p class="flash <?= $type === 'ok' ? 'ok' : 'error' ?>" role="<?= $type === 'ok' ? 'status' : 'alert' ?>"><?= e($message) ?></p>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<script nonce="<?= e(csp_nonce()) ?>">
// Guards against double form submission (e.g. two login links): disables the button after submit.
document.addEventListener('submit', function (ev) {
    setTimeout(function () {
        if (ev.defaultPrevented) { return; }
        ev.target.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
    }, 0);
});
</script>
</body>
</html>
