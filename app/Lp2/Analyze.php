<?php
declare(strict_types=1);

namespace App\Lp2;

/**
 * Landing page analysis (port of src/lp2/analyze.ts): forbidden phrases, <select> lists, Google Ads tag, Consent Mode.
 * Works on text only (nothing is executed). Indexes in the folded text are counted in characters (mb_*), in code analysis in bytes.
 * Results are associative arrays with the same keys and order as the Node objects (they end up in structuredContent).
 */
final class Analyze
{
    private const MAX_HITS_PER_PHRASE = 20;
    /** Block boundary in the flattened page text: it breaks a phrase (it is not whitespace). */
    private const SEP = "\u{1}";
    private const INLINE = ['a', 'b', 'i', 'em', 'strong', 'span', 'u', 'small', 'mark', 'sub', 'sup', 'font', 'abbr', 'code', 's', 'del', 'ins', 'label', 'big', 'cite', 'q', 'time'];
    // Intentionally Polish: the landing pages checked are in Polish, so Polish diacritics are folded to ASCII when comparing phrases.
    private const PL_FROM = 'ĄĆĘŁŃÓŚŹŻąćęłńóśźż';
    private const PL_TO = 'ACELNOSZZacelnoszz';

    // ---------- text comparison: case, Polish diacritics, whitespace ----------

