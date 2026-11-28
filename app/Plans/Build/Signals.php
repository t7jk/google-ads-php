<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Plans\{Cfg, Json, Plan, Text, Undo};
use App\Plans\Snapshot\PmaxSnapshot;

/**
 * PMax asset group signals (search themes and audiences) and custom audiences (port of build-signals.ts).
 * Assumptions: up to 50 themes per group, text up to 80 characters and 10 words; Google allows only ONE audience per group (a new one replaces
 * the old one only with removeAudience). Operations run in a fixed order: removals -> new audience (temporary ID -1) -> signals.
 * A custom audience is a separate plan (kind custom_audience, endpoint customAudiences:mutate; see Kinds/Pmax.php).
 */
final class Signals
{
    private const MAX_THEMES = 50;
    private const MAX_PHRASE_CHARS = 80;
    private const MAX_WORDS = 10;
    private const MAX_URL_CHARS = 2048;
    private const TH = 'Search themes';
    private const AU = 'Audiences';
    private const GENDER_LABEL = ['FEMALE' => 'female', 'MALE' => 'male', 'UNDETERMINED' => 'undetermined'];
    private const PARENT_LABEL = ['PARENT' => 'parent', 'NOT_A_PARENT' => 'not a parent', 'UNDETERMINED' => 'undetermined'];
    public const CUSTOM_AUDIENCE_NAMES_QUERY = "SELECT custom_audience.id, custom_audience.name, custom_audience.status FROM custom_audience WHERE custom_audience.status = 'ENABLED'";

    private array $errors = [];
    private array $warnings = [];
    private SectionRows $rows;
    private string $cid;
    private ?array $group = null;
    private string $groupRn = '';
    /** @var list<string> resourceNames of signals to remove */
    private array $removes = [];
    private array $creates = [];
    private array $audienceCreates = [];

    private function __construct(private array $req, private array $s, private \DateTimeImmutable $now)
    {
        $this->rows = new SectionRows();
        $this->cid = Cfg::customerId();
    }

    /** Fingerprint of the part of the snapshot the plan changes: the campaign, the given group, its signals and the account audiences. */
    public static function fingerprint(array $s, ?string $groupId): string
    {
        $inGroup = fn($r) => (string) ($r['assetGroup']['id'] ?? '') === $groupId;
        $s['assetGroups'] = $groupId ? array_values(array_filter($s['assetGroups'], $inGroup)) : [];
        $s['signals'] = $groupId ? array_values(array_filter($s['signals'], $inGroup)) : [];
        return PmaxSnapshot::fingerprint($s, ['campaign', 'assetGroups', 'signals', 'audiences']);
    }

    private static function words(string $t): int
    {
        return count(array_filter(preg_split('/\s+/u', Text::norm($t)) ?: [], fn($w) => $w !== ''));
    }

    /** Error for a theme or keyword text (≤80 characters, ≤10 words), or null. */
    private static function checkPhrase(string $what, string $t): ?string
    {
        $n = Text::len($t);
        if ($n === 0) {
            return "$what cannot be empty";
        }
        if ($n > self::MAX_PHRASE_CHARS) {
            return "$what has $n characters (max " . self::MAX_PHRASE_CHARS . '): "' . Text::norm($t) . '"';
        }
        $w = self::words($t);
        return $w > self::MAX_WORDS ? "$what has $w words (max " . self::MAX_WORDS . '): "' . Text::norm($t) . '"' : null;
    }

    /** @param array $req tool input + campaignId */
    public static function plan(array $req, array $snapshot, \DateTimeImmutable $now): array
    {
        return (new self($req, $snapshot, $now))->build();
    }

