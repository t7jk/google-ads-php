<?php
declare(strict_types=1);

namespace App\Plans\Build;

use App\Media\Ratio;
use App\Plans\{BlobOps, Cfg, Guard, Plan, Prepare, Text, Undo};
use App\Plans\Snapshot\PmaxSnapshot;

/**
 * Plan for changes to a Performance Max asset group (port of build-asset-group.ts): texts, images, logos, videos, name, status, URL, paths,
 * or a new (always paused) group. Usage: AssetGroup::plan($request, $pmaxSnapshot, $resolvedImages, $existingImages, $now).
 *
 * Assumptions and pitfalls:
 * - Google assets are immutable: "changing" a text = a new asset + a link + removal of the old link. Operations run in a fixed
 *   order: assets (negative temporary IDs) -> group settings -> links (create) -> links (remove) -> the same for the campaign.
 * - With brand guidelines enabled (campaign.brandGuidelinesEnabled) the business name and logos are CAMPAIGN assets (place=campaign).
 * - Images in operations are references ['$blob' => sha] (BlobOps); the bytes are added only at upload time.
 * - A current asset entry ($cur): ft, place (group|campaign), assetId, link (the link resourceName), text?, w?, h?, url?, name?, videoId?.
 *   Removed ones are marked in $removed ($cur indexes), without modifying $cur.
 */
final class AssetGroup
{
    private const MAX_PLAN_IMAGE_BYTES = 10_485_760;
    private const BRAND = ['BUSINESS_NAME', 'LOGO', 'LANDSCAPE_LOGO'];
    private const RECOMMENDED = [
        'MARKETING_IMAGE' => '1200×628', 'SQUARE_MARKETING_IMAGE' => '1200×1200', 'PORTRAIT_MARKETING_IMAGE' => '960×1200', 'LOGO' => '1200×1200', 'LANDSCAPE_LOGO' => '1200×300',
    ];
    private const SECTION = [
        'HEADLINE' => 'Headlines', 'LONG_HEADLINE' => 'Long headlines', 'DESCRIPTION' => 'Descriptions', 'BUSINESS_NAME' => 'Business name',
        'MARKETING_IMAGE' => 'Images', 'SQUARE_MARKETING_IMAGE' => 'Images', 'PORTRAIT_MARKETING_IMAGE' => 'Images', 'LOGO' => 'Logos', 'LANDSCAPE_LOGO' => 'Logos', 'YOUTUBE_VIDEO' => 'Videos',
    ];
    private const SECTION_ORDER = ['Group', 'Headlines', 'Long headlines', 'Descriptions', 'Business name', 'Images', 'Logos', 'Videos'];
    /** section => [field types, description of limits in the "Count" row] */
    private const SECTION_KEYS = [
        'Headlines' => [['HEADLINE'], 'min 3, max 15'], 'Long headlines' => [['LONG_HEADLINE'], 'min 1, max 5'], 'Descriptions' => [['DESCRIPTION'], 'min 2, max 5'],
        'Business name' => [['BUSINESS_NAME'], ''], 'Images' => [PmaxRules::IMAGE_TYPES, 'min 2, max 20'],
        'Logos' => [PmaxRules::LOGO_TYPES, 'min 1, max 5 + 5 (4:1)'], 'Videos' => [['YOUTUBE_VIDEO'], 'max 15'],
    ];
    private const TYPE_LABEL = ['HEADLINE' => 'headlines', 'LONG_HEADLINE' => 'long headlines', 'DESCRIPTION' => 'descriptions', 'BUSINESS_NAME' => 'business names'];
    private const NOUN = ['HEADLINE' => 'Headline', 'LONG_HEADLINE' => 'Long headline', 'DESCRIPTION' => 'Description', 'BUSINESS_NAME' => 'Business name'];

    private array $errors = [];
    private array $warnings = [];
    private SectionRows $rows;
    private array $base;
    private string $cid;
    private string $campaignRn;
    private bool $brand;
    private bool $isNew;
    /** @var array|null group row from the snapshot (assetGroup) */
    private ?array $group = null;
    private ?string $gid = null;
    private string $groupRn = '';
    private array $cur = [];
    /** @var array<int,true> indexes of removed $cur entries */
    private array $removed = [];
    private array $adds = [];
    /** @var array<int, array{0:string,1:int}> $cur index => [section, row id] for images being removed */
    private array $imgRows = [];
    private bool $cancelledDup = false;

