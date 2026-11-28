<?php
/**
 * Choosing YouTube videos after the pasted addresses are recognized.
 * @var string $q @var string $input @var array{videos:list<array>,notes:list<string>,problems:list<string>} $r @var int $minSec @var int $maxSelected
 */
use App\Media\YoutubeChannel;

?>
<h1>Choose YouTube videos</h1>
<p><a href="<?= e(url('/upload') . $q) ?>">Back to the Upload page</a></p>

<?php foreach ($r['notes'] as $n): ?><p class="muted"><?= e($n) ?></p><?php endforeach; ?>
<?php if ($r['problems']): ?>
    <ul class="bad"><?php foreach ($r['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php if (!$r['videos']): ?>
    <p>No videos found.</p>
<?php else: ?>
    <form method="post" action="<?= e(url('/upload/youtube/select') . $q) ?>">
        <?= csrf_field() ?>
        <h2>Videos (<?= e(count($r['videos'])) ?>)</h2>
        <div class="yt-grid">
        <?php foreach ($r['videos'] as $v): $off = !empty($v['unavailable']); $short = isset($v['durationSec']) && $v['durationSec'] < $minSec; ?>
            <div class="card yt-card<?= $off ? ' off' : '' ?>">
                <label>
                    <input type="checkbox" name="v[]" value="<?= e($v['id']) ?>" <?= $off ? 'disabled' : '' ?>>
                    <img src="https://i.ytimg.com/vi/<?= e($v['id']) ?>/mqdefault.jpg" alt="" loading="lazy" width="320" height="180">
                    <b><?= e($v['title'] !== '' ? $v['title'] : $v['id']) ?></b>
                </label>
                <p class="muted">
                    <?= e(implode(' · ', array_filter([isset($v['published']) ? substr((string) $v['published'], 0, 10) : '', isset($v['durationSec']) ? YoutubeChannel::formatDuration($v['durationSec']) : '']))) ?>
                </p>
                <?php if ($off): ?><p class="bad">unavailable/private</p><?php elseif ($short): ?><p class="warn">too short for PMax (min. <?= e($minSec) ?> s)</p><?php endif; ?>
                <a href="https://www.youtube.com/watch?v=<?= e($v['id']) ?>" target="_blank" rel="noopener">open on YouTube</a>
            </div>
        <?php endforeach; ?>
        </div>
        <p><button type="submit">Confirm selection</button> <span class="muted">(max. <?= e($maxSelected) ?> videos)</span></p>
    </form>
<?php endif; ?>