    private function build(): array
    {
        $camp = $this->s['campaign'];
        if (!$camp) {
            $this->errors[] = 'Campaign not found';
        }
        $ref = trim((string) $this->req['assetGroup']);
        $this->group = GroupLookup::find($this->s, $ref, $this->errors);
        $gid = $this->group ? (string) $this->group['id'] : null;
        $this->groupRn = $this->group ? (string) $this->group['resourceName'] : "customers/{$this->cid}/assetGroups/0";
        $mine = $gid !== null ? array_values(array_filter($this->s['signals'], fn($r) => (string) ($r['assetGroup']['id'] ?? '') === $gid)) : [];
        $themeRows = array_values(array_filter($mine, fn($r) => isset($r['assetGroupSignal']['searchTheme']['text'])));
        $audienceRows = array_values(array_filter($mine, fn($r) => isset($r['assetGroupSignal']['audience']['audience'])));
        $this->themes($themeRows);
        $this->audience($audienceRows);
        if (($camp['status'] ?? null) === 'ENABLED') {
            $this->warnings[] = 'The campaign is enabled; the change takes effect immediately';
        }
        $ops = [...array_map(fn($r) => ['assetGroupSignalOperation' => ['remove' => $r]], $this->removes), ...$this->audienceCreates, ...$this->creates];
        if (!$ops && !$this->errors) {
            $this->errors[] = 'Nothing to change';
        }
        return Undo::attach(array_merge(Plan::base($this->now), [
            'kind' => 'asset_group_signals', 'campaignId' => $this->req['campaignId'],
        ], $gid !== null ? ['assetGroupId' => $gid] : [], [
            'summary' => 'Asset group signals for "' . ($this->group['name'] ?? $ref) . '" in campaign "' . ($camp['name'] ?? $this->req['campaignId']) . '"',
            'diff' => $this->rows->flatten([self::TH, self::AU]),
            'steps' => $ops ? [['title' => 'Asset group signals', 'operations' => $ops]] : [],
            'warnings' => $this->warnings, 'validation' => ['ok' => !$this->errors, 'errors' => $this->errors],
            'beforeFingerprint' => self::fingerprint($this->s, $gid),
        ], $camp ? ['campaignState' => Text::stateLabel($camp)] : []), $this->s);
    }

    // ---- search themes ----

    private function themes(array $themeRows): void
    {
        $themeText = fn($r) => (string) $r['assetGroupSignal']['searchTheme']['text'];
        $th = $this->req['searchThemes'] ?? [];
        $removedRows = [];
        foreach ($th['remove'] ?? [] as $t) {
            $hit = null;
            foreach ($themeRows as $i => $r) {
                if (!isset($removedRows[$i]) && Text::same($themeText($r), $t)) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                $this->errors[] = 'Theme "' . Text::norm($t) . '" not found in the group. Current: ' . (implode('; ', array_map($themeText, $themeRows)) ?: 'none');
                continue;
            }
            $removedRows[$hit] = true;
            $this->removes[] = (string) $themeRows[$hit]['assetGroupSignal']['resourceName'];
            $this->rows->add(self::TH, ['label' => 'Theme', 'before' => $themeText($themeRows[$hit]), 'after' => null, 'change' => 'remove']);
        }
        $remaining = [];
        foreach ($themeRows as $i => $r) {
            if (!isset($removedRows[$i])) {
                $remaining[] = $themeText($r);
            }
        }
        $added = [];
        foreach ($th['add'] ?? [] as $t) {
            $e = self::checkPhrase('Theme', $t);
            if ($e) {
                $this->errors[] = $e;
                continue;
            }
            $n = Text::norm($t);
            foreach ([...$remaining, ...$added] as $x) {
                if (Text::same($x, $n)) {
                    $this->errors[] = "Theme \"{$n}\" already exists or is repeated in the request (case does not matter)";
                    continue 2;
                }
            }
            $added[] = $n;
            $this->creates[] = ['assetGroupSignalOperation' => ['create' => ['assetGroup' => $this->groupRn, 'searchTheme' => ['text' => $n]]]];
            $this->rows->add(self::TH, ['label' => 'New theme', 'before' => null, 'after' => $n, 'change' => 'add']);
        }
        if (count($remaining) + count($added) > self::MAX_THEMES) {
            $this->errors[] = 'Too many search themes after the change: ' . (count($remaining) + count($added)) . ' (maks. ' . self::MAX_THEMES . ')';
        }
    }

