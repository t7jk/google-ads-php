<?php
declare(strict_types=1);

namespace App\AdsReport;

use App\Google\Ads;

/** C11: account hygiene: users (roles, masked e-mails), invitations, experiments, labels; current state. */
final class AccountHygiene
{
    private const ROLE_NAMES = ['ADMIN' => 'administrator', 'STANDARD' => 'standard', 'READ_ONLY' => 'read-only', 'EMAIL_ONLY' => 'email-only'];
    private const ACTIVE_EXP = ['ENABLED', 'INITIATED'];

    /** E-mail is only ever shown masked: first letter + "***@" + domain. Without "@" just "***". */
    public static function maskEmail(mixed $v): string
    {
        $s = trim((string) ($v ?? ''));
        $at = mb_strrpos($s, '@');
        return $at === false || $at < 1 ? '***' : mb_substr($s, 0, 1) . '***@' . mb_substr($s, $at + 1);
    }

    private static function roleName(mixed $role): mixed
    {
        return self::ROLE_NAMES[(string) $role] ?? $role;
    }

    public static function view(array $a): array
    {
        $exp = Fmt::guarded(fn() => Ads::search(Queries::experiments()));
        $lab = Fmt::guarded(fn() => Ads::search(Queries::labels()));
        $cl = Fmt::guarded(fn() => Ads::search(Queries::campaignLabels()));
        $gl = Fmt::guarded(fn() => Ads::search(Queries::adGroupLabels()));
        $usr = Fmt::guarded(fn() => Ads::search(Queries::userAccess()));
        $inv = Fmt::guarded(fn() => Ads::search(Queries::userAccessInvitation()));
        $notes = [];
        $denied = ['usr' => ['Account users: access denied (reading customer_user_access requires the ADMIN role or higher API access than Explorer).', $usr],
            'inv' => ['Invitations: access denied (ADMIN role required).', $inv], 'exp' => ['Experiments: access denied.', $exp], 'lab' => ['Labels: access denied.', $lab]];
        foreach ($denied as [$text, $g]) {
            if ($g['denied']) {
                $notes[] = $text;
            }
        }

        $rows = [];
        $byRole = [];
        foreach ($usr['value'] ?? [] as $r) {
            $u = $r['customerUserAccess'] ?? [];
            $role = (string) ($u['accessRole'] ?? '?');
            $byRole[$role] = ($byRole[$role] ?? 0) + 1;
            $rows[] = ['kind' => 'user', 'id' => (string) ($u['userId'] ?? ''), 'name' => self::maskEmail($u['emailAddress'] ?? null), 'role' => self::roleName($role),
                'status' => null, 'date' => Fmt::day($u['accessCreationDateTime'] ?? null), 'used' => null];
        }
        foreach ($inv['value'] ?? [] as $r) {
            $i = $r['customerUserAccessInvitation'] ?? [];
            $rows[] = ['kind' => 'invitation', 'id' => (string) ($i['invitationId'] ?? ''), 'name' => self::maskEmail($i['emailAddress'] ?? null), 'role' => self::roleName($i['accessRole'] ?? null),
                'status' => $i['invitationStatus'] ?? null, 'date' => Fmt::day($i['creationDateTime'] ?? null), 'used' => null];
        }
        $inactiveExp = 0;
        foreach ($exp['value'] ?? [] as $r) {
            $e = $r['experiment'] ?? [];
            if (!in_array((string) ($e['status'] ?? ''), self::ACTIVE_EXP, true)) {
                $inactiveExp++;
            }
            $rows[] = ['kind' => 'experiment', 'id' => (string) ($e['experimentId'] ?? Fmt::lastId($e['resourceName'] ?? null)), 'name' => $e['name'] ?? null, 'role' => null,
                'status' => $e['status'] ?? null, 'type' => $e['type'] ?? null, 'date' => Fmt::day($e['startDate'] ?? null), 'endDate' => Fmt::day($e['endDate'] ?? null), 'used' => null];
        }
        $use = [];
        foreach ($cl['value'] ?? [] as $r) {
            $id = Fmt::lastId(Fmt::get($r, 'campaignLabel.label'));
            $use[$id] = ($use[$id] ?? 0) + 1;
        }
        foreach ($gl['value'] ?? [] as $r) {
            $id = Fmt::lastId(Fmt::get($r, 'adGroupLabel.label'));
            $use[$id] = ($use[$id] ?? 0) + 1;
        }
        $unusedLabels = 0;
        foreach ($lab['value'] ?? [] as $r) {
            $l = $r['label'] ?? [];
            $n = $use[(string) ($l['id'] ?? '')] ?? 0;
            if (!$n) {
                $unusedLabels++;
            }
            $rows[] = ['kind' => 'label', 'id' => (string) ($l['id'] ?? ''), 'name' => $l['name'] ?? null, 'role' => null, 'status' => $l['status'] ?? null, 'used' => $n];
        }

        $roles = implode(', ', array_map(fn($k, $v) => (self::ROLE_NAMES[$k] ?? $k) . " $v", array_keys($byRole), $byRole));
        $users = count($usr['value'] ?? []);
        $pending = count(array_filter($inv['value'] ?? [], fn($r) => Fmt::get($r, 'customerUserAccessInvitation.invitationStatus') === 'PENDING'));
        $nExp = count($exp['value'] ?? []);
        $nLab = count($lab['value'] ?? []);
        $conclusion = implode('; ', [
            $usr['denied'] ? 'users: no access' : "users $users" . ($roles ? " ($roles)" : '') . ($pending ? ", pending invitations $pending" : ''),
            $exp['denied'] ? 'experiments: no access' : "experiments $nExp, inactive $inactiveExp",
            $lab['denied'] ? 'labels: no access' : "labels $nLab, unused $unusedLabels",
        ]);
        $note = implode(' ', $notes);
        return [
            'header' => "Account hygiene: $conclusion.",
            'rows' => $rows,
            'extra' => ['usersByRole' => (object) $byRole, 'users' => $users, 'pendingInvitations' => $pending, 'experiments' => $nExp, 'inactiveExperiments' => $inactiveExp, 'labels' => $nLab, 'unusedLabels' => $unusedLabels, 'notes' => $notes],
            'empty' => "Account hygiene: $conclusion. No users, experiments or labels to show." . ($note ? " $note" : ''),
            'footer' => 'E-mails are masked (first letter and domain). Inactive experiment = status other than ENABLED/INITIATED. Unused label = not attached to any campaign or ad group (used column; ad and keyword labels are not checked).' . ($note ? " $note" : ''),
        ];
    }
}
