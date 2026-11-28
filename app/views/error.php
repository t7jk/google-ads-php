<?php /** @var string $message */ ?>
<h1><?= e($title ?? 'Error') ?></h1>
<p><?= e($message) ?></p>
<p><a href="<?= e(url('/')) ?>">Home</a></p>