    // ---- audiences ----

    private function audienceName(string $rn): string
    {
        foreach ($this->s['audiences'] as $x) {
            if (($x['audience']['resourceName'] ?? null) === $rn) {
                return (string) ($x['audience']['name'] ?? basename($rn));
            }
        }
        return basename($rn);
    }

    private function audience(array $audienceRows): void
    {
        $a = $this->req['audience'] ?? null;
        $rnOf = fn($r) => (string) $r['assetGroupSignal']['audience']['audience'];
        $removedAudiences = [];
        if (!empty($this->req['removeAudience'])) {
            if (!$audienceRows) {
                $this->errors[] = 'The group has no audience signals to remove';
            }
            foreach ($audienceRows as $r) {
                $this->removes[] = (string) $r['assetGroupSignal']['resourceName'];
                $removedAudiences[$rnOf($r)] = true;
            }
        }
        $oldNames = array_map(fn($r) => $this->audienceName($rnOf($r)), array_filter($audienceRows, fn($r) => isset($removedAudiences[$rnOf($r)])));
        $keptRows = array_values(array_filter($audienceRows, fn($r) => !isset($removedAudiences[$rnOf($r)])));
        $added = null;
        $note = '';
        if ($a !== null) {
            if (isset($a['existingAudienceId'])) {
                $added = $this->existingAudience($a, $keptRows, $rnOf);
            } elseif (isset($a['name']) || $this->hasDimensions($a)) {
                [$added, $note] = $this->newAudience($a, $keptRows, $rnOf);
            }
        }
        if ($added !== null) {
            $this->rows->add(self::AU, ['label' => $oldNames ? 'Audience (replacement)' : 'Audience signal', 'before' => implode(', ', $oldNames) ?: null, 'after' => $added, 'change' => $oldNames ? 'change' : 'add'] + ($note !== '' ? ['note' => $note] : []));
        } else {
            foreach ($oldNames as $n) {
                $this->rows->add(self::AU, ['label' => 'Audience', 'before' => $n, 'after' => null, 'change' => 'remove']);
            }
        }
    }

    private function hasDimensions(array $a): bool
    {
        foreach (['customAudienceIds', 'userListIds', 'ageRanges', 'genders', 'parentalStatus'] as $k) {
            if (!empty($a[$k])) {
                return true;
            }
        }
        return false;
    }

    /** Google allows one audience per group: true when there are no others, otherwise an error. */
    private function oneAudience(array $keptRows, callable $rnOf): bool
    {
        if (!$keptRows) {
            return true;
        }
        $this->errors[] = 'The asset group already has audiences (' . implode(', ', array_map(fn($r) => $this->audienceName($rnOf($r)), $keptRows)) . '); Google allows one audience per group. Add removeAudience: true to replace them';
        return false;
    }

    /** Signal with an existing audience; returns the name of the added audience or null (error). */
    private function existingAudience(array $a, array $keptRows, callable $rnOf): ?string
    {
        if (isset($a['name']) || $this->hasDimensions($a)) {
            $this->errors[] = 'existingAudienceId cannot be combined with name or dimensions; give either an existing audience or the definition of a new one';
        }
        $id = trim($a['existingAudienceId']);
        $rn = "customers/{$this->cid}/audiences/$id";
        if (!preg_match('/^\d+$/', $id)) {
            $this->errors[] = "existingAudienceId: invalid ID \"{$a['existingAudienceId']}\"";
        } elseif (!array_filter($this->s['audiences'], fn($x) => ($x['audience']['resourceName'] ?? null) === $rn)) {
            $this->errors[] = "Audience $id not found. Available: " . (implode(', ', array_map(fn($x) => ($x['audience']['name'] ?? '') . ' [' . ($x['audience']['id'] ?? '') . ']', $this->s['audiences'])) ?: 'none');
        } elseif (array_filter($keptRows, fn($r) => $rnOf($r) === $rn)) {
            $this->errors[] = "Audience $id is already a signal of this group";
        } elseif ($this->oneAudience($keptRows, $rnOf)) {
            $this->creates[] = ['assetGroupSignalOperation' => ['create' => ['assetGroup' => $this->groupRn, 'audience' => ['audience' => $rn]]]];
            return $this->audienceName($rn);
        }
        return null;
    }

