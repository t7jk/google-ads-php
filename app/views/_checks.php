<?php
/**
 * A list of installer checks (Install\Check): status word + label + what to do. The word is text, not only a colour.
 * @var list<\App\Install\Check> $list
 */
use App\Install\Check;

$words = [Check::OK => 'OK', Check::WARN => 'WARN', Check::FAIL => 'FAIL'];
?>
<ul class="checks">
    <?php foreach ($list as $check): ?>
        <li class="check-<?= e($check->status) ?>">
            <strong class="badge"><?= e($words[$check->status]) ?></strong>
            <span><?= e($check->label) ?><?php if ($check->detail !== ''): ?><br><span class="help"><?= e($check->detail) ?></span><?php endif; ?></span>
        </li>
    <?php endforeach; ?>
</ul>