    private function __construct(private array $req, private array $s, private array $images, private array $existing, private \DateTimeImmutable $now)
    {
        $this->rows = new SectionRows();
        $this->base = Plan::base($now);
        $this->cid = Cfg::customerId();
        $this->campaignRn = (string) ($s['campaign']['resourceName'] ?? "customers/{$this->cid}/campaigns/{$req['campaignId']}");
        $this->brand = ($s['campaign']['brandGuidelinesEnabled'] ?? false) === true;
        $this->isNew = !empty($req['newGroup']);
    }

    /** Fingerprint of the part of the snapshot the plan changes: the campaign, the given group (or the names of all groups for a new one) and the brand assets. */
    public static function fingerprint(array $s, ?string $groupId): string
    {
        $inGroup = fn($r) => (string) ($r['assetGroup']['id'] ?? '') === $groupId;
        $s['assetGroups'] = $groupId ? array_values(array_filter($s['assetGroups'], $inGroup)) : $s['assetGroups'];
        $s['groupAssets'] = $groupId ? array_values(array_filter($s['groupAssets'], $inGroup)) : [];
        $s['campaignAssets'] = array_values(array_filter($s['campaignAssets'], fn($r) => in_array($r['campaignAsset']['fieldType'] ?? null, self::BRAND, true)));
        return PmaxSnapshot::fingerprint($s, ['campaign', 'assetGroups', 'groupAssets', 'campaignAssets']);
    }

    /** @param array $req tool input + campaignId @param list<array> $images ResolvedImage @param list<array> $existing ExistingImages::find */
    public static function plan(array $req, array $snapshot, array $images, array $existing, \DateTimeImmutable $now): array
    {
        return (new self($req, $snapshot, $images, $existing, $now))->build();
    }

    // ---- building ----

    private function build(): array
    {
        if (!$this->s['campaign']) {
            $this->errors[] = 'Campaign not found';
        }
        $this->findGroup();
        $this->collectCurrent();
        $before = $this->countOf($this->cur);
        $this->applyTexts();
        $this->applyImages();
        $this->applyVideos();
        $this->checkTextsAfter();
        $this->checkCounts($before);
        $settingsOps = $this->groupSettings();
        $ops = $this->operations($settingsOps);
        if (!$ops && !$this->errors && !$this->cancelledDup) {
            $this->errors[] = 'Nothing to change';
        }
        return $this->finish($ops);
    }

    private function finish(array $ops): array
    {
        $camp = $this->s['campaign'];
        $groupName = $this->group ? (string) $this->group['name'] : (string) ($this->req['newGroup']['name'] ?? '');
        $plan = array_merge($this->base, [
            'kind' => 'asset_group_changes',
            'campaignId' => $this->req['campaignId'],
        ], $this->gid !== null ? ['assetGroupId' => $this->gid] : [], [
            'summary' => "Asset group \"{$groupName}\" in campaign \"" . ($camp['name'] ?? $this->req['campaignId']) . '"' . ($this->isNew ? ' (new, paused)' : ''),
            'diff' => $this->rows->flatten(self::SECTION_ORDER),
            'steps' => $ops ? [['title' => 'Asset group', 'operations' => $ops]] : [],
            'warnings' => $this->warnings,
            'validation' => ['ok' => !$this->errors, 'errors' => $this->errors],
            'beforeFingerprint' => self::fingerprint($this->s, $this->gid),
        ], $camp ? ['campaignState' => Text::stateLabel($camp)] : []);
        return Undo::attach($plan, $this->s);
    }

    private function findGroup(): void
    {
        $assetGroup = $this->req['assetGroup'] ?? null;
        if ($assetGroup !== null && $this->isNew) {
            $this->errors[] = 'Give assetGroup or newGroup, not both';
        } elseif ($assetGroup === null && !$this->isNew) {
            $this->errors[] = 'Give assetGroup (ID or name) or newGroup';
        } elseif ($assetGroup !== null) {
            $this->group = GroupLookup::find($this->s, (string) $assetGroup, $this->errors);
        }
        $this->gid = $this->group ? (string) $this->group['id'] : null;
        $this->groupRn = $this->group ? (string) $this->group['resourceName'] : "customers/{$this->cid}/assetGroups/-100";
    }