    /** Digits only; anything else is an error. @return list<string> */
    private function digits(array $ids, string $what): array
    {
        $out = [];
        foreach ($ids as $i) {
            if (preg_match('/^\d+$/', trim($i))) {
                $out[] = trim($i);
            } else {
                $this->errors[] = "$what: invalid ID \"{$i}\" (digits only)";
            }
        }
        return $out;
    }

    /** New audience from dimensions + a signal for it (temporary ID -1). @return array{0:?string,1:string} [name, note with the dimensions] */
    private function newAudience(array $a, array $keptRows, callable $rnOf): array
    {
        $name = Text::norm($a['name'] ?? '');
        if ($name === '') {
            $this->errors[] = 'Audience name is required when creating one';
        } elseif (array_filter($this->s['audiences'], fn($x) => Text::same((string) ($x['audience']['name'] ?? ''), $name))) {
            $this->errors[] = "An audience named \"{$name}\" already exists";
        }
        if (!$this->hasDimensions($a)) {
            $this->errors[] = 'An audience needs at least one dimension (segment, age, gender or parental status)';
        }
        [$dims, $notes] = $this->dimensions($a);
        $this->oneAudience($keptRows, $rnOf);
        $tmp = "customers/{$this->cid}/audiences/-1";
        $this->audienceCreates[] = ['audienceOperation' => ['create' => ['resourceName' => $tmp, 'name' => $name, 'scope' => 'CUSTOMER', 'dimensions' => $dims]]];
        $this->creates[] = ['assetGroupSignalOperation' => ['create' => ['assetGroup' => $this->groupRn, 'audience' => ['audience' => $tmp]]]];
        return [$name, implode(' · ', $notes)];
    }

    /** @return array{0:list<array>,1:list<string>} audience dimensions (Google) and descriptions for the preview */
    private function dimensions(array $a): array
    {
        $dims = [];
        $notes = [];
        $segments = [
            ...array_map(fn($i) => ['customAudience' => ['customAudience' => "customers/{$this->cid}/customAudiences/$i"]], $this->digits($a['customAudienceIds'] ?? [], 'customAudienceIds')),
            ...array_map(fn($i) => ['userList' => ['userList' => "customers/{$this->cid}/userLists/$i"]], $this->digits($a['userListIds'] ?? [], 'userListIds')),
        ];
        if ($segments) {
            $dims[] = ['audienceSegments' => ['segments' => $segments]];
        }
        if (!empty($a['customAudienceIds'])) {
            $notes[] = 'custom segments: ' . implode(', ', $a['customAudienceIds']);
        }
        if (!empty($a['userListIds'])) {
            $notes[] = 'user lists: ' . implode(', ', $a['userListIds']);
        }
        if (!empty($a['ageRanges'])) {
            foreach ($a['ageRanges'] as $r) {
                if ($r['min'] >= $r['max']) {
                    $this->errors[] = "Age range {$r['min']}–{$r['max']}: min must be less than max";
                }
            }
            $dims[] = ['age' => ['ageRanges' => array_map(fn($r) => ['minAge' => $r['min'], 'maxAge' => $r['max']], $a['ageRanges']), 'includeUndetermined' => false]];
            $notes[] = 'age: ' . implode(', ', array_map(fn($r) => "{$r['min']}–{$r['max']}", $a['ageRanges']));
        }
        if (!empty($a['genders'])) {
            $dims[] = ['gender' => ['genders' => array_values(array_filter($a['genders'], fn($g) => $g !== 'UNDETERMINED')), 'includeUndetermined' => in_array('UNDETERMINED', $a['genders'], true)]];
            $notes[] = 'gender: ' . implode(', ', array_map(fn($g) => self::GENDER_LABEL[$g], $a['genders']));
        }
        if (!empty($a['parentalStatus'])) {
            $dims[] = ['parentalStatus' => ['parentalStatuses' => array_values(array_filter($a['parentalStatus'], fn($g) => $g !== 'UNDETERMINED')), 'includeUndetermined' => in_array('UNDETERMINED', $a['parentalStatus'], true)]];
            $notes[] = 'parental status: ' . implode(', ', array_map(fn($g) => self::PARENT_LABEL[$g], $a['parentalStatus']));
        }
        return [$dims, $notes];
    }

