<?php
declare(strict_types=1);

namespace App\Plans;

/** Plan preview HTML for the server page (port of renderPlan from ui/render.ts, "web" mode). All values go through e(). */
final class PlanRenderer
{
    private const IMAGE_HOSTS = ['tpc.googlesyndication.com', 'googleusercontent.com'];

    private static function imageAllowed(string $src, string $base): bool
    {
        if ($base !== '' && str_starts_with($src, $base)) {
            return true;
        }
        $p = parse_url($src);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower($p['host'] ?? '');
        return $host === 'tpc.googlesyndication.com' || str_ends_with($host, '.googleusercontent.com');
    }

    private static function cell(?string $v, ?string $image, ?string $alt, ?string $note, string $base): string
    {
        $thumb = $image && self::imageAllowed($image, $base)
            ? '<img src="' . e($image) . '" alt="' . e($alt ?? '') . '" loading="lazy" style="max-width:120px;max-height:120px;display:block;margin-bottom:4px">' : '';
        return '<td>' . $thumb . e($v ?? '—') . ($note ? '<div class="sub">' . e($note) . '</div>' : '') . '</td>';
    }

    private static function row(array $d, string $base): string
    {
        return '<tr class="' . e($d['change']) . (($d['level'] ?? null) === 'danger' ? ' danger' : '') . '"><td>' . e($d['label']) . '</td>'
            . self::cell($d['before'], $d['beforeImage'] ?? null, $d['imageAlt'] ?? null, null, $base)
            . self::cell($d['after'], $d['afterImage'] ?? null, $d['imageAlt'] ?? null, $d['note'] ?? null, $base) . '</tr>';
    }

    /**
     * @param array $view result of Plan::view; $csrf: link token (hidden field of the approval form)
     * @param ?string $loginHtml sign-in block shown INSTEAD of the Approve button of a pending plan (null = admin is logged in)
     */
    public static function html(array $view, string $csrf, string $actionUrl, ?string $loginHtml = null): string
    {
        $base = (string) ($view['imageBase'] ?? '');
        $head = '<tr><th>Item</th><th>Before</th><th>After</th></tr>';
        $diff = '';
        foreach (array_unique(array_column($view['diff'], 'section')) as $section) {
            $rows = array_values(array_filter($view['diff'], fn($d) => $d['section'] === $section));
            $collapse = count($rows) > 30;
            $info = $collapse ? array_filter($rows, fn($d) => $d['change'] === 'info') : [];
            $shown = $collapse ? array_filter($rows, fn($d) => $d['change'] !== 'info') : $rows;
            $more = $info ? '<details><summary>Others (' . count($info) . ')</summary><table>' . $head . implode('', array_map(fn($d) => self::row($d, $base), $info)) . '</table></details>' : '';
            $diff .= '<h2>' . e($section) . '</h2><table>' . $head . implode('', array_map(fn($d) => self::row($d, $base), $shown)) . '</table>' . $more;
        }
        $warnings = implode('', array_map(fn($w) => '<div class="warn">' . e($w) . '</div>', $view['warnings']));
        $errors = $view['validation']['ok'] ? '' : implode('', array_map(fn($x) => '<div class="err">' . e($x) . '</div>', $view['validation']['errors']));
        $exp = (new \DateTimeImmutable($view['expiresAt']))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
        $finished = in_array($view['status'], ['done', 'failed', 'refused'], true);
        $paused = $view['kind'] === 'new_campaign' || ($view['campaignState'] ?? null) === 'PAUSED';
        $invalidPending = $view['status'] === 'pending' && !$view['validation']['ok'];
        $note = $finished || $invalidPending || !$paused ? '' : '<p class="note">The campaign will stay paused after approval.</p>';
        $disabled = !$view['validation']['ok'] || $view['status'] !== 'pending' ? ' disabled' : '';
        $msg = isset($view['resultMessage']) ? ': ' . $view['resultMessage'] : '';
        if ($finished) {
            $action = '<div class="' . ($view['status'] === 'done' ? 'okbox' : 'err') . '" role="status">' . e(($view['status'] === 'done' ? 'Done' : 'Not executed') . $msg) . '</div>';
        } elseif ($invalidPending) {
            $action = '<div class="err" role="status">Not executed: the plan did not pass Google validation</div>';
        } elseif ($loginHtml !== null) {
            $action = $loginHtml;
        } else {
            $action = '<form method="post" action="' . e($actionUrl) . '"><input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="planId" value="' . e($view['id']) . '"><input type="hidden" name="planHash" value="' . e($view['hash']) . '"><button type="submit"' . $disabled . '>Approve</button></form>';
        }
        return '<div class="plan"><h1>' . e($view['summary']) . '</h1>' . (!empty($view['campaignState']) ? '<p class="exp">Campaign: ' . e($view['campaignState']) . '</p>' : '')
            . $diff . $warnings . $errors . $note . '<p class="exp">Valid until ' . e($exp) . '</p>' . $action . '</div>';
    }
}