    private function collectCurrent(): void
    {
        if ($this->gid !== null) {
            foreach ($this->s['groupAssets'] as $r) {
                $brandAtCampaign = $this->brand && in_array($r['assetGroupAsset']['fieldType'] ?? null, self::BRAND, true);
                if ((string) ($r['assetGroup']['id'] ?? '') === $this->gid && !$brandAtCampaign) {
                    $this->cur[] = $this->currentOf($r, 'group');
                }
            }
        }
        if ($this->brand) {
            foreach ($this->s['campaignAssets'] as $r) {
                if (in_array($r['campaignAsset']['fieldType'] ?? null, self::BRAND, true)) {
                    $this->cur[] = $this->currentOf($r, 'campaign');
                }
            }
        }
    }

    private function currentOf(array $r, string $place): array
    {
        $a = $r['asset'] ?? [];
        $link = $place === 'group' ? ($r['assetGroupAsset'] ?? []) : ($r['campaignAsset'] ?? []);
        $fs = $a['imageAsset']['fullSize'] ?? null;
        return [
            'ft' => (string) ($link['fieldType'] ?? ''), 'place' => $place, 'assetId' => (string) ($a['id'] ?? ''), 'link' => (string) ($link['resourceName'] ?? ''),
            'text' => $a['textAsset']['text'] ?? null, 'w' => $fs ? (int) $fs['widthPixels'] : null, 'h' => $fs ? (int) $fs['heightPixels'] : null,
            'url' => $fs['url'] ?? null, 'name' => $a['name'] ?? null, 'videoId' => $a['youtubeVideoAsset']['youtubeVideoId'] ?? null,
        ];
    }

    private function countOf(array $list): array
    {
        $c = [];
        foreach ($list as $x) {
            $c[$x['ft']] = ($c[$x['ft']] ?? 0) + 1;
        }
        return $c;
    }

    private function placeOf(string $ft): string
    {
        return $this->brand && in_array($ft, self::BRAND, true) ? 'campaign' : 'group';
    }

    private function live(): \Generator
    {
        foreach ($this->cur as $i => $x) {
            if (!isset($this->removed[$i])) {
                yield $i => $x;
            }
        }
    }

    // ---- texts ----

    private function lenTag(string $ft, string $t): string
    {
        return Text::len($t) . '/' . (PmaxRules::textLimit($ft) ?? 'undefined');
    }

    private function textRow(string $ft, string $label, ?string $before, ?string $after, string $change): void
    {
        $this->rows->add(self::SECTION[$ft], ['label' => $label, 'before' => $before, 'after' => $after, 'change' => $change]);
    }

