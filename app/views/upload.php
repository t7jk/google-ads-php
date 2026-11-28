<?php
/**
 * Upload page: images (JPG/PNG) + YouTube videos + a list of recent uploads.
 * @var string $q ('' for the admin or '?t=<token>') @var ?array $result result of the last POST (PRG)
 * @var list<array> $recent @var int $maxFiles @var int|float $maxMb @var int $ttlHours
 */
use App\Ads\Plural;
use App\Media\YoutubeChannel;

$thumbUrl = fn(string $id) => url('/upload/thumb') . ($q !== '' ? $q . '&' : '?') . 'id=' . rawurlencode($id);
?>
<h1>Upload</h1>
<p class="muted">Upload images or pick YouTube videos, then tell Claude in the conversation "images uploaded" or "videos selected" — it will read the identifiers itself (the <code>recent_uploads</code> tool). Uploads are kept for <?= e($ttlHours) ?> h.</p>

<?php if ($result): ?>
    <section class="card highlight" aria-labelledby="res-h">
    <?php if ($result['type'] === 'images'): $ok = array_filter($result['rows'], fn($r) => !isset($r['error'])); ?>
        <h2 id="res-h">Upload result</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>File</th><th>Dimensions</th><th>Aspect ratio / PMax type</th><th>uploadId</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $r): ?>
                <tr><td><?= e($r['name']) ?></td>
                <?php if (isset($r['error'])): ?>
                    <td colspan="3" class="bad"><?= e($r['error']) ?></td>
                <?php else: ?>
                    <td><?= e($r['dims']) ?></td><td><?= e($r['kind']) ?></td>
                    <td><code><?= e($r['uploadId']) ?></code> <button type="button" class="small" data-copy="<?= e($r['uploadId']) ?>">copy</button></td>
                <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($ok): $all = "Uploaded images:\n" . implode("\n", array_map(fn($r) => "- {$r['name']}: uploadId {$r['uploadId']} ({$r['dims']}, {$r['kind']})", $ok)); ?>
            <p><button type="button" data-copy="<?= e($all) ?>">Copy all for Claude</button></p>
        <?php endif; ?>
    <?php else: ?>
        <h2 id="res-h">Videos selected</h2>
        <ul class="plain">
        <?php foreach ($result['rows'] as $r): ?>
            <li><b><?= e($r['title']) ?></b> — <code><?= e($r['youtubeId']) ?></code>
                <?php if ($r['durationSec'] !== null): ?>(<?= e(YoutubeChannel::formatDuration((int) $r['durationSec'])) ?>)<?php endif; ?>
                <?php if ($r['durationSec'] !== null && $r['durationSec'] < YoutubeChannel::MIN_PMAX_VIDEO_SEC): ?><span class="warn">too short for PMax (min. <?= e(YoutubeChannel::MIN_PMAX_VIDEO_SEC) ?> s)</span><?php endif; ?>
                <?php if ($r['unavailable']): ?><span class="bad">unavailable/private</span><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
        <?php $all = "Selected YouTube videos:\n" . implode("\n", array_map(fn($r) => "- {$r['title']}: YouTube {$r['youtubeId']}", $result['rows'])); ?>
        <p class="ok-text">Saved <?= e(Plural::withCount(count($result['rows']), 'video', 'videos', 'videos')) ?>.</p>
        <p><button type="button" data-copy="<?= e($all) ?>">Copy all for Claude</button></p>
    <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card" aria-labelledby="img-h">
    <h2 id="img-h">Images</h2>
    <form method="post" action="<?= e(url('/upload') . $q) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label for="files">JPG or PNG files (up to <?= e($maxFiles) ?> at once, each up to <?= e($maxMb) ?> MB)</label>
        <input id="files" type="file" name="files[]" multiple required accept="image/jpeg,image/png">
        <button type="submit">Upload</button>
    </form>
</section>

<section class="card" aria-labelledby="yt-h">
    <h2 id="yt-h">YouTube videos</h2>
    <form method="post" action="<?= e(url('/upload/youtube/list') . $q) ?>">
        <?= csrf_field() ?>
        <label for="yt-input">YouTube channel URL or video links (one per line)</label>
        <textarea id="yt-input" name="input" rows="5" maxlength="<?= e(YoutubeChannel::MAX_INPUT_CHARS) ?>" required
            placeholder="https://www.youtube.com/@ChannelName&#10;or video links, one per line:&#10;https://youtu.be/xxxxxxxxxxx"></textarea>
        <p class="muted">You can paste a channel and links together — the result is merged without duplicates. Next you select the videos to approve.</p>
        <button type="submit">Show videos</button>
    </form>
</section>

<section class="card" aria-labelledby="rec-h">
    <h2 id="rec-h">Recent uploads (<?= e($ttlHours) ?> h)</h2>
    <?php if (!$recent): ?>
        <p class="muted">Nothing uploaded yet.</p>
    <?php else: ?>
        <ul class="thumbs">
        <?php foreach ($recent as $f): ?>
            <?php if (($f['type'] ?? '') === 'youtube'): ?>
                <li><img src="https://i.ytimg.com/vi/<?= e($f['youtubeId']) ?>/mqdefault.jpg" alt="" loading="lazy" width="160" height="90">
                    <span><b><?= e($f['title']) ?></b><br>YouTube <code><?= e($f['youtubeId']) ?></code>
                    <button type="button" class="small" data-copy="<?= e($f['youtubeId']) ?>">copy ID</button></span></li>
            <?php else: ?>
                <li><img src="<?= e($thumbUrl($f['uploadId'])) ?>" alt="Preview: <?= e($f['name']) ?>" loading="lazy" width="160" height="90">
                    <span><b><?= e($f['name']) ?></b><br><?= e($f['width']) ?>×<?= e($f['height']) ?><br><code><?= e($f['uploadId']) ?></code>
                    <button type="button" class="small" data-copy="<?= e($f['uploadId']) ?>">copy uploadId</button></span></li>
            <?php endif; ?>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
// "Copy" buttons: the page still works without JS (the IDs can be selected by hand).
document.querySelectorAll('button[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.textContent = 'copied'; });
    });
});
</script>
