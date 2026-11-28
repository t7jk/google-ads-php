<?php
/**
 * Logs tab (LogsController): the newest application events, newest first. Read-only.
 * @var string $filter @var array<string,string> $filters @var list<array{ts:string,type:string,client:string,ip:string,summary:string,failed:bool}> $rows @var int $limit
 */
?>
<h1>Logs</h1>

<section class="card">
    <p>The last <?= e($limit) ?> events of the application log, newest first (times are UTC). Secrets and link tokens are masked.
        Older days are included when today has fewer events.</p>
    <nav class="filters" aria-label="Log filter">
        <?php foreach ($filters as $key => $label): ?>
            <a class="button<?= $key === $filter ? '' : ' secondary' ?>" href="<?= e(url('/logs') . ($key === 'all' ? '' : '?f=' . $key)) ?>"<?= $key === $filter ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($rows === []): ?>
        <p class="muted">No events to show<?= $filter === 'all' ? ' (there is no log yet, or it is empty).' : ' for this filter.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="log-table">
                <thead><tr><th>Time (UTC)</th><th>Event</th><th>Client</th><th>IP</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr<?= $row['failed'] ? ' class="failed"' : '' ?>>
                        <td><?= e($row['ts']) ?></td>
                        <td><?= e($row['type']) ?><?= $row['failed'] ? ' <strong class="bad">failed</strong>' : '' ?></td>
                        <td><?= e($row['client']) ?></td>
                        <td><?= e($row['ip']) ?></td>
                        <td class="ua"><?= e($row['summary']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