    private static function foldChar(string $ch): string
    {
        static $cache = [];
        if (isset($cache[$ch])) {
            return $cache[$ch];
        }
        $f = $ch;
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($ch, \Normalizer::NFD);
            if ($n !== false) {
                $f = (string) preg_replace('/\p{M}/u', '', $n);
            }
        } else { // hosting without intl: Polish diacritics are enough (the pages checked are Polish)
            $f = strtr($ch, array_combine(mb_str_split(self::PL_FROM), mb_str_split(self::PL_TO)));
        }
        $f = str_replace('ł', 'l', mb_strtolower($f));
        return $cache[$ch] = $f;
    }

    /** @return array{text:string, map:list<int>} map[i] = index of the character in the original for the i-th character of the folded text */
    public static function fold(string $s): array
    {
        $out = [];
        $map = [];
        $last = '';
        foreach (mb_str_split($s) as $i => $ch) {
            $f = self::foldChar($ch);
            if (preg_match('/^\s$/u', $f)) {
                $f = ' ';
            }
            foreach (mb_str_split($f) as $c) {
                if ($c === ' ' && $last === ' ') {
                    continue;
                }
                $out[] = $last = $c;
                $map[] = $i;
            }
        }
        return ['text' => implode('', $out), 'map' => $map];
    }

    private static function foldPhrase(string $p): string
    {
        return self::fold(trim($p))['text'];
    }

    private static function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Snippet of +-70 characters around the hit. Node counts in UTF-16 units (emoji = 2), PHP in code points,
     * so the window is measured in UTF-16 units to keep the snippet identical to Node's.
     */
    private static function snippetAround(string $raw, int $start, int $len): string
    {
        $before = self::takeUnits(array_reverse(mb_str_split(mb_substr($raw, max(0, $start - 70), min(70, $start)))), 70);
        $after = self::takeUnits(mb_str_split(mb_substr($raw, $start + $len, 70)), 70);
        $mid = mb_substr($raw, $start, $len);
        return self::oneLine(str_replace("\u{1}", ' ', implode('', array_reverse($before)) . $mid . implode('', $after)));
    }

    /** The first characters of the list that fit in $max UTF-16 units (a non-BMP character = 2 units). @return list<string> */
    private static function takeUnits(array $chars, int $max): array
    {
        $out = [];
        foreach ($chars as $c) {
            $u = mb_ord($c) > 0xFFFF ? 2 : 1;
            if ($max - $u < 0) {
                break;
            }
            $max -= $u;
            $out[] = $c;
        }
        return $out;
    }

    /** Fragment of text around a byte index (without cutting UTF-8 characters). */
    private static function byteSnippet(string $t, int $i, int $len, int $before, int $after): string
    {
        $from = max(0, $i - $before);
        return self::oneLine((string) mb_strcut($t, $from, $i + $len + $after - $from));
    }

    private static function elLabel(HNode $e): string
    {
        $cls = '';
        if (($e->attrs['class'] ?? '') !== '') {
            $parts = preg_split('/\s+/', trim($e->attrs['class'])) ?: [];
            $cls = '.' . implode('.', array_slice($parts, 0, 3));
        }
        return "<{$e->tag}" . (($e->attrs['id'] ?? '') !== '' ? "#{$e->attrs['id']}" : '') . "$cls>";
    }

    private static function classify(HNode $n, bool $attr = false): string
    {
        $chain = Html::ancestors($n);
        if ($attr && $n->tag === 'meta' && preg_match('/^(description|og:description|twitter:description)$/i', $n->attrs['name'] ?? $n->attrs['property'] ?? '')) {
            return 'meta description';
        }
        foreach ($chain as $e) {
            if ($e->tag === 'title') {
                return 'page title';
            }
        }
        foreach ($chain as $e) {
            if ($e->tag === 'button' || ($e->tag === 'input' && preg_match('/^(submit|button)$/i', $e->attrs['type'] ?? ''))
                || ($e->tag === 'a' && preg_match('/(^|[\s-])(btn|button)([\s-]|$)/i', $e->attrs['class'] ?? ''))) {
                return 'button';
            }
        }
        foreach ($chain as $e) {
            if ($e->tag === 'details' || $e->tag === 'summary' || preg_match('/faq|accordion|pytani/i', ($e->attrs['id'] ?? '') . ' ' . ($e->attrs['class'] ?? ''))) {
                return 'FAQ';
            }
        }
        return 'other';
    }

    /** Key order as in the JS object: source, [scriptUrl], phrase, where, visible, hidden, [hiddenReason], kind, [element], snippet. */
    private static function hit(array $src, string $phrase, string $where, bool $visible, bool $hidden, ?string $hiddenReason, string $kind, ?string $element, string $snippet): array
    {
        return $src + ['phrase' => $phrase, 'where' => $where, 'visible' => $visible, 'hidden' => $hidden]
            + ($hiddenReason !== null ? ['hiddenReason' => $hiddenReason] : []) + ['kind' => $kind]
            + ($element !== null ? ['element' => $element] : []) + ['snippet' => $snippet];
    }

    /**
     * Searches for phrases in the page content (text including inline tags, attributes), comments, <script> and <style> of one document.
     * @param list<string> $phrases
     * @return list<array{phrase:string,total:int,visible:int,hidden:int,other:int,hits:list<array>,truncated:bool}>
     */
    public static function findPhrases(HNode $root, array $phrases, array $src = ['source' => 'html']): array
    {
        $folded = [];
        foreach ($phrases as $p) {
            $f = self::foldPhrase($p);
            if ($f !== '') {
                $folded[] = ['phrase' => $p, 'f' => $f];
            }
        }
        // Page text as one string with block boundaries; owner[i] = text node owning the i-th character.
        $parts = [];
        $owner = [];
        $lastIsSep = false;
        $specials = [];
        $attrs = [];
        $addSep = function () use (&$parts, &$owner, &$lastIsSep): void {
            if ($owner && !$lastIsSep) {
                $parts[] = self::SEP;
                $owner[] = $owner[count($owner) - 1];
                $lastIsSep = true;
            }
        };
        $visit = function (HNode $el) use (&$visit, &$parts, &$owner, &$lastIsSep, &$specials, &$attrs, $addSep): void {
            foreach ($el->children as $c) {
                if ($c->type === 'text') {
                    if ($c->parent->tag === 'script' || $c->parent->tag === 'style') {
                        $specials[] = ['where' => $c->parent->tag, 'text' => $c->text, 'el' => $c->parent];
                        continue;
                    }
                    if ($c->text !== '') {
                        $parts[] = $c->text;
                        $lastIsSep = false;
                        for ($k = 0, $n = mb_strlen($c->text); $k < $n; $k++) {
                            $owner[] = $c;
                        }
                    }
                } elseif ($c->type === 'comment') {
                    $specials[] = ['where' => 'comment', 'text' => $c->text, 'el' => $el];
                } else {
                    foreach ($c->attrs as $name => $v) {
                        if ($v === '' || !in_array($name, ['title', 'alt', 'placeholder', 'value', 'aria-label', 'content', 'label'], true)) {
                            continue;
                        }
                        if ($name === 'content' && $c->tag !== 'meta') {
                            continue;
                        }
                        $attrs[] = ['text' => $v, 'el' => $c, 'name' => $name];
                    }
                    $block = !in_array($c->tag, self::INLINE, true);
                    if ($block) {
                        $addSep();
                    }
                    $visit($c);
                    if ($block) {
                        $addSep();
                    }
                }
            }
        };
        $visit($root);
        $page = implode('', $parts);
        $pf = self::fold($page);
        $foldedAttrs = array_map(fn($a) => $a + ['f' => self::fold($a['text'])['text']], $attrs);
        $foldedSpecials = array_map(fn($x) => $x + ['fold' => self::fold($x['text'])], $specials);

        $result = [];
        foreach ($folded as ['phrase' => $phrase, 'f' => $f]) {
            $hits = [];
            $total = 0;
            $push = function (array $h) use (&$hits, &$total): void {
                $total++;
                if (count($hits) < self::MAX_HITS_PER_PHRASE) {
                    $hits[] = $h;
                }
            };
            $fl = mb_strlen($f);
            // page text
            for ($at = mb_strpos($pf['text'], $f); $at !== false; $at = mb_strpos($pf['text'], $f, $at + $fl)) {
                $rawStart = $pf['map'][$at];
                $rawEnd = $pf['map'][$at + $fl - 1] + 1;
                $node = $owner[$rawStart] ?? null;
                $h = $node ? Html::hiddenReason($node) : null;
                $push(self::hit($src, $phrase, 'text', $h === null, $h !== null, $h, $node ? self::classify($node) : 'other',
                    $node ? self::elLabel($node->parent) : null, self::snippetAround($page, $rawStart, $rawEnd - $rawStart)));
            }
            // attributes
            foreach ($foldedAttrs as $a) {
                if (!str_contains($a['f'], $f)) {
                    continue;
                }
                $h = Html::hiddenReason($a['el']);
                $push(self::hit($src, $phrase, 'attribute', $h === null, $h !== null, $h, self::classify($a['el'], true),
                    self::elLabel($a['el']) . "[{$a['name']}]", mb_substr(self::oneLine($a['text']), 0, 200)));
            }
            // comments, script, style: never visible content
            foreach ($foldedSpecials as $s) {
                $sf = $s['fold'];
                for ($at = mb_strpos($sf['text'], $f); $at !== false; $at = mb_strpos($sf['text'], $f, $at + $fl)) {
                    $start = $sf['map'][$at];
                    $push(self::hit($src, $phrase, $s['where'], false, false, null, 'other', self::elLabel($s['el']),
                        self::snippetAround($s['text'], $start, $sf['map'][$at + $fl - 1] + 1 - $start)));
                }
            }
            $visible = count(array_filter($hits, fn($h) => $h['visible']));
            $hidden = count(array_filter($hits, fn($h) => $h['hidden']));
            $result[] = ['phrase' => $phrase, 'total' => $total, 'visible' => $visible, 'hidden' => $hidden,
                'other' => count($hits) - $visible - $hidden, 'hits' => $hits, 'truncated' => $total > count($hits)];
        }
        return $result;
    }

    /** Phrases in foreign text (e.g. a .js file): every occurrence is marked as coming from a script. */
    public static function findPhrasesInScript(string $text, array $phrases, string $scriptUrl): array
    {
        $out = [];
        $tf = self::fold($text);
        foreach ($phrases as $p) {
            $f = self::foldPhrase($p);
            if ($f === '') {
                continue;
            }
            $fl = mb_strlen($f);
            $n = 0;
            for ($at = mb_strpos($tf['text'], $f); $at !== false && $n < self::MAX_HITS_PER_PHRASE; $at = mb_strpos($tf['text'], $f, $at + $fl), $n++) {
                $start = $tf['map'][$at];
                $out[] = self::hit(['source' => 'script', 'scriptUrl' => $scriptUrl], $p, 'script', false, false, null, 'other', null,
                    self::snippetAround($text, $start, $tf['map'][$at + $fl - 1] + 1 - $start));
            }
        }
        return $out;
    }

    // ---------- <select> ----------

    public static function findSelects(HNode $root): array
    {
        $els = Html::allElements($root);
        $labelsFor = [];
        foreach ($els as $l) {
            if ($l->tag === 'label' && ($l->attrs['for'] ?? '') !== '') {
                $labelsFor[$l->attrs['for']] = Html::textOf($l);
            }
        }
        $out = [];
        foreach ($els as $s) {
            if ($s->tag !== 'select') {
                continue;
            }
            $label = null;
            $labelSource = 'none';
            $id = $s->attrs['id'] ?? '';
            if ($id !== '' && ($labelsFor[$id] ?? '') !== '') {
                $label = $labelsFor[$id];
                $labelSource = 'label[for]';
            } else {
                $wrap = null;
                foreach (Html::ancestors($s) as $a) {
                    if ($a->tag === 'label') {
                        $wrap = $a;
                        break;
                    }
                }
                if ($wrap) {
                    $inner = self::oneLine(implode(' ', array_map(fn($c) => $c->isEl() ? Html::textOf($c) : '', $s->children)));
                    $full = Html::textOf($wrap);
                    $pos = $inner === '' ? false : strpos($full, $inner);
                    $cut = trim($pos === false ? $full : substr_replace($full, '', $pos, strlen($inner)));
                    $label = $cut !== '' ? $cut : $full;
                    $labelSource = 'label (wrapping)';
                } elseif (($s->attrs['aria-label'] ?? '') !== '') {
                    $label = $s->attrs['aria-label'];
                    $labelSource = 'aria-label';
                }
            }
            $h = Html::hiddenReason($s);
            $options = [];
            foreach (Html::allElements($s) as $o) {
                if ($o->tag === 'option') {
                    $options[] = ['value' => $o->attrs['value'] ?? Html::textOf($o), 'text' => Html::textOf($o),
                        'selected' => array_key_exists('selected', $o->attrs), 'disabled' => array_key_exists('disabled', $o->attrs),
                        'hidden' => Html::hiddenReason($o) !== null || array_key_exists('hidden', $o->attrs)];
                }
            }
            $out[] = ['name' => $s->attrs['name'] ?? null, 'id' => $s->attrs['id'] ?? null, 'label' => $label, 'labelSource' => $labelSource,
                'hidden' => $h !== null] + ($h !== null ? ['hiddenReason' => $h] : []) + ['options' => $options];
        }
        return $out;
    }

    // ---------- tag Google Ads ----------
    private const LOAD_RE = '/DOMContentLoaded|[\'"]load[\'"]|window\.onload|\$\(\s*(?:document\s*\)\.ready|function)|\bready\s*\(/i';
    private const SUBMIT_RE = '/addEventListener\(\s*[\'"]submit[\'"]|\.on\(\s*[\'"]submit|onsubmit|\.submit\s*\(|wpcf7mailsent|mailsent|\bsuccess\b|onSuccess|\.done\s*\(|thank/i';

    /**
     * Is the call at position (byte) $pos inside a form-submit handler, or does it run immediately?
     * Scans curly braces (skipping strings and comments). In an HTML document it analyzes only the current <script> block.
     * @return array{0:string,1:string} [fires (submit|load|unknown), reason]
     */
    public static function firesContext(string $src, int $pos): array
    {
        $before = substr($src, max(0, $pos - 160), min(160, $pos));
        if (preg_match('/\bon(submit|click|load)\s*=\s*["\'][^"\']*\z/i', $before, $m)) {
            return match (strtolower($m[1])) {
                'submit' => ['submit', 'onsubmit attribute'],
                'load' => ['load', 'onload attribute'],
                default => ['unknown', 'onclick attribute (a click, not necessarily a form submit)'],
            };
        }
        $stack = [];
        $open = strrpos(strtolower(substr($src, 0, $pos + 7)), '<script');
        $openEnd = $open === false ? false : strpos($src, '>', $open);
        $i = ($openEnd === false || $openEnd > $pos) ? 0 : $openEnd + 1;
        $len = strlen($src);
        while ($i < $pos) {
            $c = $src[$i];
            $d = $src[$i + 1] ?? '';
            if ($c === '/' && $d === '/') {
                $e = strpos($src, "\n", $i);
                $i = $e === false ? $pos : $e + 1;
                continue;
            }
            if ($c === '/' && $d === '*') {
                $e = strpos($src, '*/', $i + 2);
                $i = $e === false ? $pos : $e + 2;
                continue;
            }
            if ($c === '"' || $c === "'" || $c === '`') {
                $j = $i + 1;
                while ($j < $len && $src[$j] !== $c) {
                    if ($src[$j] === '\\') {
                        $j++;
                    }
                    $j++;
                }
                $i = $j + 1;
                continue;
            }
            if ($c === '{') {
                $pre = rtrim(substr($src, max(0, $i - 200), min(200, $i)));
                $stack[] = ['fn' => (bool) (preg_match('/=>\z/', $pre) || preg_match('/\bfunction\b[^{}]*\)\z/', $pre)), 'at' => $i];
            } elseif ($c === '}') {
                array_pop($stack);
            }
            $i++;
        }
        $fns = array_values(array_filter($stack, fn($s) => $s['fn']));
        if (!$fns) {
            return ['load', 'call outside any function — runs immediately when the page loads'];
        }
        for ($k = count($fns) - 1; $k >= 0; $k--) {
            $from = max(0, $fns[$k]['at'] - 400);
            $head = substr($src, $from, $fns[$k]['at'] - $from);
            if (preg_match(self::SUBMIT_RE, substr($src, $from, $pos - $from))) {
                return ['submit', 'call in a form-submit / success handler'];
            }
            if (preg_match(self::LOAD_RE, $head)) {
                return ['load', 'call in a page-load handler'];
            }
        }
        return ['unknown', 'call in a function whose trigger was not recognized'];
    }

    private static function meta(array $s): array
    {
        return ['source' => $s['source']] + (isset($s['scriptUrl']) ? ['scriptUrl' => $s['scriptUrl']] : []);
    }

    /**
     * @param list<array{source:string,scriptUrl?:string,text:string}> $sources
     * @param array{available:bool,awId?:string,label?:string,note?:string} $expected
     * @param array{foreignScriptHosts:list<string>,redirectAfterSubmit:bool} $extra
     */
    public static function analyzeTag(array $sources, array $expected, array $extra): array
    {
        $occ = [];
        foreach ($sources as $s) {
            $meta = self::meta($s);
            $t = $s['text'];
            $each = function (string $re, callable $fn) use ($t): void {
                if (preg_match_all($re, $t, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    foreach ($all as $m) {
                        $fn($m);
                    }
                }
            };
            $each('/googletagmanager\.com\/gtag\/js\?id=([A-Za-z0-9_-]+)/', function ($m) use (&$occ, $meta, $t) {
                $occ[] = $meta + ['kind' => 'gtag_js', 'awId' => $m[1][0], 'snippet' => self::byteSnippet($t, $m[0][1], strlen($m[0][0]), 60, 80)];
            });
            $each('/googletagmanager\.com\/gtm\.js\?id=(GTM-[A-Z0-9]+)/', function ($m) use (&$occ, $meta, $t) {
                $occ[] = $meta + ['kind' => 'gtm', 'awId' => $m[1][0], 'snippet' => self::byteSnippet($t, $m[0][1], strlen($m[0][0]), 60, 80)];
            });
            $each('/gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"](AW-\d+)[\'"]/', function ($m) use (&$occ, $meta, $t) {
                $occ[] = $meta + ['kind' => 'config', 'awId' => $m[1][0], 'snippet' => self::byteSnippet($t, $m[0][1], strlen($m[0][0]), 60, 80)];
            });
            $each('/[\'"]?send_to[\'"]?\s*:\s*[\'"](AW-\d+)\/([^\'"\s]+)[\'"]/', function ($m) use (&$occ, $meta, $t) {
                [$fires, $reason] = self::firesContext($t, $m[0][1]);
                $occ[] = $meta + ['kind' => 'conversion_event', 'awId' => $m[1][0], 'label' => $m[2][0], 'firesOn' => $fires, 'firesReason' => $reason,
                    'snippet' => self::byteSnippet($t, $m[0][1], strlen($m[0][0]), 60, 80)];
            });
            $each('/AW-\d{6,}/', function ($m) use (&$occ, $meta, $t) {
                foreach ($occ as $o) {
                    if ($o['source'] === $meta['source'] && ($o['scriptUrl'] ?? null) === ($meta['scriptUrl'] ?? null) && ($o['awId'] ?? null) === $m[0][0] && str_contains($o['snippet'], $m[0][0])) {
                        return;
                    }
                }
                $occ[] = $meta + ['kind' => 'id', 'awId' => $m[0][0], 'snippet' => self::byteSnippet($t, $m[0][1], strlen($m[0][0]), 60, 80)];
            });
        }
        $ofKind = fn(array $kinds) => array_values(array_filter($occ, fn($o) => in_array($o['kind'], $kinds, true)));
        $aw = array_values(array_unique(array_map(fn($o) => $o['awId'], $ofKind(['config', 'conversion_event', 'id']))));
        $labels = array_values(array_unique(array_filter(array_map(fn($o) => $o['label'] ?? '', $occ), fn($l) => $l !== '')));
        $events = $ofKind(['conversion_event']);
        $gtmIds = array_values(array_unique(array_map(fn($o) => $o['awId'], $ofKind(['gtm']))));
        $gtagIds = array_values(array_unique(array_map(fn($o) => $o['awId'], $ofKind(['gtag_js']))));
        $matches = [
            'awId' => ($expected['awId'] ?? '') !== '' ? in_array($expected['awId'], $aw, true) : null,
            'label' => ($expected['label'] ?? '') !== '' ? in_array($expected['label'], $labels, true) : null,
        ];
        $invisible = [];
        if ($gtmIds) {
            $invisible[] = 'GTM container ' . implode(', ', $gtmIds) . ' (its contents are not visible from the HTML)';
        }
        if ($extra['foreignScriptHosts']) {
            $invisible[] = 'scripts from foreign domains: ' . implode(', ', $extra['foreignScriptHosts']) . ' (not fetched)';
        }
        $firesOn = 'unknown';
        if ($events) {
            $kinds = array_unique(array_map(fn($e) => $e['firesOn'], $events));
            $firesOn = in_array('load', $kinds, true) ? 'load' : (count($kinds) === 1 && in_array('submit', $kinds, true) ? 'submit' : 'unknown');
            $firesReason = $firesOn === 'load' ? 'at least one conversion call runs immediately on page load — it counts visits, not leads'
                : ($firesOn === 'submit' ? 'conversion calls are in the form-submit handler' : 'the trigger of the conversion call was not recognized');
        } else {
            $firesReason = 'no conversion event call found in the HTML or in scripts from this domain'
                . ($extra['redirectAfterSubmit'] ? '; after the form is submitted the page redirects (window.location) — the conversion may be fired on the redirect target page' : '')
                . ($invisible ? '; code not visible: ' . implode('; ', $invisible) : '')
                . ($aw ? '' : '; no AW- identifier found either');
        }
        $gtagPresent = $gtagIds !== [] || (bool) array_filter($occ, fn($o) => $o['kind'] === 'config');
        return ['gtagPresent' => $gtagPresent, 'gtagIds' => $gtagIds, 'gtmIds' => $gtmIds, 'awIds' => $aw, 'labels' => $labels, 'expected' => $expected,
            'matchesConfig' => $matches, 'firesOn' => $firesOn, 'firesReason' => $firesReason, 'invisible' => $invisible, 'occurrences' => $occ];
    }

    // ---------- consent (Consent Mode v2) ----------
    private const CONSENT_KEYS = ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'];
    private const CMP_PATTERNS = [
        'Cookiebot' => '/cookiebot\.com|\bCookiebot\b/i', 'CookieYes' => '/cookieyes\.com|\bcky-consent|\bcookieyes\b/i', 'Complianz' => '/complianz|\bcmplz[-_]/i',
        'Klaro' => '/\bklaro(?:js|Config)?\b/i', 'OneTrust' => '/onetrust|optanon/i', 'Usercentrics' => '/usercentrics/i', 'iubenda' => '/iubenda/i',
        'lp2 own banner' => '/\blp2ConsentState\b|\blp2_consent\b|\blp2-consent/i',
    ];
    /** Default definition from lp2 (FrontendRegistration.php): always returns true. */
    private const DEFAULT_CAN_TRACK_RE = '/^lp2CanTrack\s*=\s*(?:window\.lp2CanTrack\s*\|\|\s*)?function\s*\(\s*\)\s*\{\s*return\s+true\s*;?\s*\}/';

    /**
     * Detects the consent mode: gtag('consent','default'|'update',{…}), CMP, the lp2CanTrack definition (heuristic over the HTML and scripts from this domain).
     * advanced = default "denied" for ads and gtag loaded unconditionally; basic = banner/lp2CanTrack blocks the tag on refusal; none = no traces.
     * @param list<array{source:string,scriptUrl?:string,text:string}> $sources
     */
    public static function analyzeConsent(array $sources, array $foreignScriptHosts = []): array
    {
        $calls = [];
        $cmps = [];
        $defs = [];
        $gtagLoaded = false;
        $canTrackUses = 0;
        foreach ($sources as $s) {
            $meta = self::meta($s);
            $t = $s['text'];
            if (preg_match_all('/gtag\(\s*[\'"]consent[\'"]\s*,\s*[\'"](default|update)[\'"]\s*,\s*\{([^}]*)\}/', $t, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    $values = [];
                    foreach (self::CONSENT_KEYS as $k) {
                        if (preg_match('/[\'"]?' . $k . '[\'"]?\s*:\s*[\'"](granted|denied)[\'"]/', $m[2][0], $v)) {
                            $values[$k] = $v[1];
                        }
                    }
                    $calls[] = $meta + ['kind' => $m[1][0], 'values' => $values, 'snippet' => self::byteSnippet($t, $m[0][1], strlen($m[0][0]), 20, 20)];
                }
            }
            foreach (self::CMP_PATTERNS as $name => $re) {
                if (preg_match($re, $t) && !in_array($name, $cmps, true)) {
                    $cmps[] = $name;
                }
            }
            if (preg_match('/googletagmanager\.com\/gtag\/js\?id=|gtag\(\s*[\'"]config[\'"]/', $t)) {
                $gtagLoaded = true;
            }
            $canTrackUses += preg_match_all('/lp2CanTrack\s*\(/', $t);
            if (preg_match_all('/lp2CanTrack\s*=\s*/', $t, $all, PREG_OFFSET_CAPTURE)) {
                foreach ($all[0] as [, $idx]) {
                    $body = substr($t, $idx, 400);
                    $isDefault = (bool) preg_match(self::DEFAULT_CAN_TRACK_RE, $body);
                    $deps = [];
                    if (!$isDefault && preg_match_all('/(?:localStorage|cookie|getCookie|consent[A-Za-z_]*|Cookiebot|cmplz[A-Za-z_]*|cky[A-Za-z_-]*)/i', $body, $dm)) {
                        $deps = array_slice(array_values(array_unique($dm[0])), 0, 6);
                    }
                    $defs[] = ['kind' => $isDefault ? 'default (always true)' : 'overridden', 'snippet' => mb_substr(self::oneLine($body), 0, 160)] + $meta + ['dependsOn' => $deps];
                }
            }
        }
        $adDenied = (bool) array_filter($calls, fn($c) => $c['kind'] === 'default' && (($c['values']['ad_storage'] ?? '') === 'denied' || ($c['values']['ad_user_data'] ?? '') === 'denied'));
        $hasDefaults = (bool) array_filter($calls, fn($c) => $c['kind'] === 'default');
        $adGranted = $hasDefaults && !$adDenied;
        $overridden = (bool) array_filter($defs, fn($d) => $d['kind'] === 'overridden');
        $defaultDef = (bool) array_filter($defs, fn($d) => $d['kind'] === 'default (always true)');
        $hasCmp = $cmps !== [];
        $invisible = $foreignScriptHosts ? ' Scripts from foreign domains (' . implode(', ', $foreignScriptHosts) . ') are not visible, so the result may be incomplete.' : '';
        if ($adDenied && $gtagLoaded) {
            $mode = 'advanced';
            $reason = 'gtag(\'consent\',\'default\') sets "denied" for ads while the gtag tag loads unconditionally — on refusal, pings without cookies are sent (advanced Consent Mode).'
                . ($hasCmp ? ' Banner: ' . implode(', ', $cmps) . '.' : ' No banner/CMP detected, so nothing changes the default "denied" to "granted" after consent — check gtag(\'consent\',\'update\').');
        } elseif ($adDenied) {
            $mode = 'basic';
            $reason = 'default "denied" is set, but no unconditionally loaded gtag tag was detected — the tag most likely starts only after consent (basic mode).';
        } elseif ($hasCmp || $overridden) {
            $mode = 'basic';
            $reason = ($hasCmp ? 'Banner/CMP detected (' . implode(', ', $cmps) . ') ' : '') . ($overridden ? 'and an overridden lp2CanTrack ' : '')
                . 'without a default "denied" in gtag(\'consent\',\'default\') — the tag is blocked or released by the banner, and nothing is sent on refusal (basic mode).';
        } else {
            $mode = 'none';
            $reason = 'No gtag(\'consent\') and no consent banner;' . ($defaultDef ? ' lp2CanTrack is the default and always returns true' : ' lp2CanTrack not found')
                . ' — the tag always sends data, and the consent status is unknown.';
        }
        if ($adGranted) {
            $reason .= ' Note: the default consent values are "granted" — no protection until the user refuses.';
        }
        $label = $mode === 'advanced' ? 'advanced mode (cookieless pings when consent is denied)'
            : ($mode === 'basic' ? 'basic mode (nothing is sent when consent is denied)' : 'no consent mode');
        // values as a JSON object ({} when empty), as in Node
        $calls = array_map(fn($c) => array_merge($c, ['values' => (object) $c['values']]), $calls);
        return ['mode' => $mode, 'label' => $label, 'reason' => $reason . $invisible, 'consentCalls' => $calls, 'cmps' => $cmps, 'gtagLoaded' => $gtagLoaded,
            'lp2CanTrack' => ['definitions' => $defs, 'overridden' => $overridden, 'usedInCode' => $canTrackUses]];
    }

    /** @return list<string> src values from <script src> tags */
    public static function scriptSrcs(HNode $root): array
    {
        $out = [];
        Html::walk($root, function (HNode $n) use (&$out): void {
            if ($n->isEl() && $n->tag === 'script' && ($n->attrs['src'] ?? '') !== '') {
                $out[] = $n->attrs['src'];
            }
        });
        return $out;
    }
}