    /** Index of the current text identified by its text or assetId; when missing/ambiguous it adds an error and returns null. */
    private function findText(?string $fieldType, ?string $text, ?string $assetId, string $what): ?int
    {
        $pool = [];
        foreach ($this->live() as $i => $x) {
            if ($x['text'] !== null && ($fieldType === null || $fieldType === '' || $x['ft'] === $fieldType)) {
                $pool[$i] = $x;
            }
        }
        $byAsset = fn(string $id) => array_keys(array_filter($pool, fn($x) => $x['assetId'] === $id));
        if ($assetId !== null && $assetId !== '') {
            $hits = $byAsset($assetId);
        } elseif ($text !== null && preg_match('/^\d+$/', trim($text)) && $byAsset(trim($text))) {
            $hits = $byAsset(trim($text));
        } else {
            $hits = array_keys(array_filter($pool, fn($x) => Text::same($x['text'], $text ?? '')));
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
        if (count($hits) > 1) {
            $this->errors[] = "Text \"{$what}\" matches more than one asset; give the assetId";
            return null;
        }
        $label = $fieldType !== null && $fieldType !== '' ? (self::TYPE_LABEL[$fieldType] ?? $fieldType) : 'texts';
        $this->errors[] = "Text \"{$what}\" not found in the group. Current {$label}: " . (implode('; ', array_map(fn($x) => $x['text'], $pool)) ?: 'none');
        return null;
    }

    private function applyTexts(): void
    {
        $texts = $this->req['texts'] ?? [];
        foreach ($texts['add'] ?? [] as $x) {
            $e = PmaxRules::checkText($x['fieldType'], $x['text']);
            if ($e) {
                $this->errors[] = $e;
                continue;
            }
            $this->adds[] = ['ft' => $x['fieldType'], 'place' => $this->placeOf($x['fieldType']), 'kind' => 'text', 'text' => Text::norm($x['text'])];
            $this->textRow($x['fieldType'], self::NOUN[$x['fieldType']], null, Text::norm($x['text']) . ' · ' . $this->lenTag($x['fieldType'], $x['text']), 'add');
        }
        foreach ($texts['remove'] ?? [] as $x) {
            $i = $this->findText($x['fieldType'] ?? null, $x['text'] ?? null, $x['assetId'] ?? null, $x['assetId'] ?? $x['text'] ?? '');
            if ($i === null) {
                continue;
            }
            $hit = $this->cur[$i];
            $this->removed[$i] = true;
            $this->textRow($hit['ft'], self::NOUN[$hit['ft']] ?? $hit['ft'], "{$hit['text']} · " . $this->lenTag($hit['ft'], $hit['text']), null, 'remove');
        }
        foreach ($texts['replace'] ?? [] as $x) {
            $this->replaceText($x);
        }
    }

    private function replaceText(array $x): void
    {
        $i = $this->findText($x['fieldType'] ?? null, $x['from'], null, $x['from']);
        if ($i === null) {
            return;
        }
        $hit = $this->cur[$i];
        $e = PmaxRules::checkText($hit['ft'], $x['to']);
        if ($e) {
            $this->errors[] = $e;
            return;
        }
        if (Text::norm($x['to']) === Text::norm($hit['text'])) {
            $this->errors[] = 'The new text "' . Text::norm($x['to']) . '" is the same as the current one';
            return;
        }
        $this->removed[$i] = true;
        $this->adds[] = ['ft' => $hit['ft'], 'place' => $hit['place'], 'kind' => 'text', 'text' => Text::norm($x['to'])];
        $this->textRow($hit['ft'], self::NOUN[$hit['ft']] ?? $hit['ft'], "{$hit['text']} · " . $this->lenTag($hit['ft'], $hit['text']), Text::norm($x['to']) . ' · ' . $this->lenTag($hit['ft'], $x['to']), 'change');
    }

    // ---- images ----

    /** Like String(x) in JS for a missing value. */
    private static function js(mixed $v): string
    {
        return $v === null ? 'undefined' : (string) $v;
    }

    private function applyImages(): void
    {
        $dupImages = [];
        $totalBytes = 0;
        $seen = [];
        foreach ($this->images as $im) {
            if (isset($seen[$im['sha']])) {
                $this->errors[] = 'The same image was given more than once';
                continue;
            }
            $seen[$im['sha']] = true;
            $totalBytes += $im['bytes'];
            $this->addImage($im, $dupImages);
        }
        if ($totalBytes > self::MAX_PLAN_IMAGE_BYTES) {
            $this->errors[] = 'Images in one plan may total at most 10 MB; split the change into several plans';
        }
        foreach ($this->req['images']['remove'] ?? [] as $x) {
            $this->removeImage($x);
        }
        $this->cancelDuplicates($dupImages);
    }

    /** A single image being added: classification by aspect ratio, matching to an existing asset (reuse), preview row. */
    private function addImage(array $im, array &$dupImages): void
    {
        $cls = Ratio::classify($im['width'], $im['height'], $im['role']);
        if (isset($cls['error'])) {
            $this->errors[] = $cls['error'];
            return;
        }
        if ($cls['belowMin']) {
            $this->errors[] = "Image {$im['width']}×{$im['height']} is smaller than the minimum for {$cls['fieldType']}";
            return;
        }
        if ($cls['belowRecommended']) {
            $this->warnings[] = "Image {$im['width']}×{$im['height']} is smaller than recommended (" . self::RECOMMENDED[$cls['fieldType']] . ')';
        }
        $ft = $cls['fieldType'];
        $m = ExistingImages::match(ImageSources::assetName($im, $this->now), $im['width'], $im['height'], $this->existing, $im['sha']);
        if ($m !== null && isset($m['error'])) {
            $this->errors[] = $m['error'];
            return;
        }
        $add = ['ft' => $ft, 'place' => $this->placeOf($ft), 'kind' => 'image', 'img' => $im + ['fieldType' => $ft]];
        if ($m !== null) {
            $reuse = $m['reuse'];
            $linkedCur = $this->linkedCurrent($add['place'], $ft, $reuse['id']);
            if ($linkedCur !== null || $this->linkedElsewhere($add['place'], $ft, $reuse['id'])) {
                if ($linkedCur !== null) {
                    $dupImages[] = ['cur' => $linkedCur, 'name' => $reuse['name']]; // resolved after the removals are processed
                } else {
                    $this->errors[] = "This logo/image is already in the campaign ({$reuse['name']})";
                }
                return;
            }
            $add['reuseRn'] = $reuse['resourceName'] ?: "customers/{$this->cid}/assets/{$reuse['id']}";
        }
        $this->adds[] = $add;
        $this->rows->add(self::SECTION[$ft], [
            'label' => $im['name'] ?? 'New image', 'before' => null, 'after' => "{$im['width']}×{$im['height']}", 'change' => 'add',
            'afterImage' => Plan::imagePrefix($this->base['id']) . $im['sha'], 'imageAlt' => "{$im['width']}x{$im['height']}",
            'note' => "{$im['width']}×{$im['height']} · {$cls['ratio']} · $ft",
        ]);
    }

    private function linkedCurrent(string $place, string $ft, string $assetId): ?int
    {
        foreach ($this->live() as $i => $c) {
            if ($c['place'] === $place && $c['ft'] === $ft && $c['assetId'] === $assetId) {
                return $i;
            }
        }
        return null;
    }

    /** For campaign assets (brand) we check the whole campaign snapshot, not just the current group entries. */
    private function linkedElsewhere(string $place, string $ft, string $assetId): bool
    {
        if ($place === 'group') {
            return false;
        }
        foreach ($this->s['campaignAssets'] as $r) {
            if ((string) ($r['asset']['id'] ?? '') === $assetId && ($r['campaignAsset']['fieldType'] ?? null) === $ft) {
                return true;
            }
        }
        return false;
    }

    private function isImageLike(array $c): bool
    {
        return in_array($c['ft'], PmaxRules::IMAGE_TYPES, true) || in_array($c['ft'], PmaxRules::LOGO_TYPES, true);
    }

    private function removeImageRow(int $i): void
    {
        $x = $this->cur[$i];
        $size = $x['w'] ? "{$x['w']}×{$x['h']}" : $x['assetId'];
        $section = self::SECTION[$x['ft']];
        $this->imgRows[$i] = [$section, $this->rows->add($section, [
            'label' => $x['name'] ?? "Image {$x['assetId']}", 'before' => $size, 'after' => null, 'change' => 'remove',
        ] + ($x['url'] ? ['beforeImage' => $x['url']] : []) + ['imageAlt' => self::js($x['w']) . 'x' . self::js($x['h']), 'note' => self::js($x['w']) . '×' . self::js($x['h']) . " · {$x['ft']}"])];
    }

    private function removeImage(array $x): void
    {
        if (!empty($x['assetId'])) {
            foreach ($this->live() as $i => $c) {
                if ($this->isImageLike($c) && $c['assetId'] === $x['assetId']) {
                    $this->removed[$i] = true;
                    $this->removeImageRow($i);
                    return;
                }
            }
            $this->errors[] = "Image with assetId {$x['assetId']} not found in the group";
        } elseif (!empty($x['size'])) {
            if (!preg_match('/^(\d+)\s*[x×]\s*(\d+)$/iu', trim($x['size']), $m)) {
                $this->errors[] = "Invalid size \"{$x['size']}\" (e.g. 300x300)";
                return;
            }
            $hits = [];
            foreach ($this->live() as $i => $c) {
                if ($c['place'] === 'group' && $this->isImageLike($c) && $c['w'] === (int) $m[1] && $c['h'] === (int) $m[2]) {
                    $hits[] = $i;
                }
            }
            if (!$hits) {
                $this->errors[] = "The group has no images with dimensions {$m[1]}x{$m[2]}";
            }
            foreach ($hits as $i) {
                $this->removed[$i] = true;
                $this->removeImageRow($i);
            }
        } else {
            $this->errors[] = 'images.remove requires assetId or size';
        }
    }

    /** Adding and removing the same file in one plan cancel each other (the identical file stays); in other cases it is an error. */
    private function cancelDuplicates(array $dupImages): void
    {
        foreach ($dupImages as $d) {
            if (!isset($this->removed[$d['cur']])) {
                $this->errors[] = "This logo/image is already in the campaign ({$d['name']})";
                continue;
            }
            unset($this->removed[$d['cur']]);
            if (isset($this->imgRows[$d['cur']])) {
                $this->rows->drop(...$this->imgRows[$d['cur']]);
            }
            $this->cancelledDup = true;
            if (!in_array(Prepare::SAME_IMAGE_NOOP, $this->warnings, true)) {
                $this->warnings[] = Prepare::SAME_IMAGE_NOOP;
            }
        }
    }

    // ---- videos ----

    private function videoRow(string $label, ?string $before, ?string $after, string $change): void
    {
        $this->rows->add('Videos', ['label' => $label, 'before' => $before, 'after' => $after, 'change' => $change]);
    }

    private function applyVideos(): void
    {
        foreach ($this->req['videos']['add'] ?? [] as $v) {
            $id = PmaxRules::youtubeId($v);
            if ($id === null) {
                $this->errors[] = "Invalid video: $v (give an 11-character ID or a YouTube URL)";
                continue;
            }
            $inGroup = array_filter(iterator_to_array($this->live()), fn($c) => $c['ft'] === 'YOUTUBE_VIDEO' && $c['videoId'] === $id);
            $inAdds = array_filter($this->adds, fn($a) => $a['kind'] === 'video' && $a['videoId'] === $id);
            if ($inGroup || $inAdds) {
                $this->errors[] = "Video $id is already in the group";
                continue;
            }
            $this->adds[] = ['ft' => 'YOUTUBE_VIDEO', 'place' => 'group', 'kind' => 'video', 'videoId' => $id];
            $this->videoRow('Video', null, "https://www.youtube.com/watch?v=$id", 'add');
        }
        foreach ($this->req['videos']['remove'] ?? [] as $v) {
            $id = PmaxRules::youtubeId($v);
            $hit = null;
            foreach ($this->live() as $i => $c) {
                if ($c['ft'] === 'YOUTUBE_VIDEO' && (($id !== null && $c['videoId'] === $id) || (preg_match('/^\d+$/', trim($v)) && $c['assetId'] === trim($v)))) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                $this->errors[] = "Video $v not found in the group";
                continue;
            }
            $this->removed[$hit] = true;
            $this->videoRow('Video', "https://www.youtube.com/watch?v={$this->cur[$hit]['videoId']}", null, 'remove');
        }
    }

    // ---- rules after the change ----

    private function finalTexts(string $ft): array
    {
        $out = [];
        foreach ($this->live() as $x) {
            if ($x['ft'] === $ft && $x['text'] !== null) {
                $out[] = $x['text'];
            }
        }
        foreach ($this->adds as $a) {
            if ($a['ft'] === $ft && $a['kind'] === 'text') {
                $out[] = $a['text'];
            }
        }
        return $out;
    }

    private function checkTextsAfter(): void
    {
        foreach ([...PmaxRules::TEXT_TYPES, 'BUSINESS_NAME'] as $ft) {
            $seen = [];
            foreach ($this->finalTexts($ft) as $t) {
                $k = mb_strtolower(Text::norm($t));
                if (isset($seen[$k])) {
                    $this->errors[] = 'Duplicate text "' . Text::norm($t) . '" (' . self::TYPE_LABEL[$ft] . '): texts cannot repeat (case-insensitive)';
                }
                $seen[$k] = true;
            }
        }
        $descs = $this->finalTexts('DESCRIPTION');
        if ($descs && !array_filter($descs, fn($d) => Text::len($d) <= 60)) {
            $this->errors[] = 'At least one description must be at most 60 characters long';
        }
    }

    private function checkCounts(array $before): void
    {
        $delta = [];
        foreach ($this->adds as $a) {
            $delta[$a['ft']] = ($delta[$a['ft']] ?? 0) + 1;
        }
        foreach (array_keys($this->removed) as $i) {
            $ft = $this->cur[$i]['ft'];
            $delta[$ft] = ($delta[$ft] ?? 0) - 1;
        }
        $after = PmaxRules::countAfter($before, $delta);
        $prior = $this->isNew ? [] : PmaxRules::checkGroupCounts($before, $this->brand);
        foreach (PmaxRules::checkGroupCounts($after, $this->brand) as $e) {
            if (!in_array($e, $prior, true)) {
                $this->errors[] = $e;
            }
        }
        foreach (self::SECTION_KEYS as $section => [$keys, $limits]) {
            if (!$this->rows->has($section)) {
                continue;
            }
            $sum = fn(array $c) => array_sum(array_map(fn($k) => $c[$k] ?? 0, $keys));
            $this->rows->add($section, ['label' => 'Count', 'before' => null, 'after' => 'Count: ' . $sum($before) . ' → ' . $sum($after) . ($limits !== '' ? " ($limits)" : ''), 'change' => 'info']);
        }
    }

    // ---- group settings ----

    private function urlOk(string $u): bool
    {
        $e = Guard::checkUrlHost($u);
        if ($e !== null) {
            $this->errors[] = $e;
        }
        return $e === null;
    }

    private function groupRow(string $label, ?string $before, ?string $after, string $change): void
    {
        $this->rows->add('Group', ['label' => $label, 'before' => $before, 'after' => $after, 'change' => $change]);
    }

    private static function filled(?string $v): bool
    {
        return $v !== null && $v !== '';
    }

    /** @return list<array> assetGroupOperation operations (create for a new group, update for an existing one) */
    private function groupSettings(): array
    {
        $req = $this->req;
        foreach (['path1' => 'Path 1', 'path2' => 'Path 2'] as $k => $n) {
            if (isset($req[$k]) && mb_strlen($req[$k]) > 15) {
                $this->errors[] = "$n is longer than 15 characters";
            }
        }
        return $this->isNew ? $this->newGroupOps() : ($this->group ? $this->updateGroupOps() : []);
    }

    private function nameTaken(string $name): bool
    {
        foreach ($this->s['assetGroups'] as $g) {
            if ((string) ($g['assetGroup']['id'] ?? '') !== (string) $this->gid && Text::same((string) ($g['assetGroup']['name'] ?? ''), $name)) {
                return true;
            }
        }
        return false;
    }

    private function newGroupOps(): array
    {
        $req = $this->req;
        $ng = $req['newGroup'];
        if (isset($req['rename']) || isset($req['finalUrl'])) {
            $this->errors[] = 'rename and finalUrl apply to an existing group; for newGroup use newGroup.name and newGroup.finalUrl';
        }
        if (($req['status'] ?? null) === 'ENABLED') {
            $this->errors[] = 'A new group is always created as paused (PAUSED)';
        }
        if (Text::norm($ng['name']) === '') {
            $this->errors[] = 'New group name cannot be empty';
        }
        if ($this->nameTaken($ng['name'])) {
            $this->errors[] = 'An asset group named "' . Text::norm($ng['name']) . '" already exists in the campaign';
        }
        $urlOk = $this->urlOk($ng['finalUrl']);
        if (self::filled($req['path2'] ?? null) && !self::filled($req['path1'] ?? null)) {
            $this->errors[] = 'Path 2 requires path 1';
        }
        $create = ['resourceName' => $this->groupRn, 'campaign' => $this->campaignRn, 'name' => Text::norm($ng['name']), 'status' => 'PAUSED', 'finalUrls' => [$ng['finalUrl']]]
            + (self::filled($req['path1'] ?? null) ? ['path1' => $req['path1']] : []) + (self::filled($req['path2'] ?? null) ? ['path2' => $req['path2']] : []);
        $this->groupRow('New group', null, Text::norm($ng['name']) . ' (paused)', 'add');
        if ($urlOk) {
            $this->groupRow('Final URL', null, $ng['finalUrl'], 'add');
        }
        return [['assetGroupOperation' => ['create' => $create]]];
    }

    private function updateGroupOps(): array
    {
        $req = $this->req;
        $g = $this->group;
        $upd = [];
        $mask = [];
        if (isset($req['rename']) && Text::norm($req['rename']) !== (string) $g['name']) {
            if ($this->nameTaken($req['rename'])) {
                $this->errors[] = 'An asset group named "' . Text::norm($req['rename']) . '" already exists in the campaign';
            }
            $upd['name'] = Text::norm($req['rename']);
            $mask[] = 'name';
            $this->groupRow('Name', (string) $g['name'], Text::norm($req['rename']), 'change');
        }
        if (isset($req['status']) && $req['status'] !== ($g['status'] ?? null)) {
            $upd['status'] = $req['status'];
            $mask[] = 'status';
            $this->groupRow('Status', (string) $g['status'], $req['status'], 'change');
            if ($req['status'] === 'ENABLED' && ($this->s['campaign']['status'] ?? null) === 'ENABLED') {
                $this->warnings[] = 'The group will start serving immediately';
            }
        }
        $curUrl = ($g['finalUrls'] ?? [])[0] ?? null;
        if (isset($req['finalUrl']) && $req['finalUrl'] !== $curUrl) {
            $this->urlOk($req['finalUrl']);
            $upd['finalUrls'] = [$req['finalUrl']];
            $mask[] = 'finalUrls';
            $this->groupRow('Final URL', $curUrl, $req['finalUrl'], 'change');
        }
        foreach (['path1' => 'Path 1', 'path2' => 'Path 2'] as $k => $label) {
            if (isset($req[$k]) && $req[$k] !== ($g[$k] ?? null)) {
                $upd[$k] = $req[$k];
                $mask[] = $k;
                $this->groupRow($label, $g[$k] ?? null, $req[$k], 'change');
            }
        }
        if (self::filled($req['path2'] ?? $g['path2'] ?? null) && !self::filled($req['path1'] ?? $g['path1'] ?? null)) {
            $this->errors[] = 'Path 2 requires path 1';
        }
        return $mask ? [['assetGroupOperation' => ['update' => ['resourceName' => $this->groupRn] + $upd, 'updateMask' => implode(',', $mask)]]] : [];
    }

    // ---- operations ----

    /** Fixed order: assets -> group settings -> group links (create, remove) -> campaign links (create, remove). */
    private function operations(array $settingsOps): array
    {
        $tmp = 0;
        $assetOps = [];
        foreach ($this->adds as $k => $a) {
            if (isset($a['reuseRn'])) {
                $this->adds[$k]['rn'] = $a['reuseRn'];
                continue;
            }
            $rn = "customers/{$this->cid}/assets/" . --$tmp;
            $this->adds[$k]['rn'] = $rn;
            $assetOps[] = ['assetOperation' => ['create' => ['resourceName' => $rn] + $this->assetBody($a)]];
        }
        $link = fn(string $place, string $entity, string $parentKey, string $parentRn) => array_map(
            fn($a) => [$entity => ['create' => [$parentKey => $parentRn, 'asset' => $a['rn'], 'fieldType' => $a['ft']]]],
            array_values(array_filter($this->adds, fn($a) => $a['place'] === $place)));
        $unlink = fn(string $place, string $entity) => array_map(
            fn($i) => [$entity => ['remove' => $this->cur[$i]['link']]],
            array_values(array_filter(array_keys($this->removed), fn($i) => $this->cur[$i]['place'] === $place)));
        $caCreate = $link('campaign', 'campaignAssetOperation', 'campaign', $this->campaignRn);
        $caRemove = $unlink('campaign', 'campaignAssetOperation');
        if ($caCreate || $caRemove) {
            $this->rows->add('Logos', ['label' => 'Brand assets', 'before' => null, 'after' => 'With brand guidelines enabled, logos and the business name are campaign assets, not group assets', 'change' => 'info']);
        }
        return [...$assetOps, ...$settingsOps, ...$link('group', 'assetGroupAssetOperation', 'assetGroup', $this->groupRn),
            ...$unlink('group', 'assetGroupAssetOperation'), ...$caCreate, ...$caRemove];
    }

    private function assetBody(array $a): array
    {
        return match ($a['kind']) {
            'text' => ['textAsset' => ['text' => $a['text']]],
            'image' => ['name' => ImageSources::assetName($a['img'], $this->now), 'imageAsset' => ['data' => BlobOps::ref($a['img']['sha'])]],
            default => ['youtubeVideoAsset' => ['youtubeVideoId' => $a['videoId']]],
        };
    }
}
