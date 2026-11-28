<?php
/**
 * Installer step 1 (SetupController::requirementsStep): requirements and the protection test.
 * @var list<\App\Install\Check> $checks @var list<\App\Install\Check> $protection @var string $probedUrl @var bool $needsCode
 */
use App\Install\Check;

$blocked = Check::anyFailed([...$checks, ...$protection]);
?>
<h1>Installation</h1>
<p class="muted">Step 1 of 2: requirements. The installer checks that this server can run the application and that all files arrived intact.</p>
<p class="flash error" role="note">Finish the installation right away: until then anyone who knows this address could register as the administrator.</p>

<section class="card">
    <h2>Requirements</h2>
    <?php $list = $checks; require __DIR__ . '/_checks.php'; ?>
</section>

<section class="card">
    <h2>Are your private files safe?</h2>
    <p class="help">The installer tries to download protected files from <code><?= e($probedUrl) ?></code> (this is your address as the browser sent it).
        None of them may be reachable from the internet.</p>
    <?php $list = $protection; require __DIR__ . '/_checks.php'; ?>
</section>

<?php if ($blocked): ?>
    <p class="flash error" role="alert">Fix the items marked FAIL (instructions are under each one), then press "Check again".</p>
<?php endif; ?>

<form method="post" action="<?= e(url('/setup/requirements')) ?>" class="card">
    <?= csrf_field() ?>
    <?php if ($needsCode): ?>
        <label for="install_code">Installation code</label>
        <input id="install_code" name="install_code" autocomplete="off" required spellcheck="false">
        <p class="help">It is the single line in the file INSTALL_CODE.txt that you uploaded next to index.php.</p>
    <?php endif; ?>
    <button type="submit"<?= $blocked ? ' disabled' : '' ?>>Continue</button>
    <a class="button secondary" href="<?= e(url('/setup')) ?>">Check again</a>
</form>