    // ---- custom audience ----

    /** Fingerprint of a custom audience plan: the sorted list of account custom audience names. @param list<string> $names */
    public static function customAudienceFingerprint(array $names): string
    {
        $norm = array_map([Text::class, 'norm'], $names);
        sort($norm, SORT_STRING);
        return Json::fingerprint(['customAudiences' => $norm]);
    }

    /** @param array $req name, keywords?, urls? @param list<string> $existingNames names of the active custom audiences in the account */
    public static function customAudiencePlan(array $req, array $existingNames, \DateTimeImmutable $now): array
    {
        $errors = [];
        $name = Text::norm($req['name']);
        if ($name === '') {
            $errors[] = 'Custom audience name cannot be empty';
        } elseif (array_filter($existingNames, fn($n) => Text::same($n, $name))) {
            $errors[] = "A custom audience named \"{$name}\" already exists";
        }
        $members = [];
        $kw = [];
        foreach ($req['keywords'] ?? [] as $k) {
            $e = self::checkPhrase('Keyword', $k);
            if ($e) {
                $errors[] = $e;
                continue;
            }
            $n = Text::norm($k);
            if (array_filter($kw, fn($x) => Text::same($x, $n))) {
                continue;
            }
            $kw[] = $n;
            $members[] = ['memberType' => 'KEYWORD', 'keyword' => $n];
        }
        $urls = [];
        // URLs in an audience are competitor and topical pages, so they are not restricted to allowed_url_hosts (which applies to ad URLs).
        foreach ($req['urls'] ?? [] as $u) {
            $t = trim($u);
            if (!preg_match('#^https?://\S+$#i', $t)) {
                $errors[] = "A page URL must start with http:// or https://: $t";
                continue;
            }
            if (mb_strlen($t) > self::MAX_URL_CHARS) {
                $errors[] = 'Page URL has ' . mb_strlen($t) . ' characters (max ' . self::MAX_URL_CHARS . ')';
                continue;
            }
            if (in_array($t, $urls, true)) {
                continue;
            }
            $urls[] = $t;
            $members[] = ['memberType' => 'URL', 'url' => $t];
        }
        if (!$members && !$errors) {
            $errors[] = 'A custom audience needs at least one keyword or page URL';
        }
        $sec = 'Custom audience';
        $diff = [['section' => $sec, 'label' => 'New audience', 'before' => null, 'after' => $name, 'change' => 'add', 'note' => count($kw) . ' keywords · ' . count($urls) . ' URLs']];
        foreach ($kw as $k) {
            $diff[] = ['section' => $sec, 'label' => 'Keyword', 'before' => null, 'after' => $k, 'change' => 'add'];
        }
        foreach ($urls as $u) {
            $diff[] = ['section' => $sec, 'label' => 'Page URL', 'before' => null, 'after' => $u, 'change' => 'add'];
        }
        return Undo::attach(array_merge(Plan::base($now), [
            'kind' => 'custom_audience', 'summary' => "Custom audience \"{$name}\"", 'diff' => $diff,
            'steps' => $members ? [['title' => 'Custom audience', 'operations' => [['customAudienceOperation' => ['create' => ['name' => $name, 'members' => $members]]]]]] : [],
            'warnings' => ['The audience is only created in the account; it serves nothing by itself. Use it in prepare_asset_group_signals (audience.customAudienceIds).'],
            'validation' => ['ok' => !$errors, 'errors' => $errors],
            'beforeFingerprint' => self::customAudienceFingerprint($existingNames),
        ]));
    }
}
