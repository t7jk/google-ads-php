<?php
declare(strict_types=1);

namespace App\Lp2;

use App\Ads\{Params, UserError};
use App\Lib\Mcp;

/** Logic of the verify_lp2 tool (port of src/mcp/tools-lp2.ts): reads the landing page and analyzes the tag, consent, phrases, <select> lists. GET only. */
final class Verify
{
    private const MAX_SCRIPTS = 10;
    private const REDIRECT_RE = '/window\.location(?:\.href)?\s*=|location\.(?:assign|replace)\s*\(/';

    /** Default URLs: the first host from allowed_url_hosts + landing_path_prefix, with and without a trailing slash. */
    public static function defaultUrls(): array
    {
        $host = UrlHost::allowed()[0] ?? '';
        $base = rtrim((string) config('landing_path_prefix', ''), '/');
        return $base !== '' ? ["https://$host$base", "https://$host$base/"] : ["https://$host/"];
    }

    private static function fail(string $text, array $structured): array
    {
        return Mcp::error($text) + ['structuredContent' => $structured];
    }

    /** Text from a JS-style value (`${x ?? "?"}`): null -> ?, bool -> true/false. */
    private static function s(mixed $v, string $fallback = '?'): string
    {
        return $v === null ? $fallback : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }

    public static function run(array $args): array
    {
        $testParams = Params::bool($args, 'testParams', true);
        $fetchScripts = Params::bool($args, 'fetchScripts', true);
        $urls = $args['urls'] ?? null;
        if ($urls !== null && (!is_array($urls) || count($urls) > 6)) {
            throw new UserError('Parameter urls must be an array of up to 6 URLs');
        }
        $forbidden = $args['forbiddenPhrases'] ?? null;
        if ($forbidden !== null && (!is_array($forbidden) || count($forbidden) > 50)) {
            throw new UserError('Parameter forbiddenPhrases must be an array of up to 50 phrases');
        }
        try {
            return self::verify($urls ?: self::defaultUrls(), $forbidden ?: (array) config('lp2_forbidden_phrases', []),
                $testParams, (string) ($args['thankYouUrl'] ?? config('lp2_thankyou_path', '')), $fetchScripts);
        } catch (\Throwable $e) {
            return self::fail('LP2 verification error: ' . $e->getMessage(), []);
        }
    }

    /** @return array{available:bool, awId?:string, label?:string, note?:string} expected tag — the same source as get_conversion_tag */
    private static function expectedTag(): array
    {
        $name = (string) config('conversion_action_name', '');
        try {
            $t = ConversionTag::find();
            if (($t['conversionId'] ?? '') !== '' && ($t['label'] ?? '') !== '') {
                return ['available' => true, 'awId' => $t['conversionId'], 'label' => $t['label']];
            }
            return ['available' => false, 'note' => $t['found']
                ? 'the conversion action exists, but Google has not returned the tag data yet — checking only that gtag is present'
                : "conversion action \"{$name}\" not found — checking only that gtag is present"];
        } catch (\Throwable $e) {
            return ['available' => false, 'note' => "could not determine the AW-ID and label ({$e->getMessage()}) — checking only that gtag is present"];
        }
    }

    private static function verify(array $list, array $phrasesIn, bool $testParams, string $thankYouRaw, bool $fetchScripts): array
    {
        $tyRaw = trim($thankYouRaw);
        $tyUrl = $tyRaw !== '' ? UrlHost::resolve($tyRaw, 'https://' . (UrlHost::allowed()[0] ?? '') . '/') : null;
        if ($tyRaw !== '' && $tyUrl === null) {
            throw new \RuntimeException('Invalid URL');
        }
        $bad = array_values(array_filter(array_map([UrlHost::class, 'check'], [...$list, ...($tyUrl ? [$tyUrl] : [])])));
        if ($bad) {
            return self::fail("Disallowed URLs:\n" . implode("\n", $bad), ['errors' => $bad]);
        }
        $phrases = array_values(array_filter(array_map('trim', array_map('strval', $phrasesIn)), fn($p) => $p !== ''));
        $expected = self::expectedTag();

        // redirect chains + parameters
        $redirects = [];
        $chains = [];
        foreach ($list as $url) {
            $chain = Fetch::chain($url);
            $params = $testParams ? Fetch::checkParams($url) : null;
            $chains[] = [$url, $chain];
            $redirects[] = ['url' => $url, 'hops' => $chain['hops'], 'finalUrl' => $chain['finalUrl'] ?? null, 'status' => $chain['status'] ?? null]
                + (isset($chain['blocked']) ? ['blocked' => $chain['blocked']] : []) + (isset($chain['error']) ? ['error' => $chain['error']] : [])
                + ($params ? ['paramsPreserved' => $params['paramsPreserved']] + (isset($params['lostAt']) ? ['paramsLostAtHop' => $params['lostAt']] : [])
                    + (isset($params['note']) ? ['paramsNote' => $params['note']] : []) : []);
        }

        // pages (unique final URLs)
        $pages = [];
        foreach ($chains as [$url, $chain]) {
            if (($chain['finalUrl'] ?? null) === null || ($chain['status'] ?? 0) !== 200 || !isset($chain['body'])) {
                continue;
            }
            $pages[$chain['finalUrl']] ??= ['chain' => $chain, 'from' => []];
            $pages[$chain['finalUrl']]['from'][] = $url;
        }
        $scriptCache = [];
        $fetched = 0;
        $pageResults = [];
        foreach ($pages as $finalUrl => ['chain' => $chain, 'from' => $from]) {
            $pageResults[] = self::analyzePage((string) $finalUrl, $chain, $from, $phrases, $expected, $fetchScripts, $scriptCache, $fetched);
        }

        // thank-you page: code run when it loads takes effect only after the form is submitted
        $thankYou = null;
        if ($tyUrl) {
            $c = Fetch::chain($tyUrl);
            $thankYou = ['url' => $tyUrl, 'hops' => $c['hops'], 'finalUrl' => $c['finalUrl'] ?? null, 'status' => $c['status'] ?? null]
                + (isset($c['blocked']) ? ['blocked' => $c['blocked']] : []) + (isset($c['error']) ? ['error' => $c['error']] : []);
            if (($c['status'] ?? 0) === 200 && isset($c['body'])) {
                $src = [['source' => 'html', 'text' => $c['body']]];
                $t = Analyze::analyzeTag($src, $expected, ['foreignScriptHosts' => [], 'redirectAfterSubmit' => false]);
                $hasEvent = (bool) array_filter($t['occurrences'], fn($x) => $x['kind'] === 'conversion_event');
                $thankYou['consent'] = Analyze::analyzeConsent($src);
                $thankYou['tag'] = array_merge($t, ['firesOn' => $hasEvent ? 'submit' : 'unknown',
                    'firesReason' => $hasEvent ? 'call on the thank-you page the form redirects to after submit; it will also count a direct visit to that URL' : $t['firesReason']]);
            }
        }

        $conversion = self::conversionSummary($pageResults, $thankYou, $tyUrl !== null);
        $text = self::renderText($conversion, $thankYou, $redirects, $pages, $pageResults, $phrases);
        return Mcp::text($text, ['urls' => $list, 'expected' => $expected, 'conversion' => $conversion, 'thankYou' => $thankYou, 'redirects' => $redirects,
            'pages' => $pageResults, 'phrasesConfigured' => $phrases] + ($phrases ? [] : ['phrasesNote' => 'The forbidden-phrases list is empty — phrase check skipped.']));
    }

    /** Scripts only from allowed hosts; the rest goes to "skipped". */
    private static function analyzePage(string $finalUrl, array $chain, array $from, array $phrases, array $expected, bool $fetchScripts, array &$scriptCache, int &$fetched): array
    {
        $html = $chain['body'];
        $root = Html::parse($html);
        $same = [];
        $skipped = [];
        $foreignHosts = [];
        foreach (Analyze::scriptSrcs($root) as $src) {
            $abs = UrlHost::resolve($src, $finalUrl);
            if ($abs === null) {
                $skipped[] = ['url' => $src, 'reason' => 'invalid URL'];
                continue;
            }
            if (UrlHost::check($abs) !== null) {
                $h = (string) parse_url($abs, PHP_URL_HOST);
                if ($h !== '' && !preg_match('/(^|\.)googletagmanager\.com$/', $h) && !in_array($h, $foreignHosts, true)) {
                    $foreignHosts[] = $h;
                }
                $skipped[] = ['url' => $abs, 'reason' => 'outside the allowed domains (not fetched)'];
            } elseif (!in_array($abs, $same, true)) {
                $same[] = $abs;
            }
        }
        $infos = [];
        foreach ($same as $abs) {
            if (!$fetchScripts) {
                $skipped[] = ['url' => $abs, 'reason' => 'script fetching disabled (fetchScripts=false)'];
                continue;
            }
            if (!isset($scriptCache[$abs])) {
                if ($fetched >= self::MAX_SCRIPTS) {
                    $skipped[] = ['url' => $abs, 'reason' => 'limit of ' . self::MAX_SCRIPTS . ' files'];
                    continue;
                }
                $fetched++;
                $c = Fetch::chain($abs);
                $scriptCache[$abs] = ['url' => $abs, 'status' => $c['status'] ?? null, 'bytes' => $c['bytes'] ?? null, 'truncated' => $c['truncated'] ?? false,
                    'error' => $c['error'] ?? null, 'blocked' => $c['blocked'] ?? null, 'text' => $c['body'] ?? null];
            }
            $infos[] = $scriptCache[$abs];
        }
        $withText = array_values(array_filter($infos, fn($i) => $i['text'] !== null));

        // phrases: HTML + scripts
        $phraseResults = Analyze::findPhrases($root, $phrases);
        foreach ($withText as $s) {
            foreach (Analyze::findPhrasesInScript($s['text'], $phrases, $s['url']) as $h) {
                foreach ($phraseResults as &$r) {
                    if ($r['phrase'] === $h['phrase']) {
                        $r['total']++;
                        $r['other']++;
                        if (count($r['hits']) < 20) {
                            $r['hits'][] = $h;
                        } else {
                            $r['truncated'] = true;
                        }
                        break;
                    }
                }
                unset($r);
            }
        }
        // tag and consent
        $sources = [['source' => 'html', 'text' => $html]];
        $redirectAfterSubmit = (bool) preg_match(self::REDIRECT_RE, $html);
        foreach ($withText as $s) {
            $sources[] = ['source' => 'script', 'scriptUrl' => $s['url'], 'text' => $s['text']];
            $redirectAfterSubmit = $redirectAfterSubmit || preg_match(self::REDIRECT_RE, $s['text']);
        }
        $tag = Analyze::analyzeTag($sources, $expected, ['foreignScriptHosts' => $foreignHosts, 'redirectAfterSubmit' => (bool) $redirectAfterSubmit]);
        $consent = Analyze::analyzeConsent($sources, $foreignHosts);
        $title = null;
        foreach (Html::allElements($root) as $e) {
            if ($e->tag === 'title') {
                $title = Html::textOf($e);
                break;
            }
        }
        $truncated = (bool) ($chain['truncated'] ?? false);
        return ['finalUrl' => $finalUrl, 'requestedFrom' => $from, 'bytes' => $chain['bytes'] ?? null, 'truncated' => $truncated]
            + ($truncated ? ['truncatedNote' => 'HTML truncated after 2 MB — the analysis covers only the beginning of the page'] : [])
            + ['title' => $title, 'scripts' => ['analyzed' => array_map(fn($i) => ['url' => $i['url'], 'status' => $i['status'], 'bytes' => $i['bytes'], 'truncated' => (bool) $i['truncated']]
                    + ($i['error'] ? ['error' => $i['error']] : []) + ($i['blocked'] ? ['blocked' => $i['blocked']] : []), $infos), 'skipped' => $skipped],
                'tag' => $tag, 'consent' => $consent, 'phrases' => $phraseResults, 'selectOptions' => Analyze::findSelects($root)];
    }

    /** Summary: where the conversion event is (landing page / thank-you page / nowhere). */
    private static function conversionSummary(array $pageResults, ?array $thankYou, bool $hasThankYou): array
    {
        $eventsIn = fn(?array $t) => $t !== null && array_filter($t['occurrences'] ?? [], fn($x) => $x['kind'] === 'conversion_event');
        $landingTag = null;
        foreach ($pageResults as $p) {
            if ($eventsIn($p['tag'])) {
                $landingTag = $p['tag'];
                break;
            }
        }
        $convTag = $eventsIn($thankYou['tag'] ?? null) ? $thankYou['tag'] : $landingTag;
        if ($convTag === null) {
            return ['location' => 'not_found', 'firesOn' => 'unknown',
                'firesReason' => 'no conversion call found on the landing page' . ($hasThankYou ? ' or on the thank-you page' : ' (LP2_THANKYOU_PATH is not set, so the thank-you page was not checked)'),
                'awIdMatches' => null, 'labelMatches' => null];
        }
        return ['location' => $convTag === $landingTag ? 'landing' : 'thank_you', 'firesOn' => $convTag['firesOn'], 'firesReason' => $convTag['firesReason'],
            'awIdMatches' => $convTag['matchesConfig']['awId'], 'labelMatches' => $convTag['matchesConfig']['label']];
    }

    private static function renderText(array $conversion, ?array $thankYou, array $redirects, array $pages, array $pageResults, array $phrases): string
    {
        $s = [self::class, 's'];
        $join = fn(array $a) => $a ? implode(', ', $a) : 'none';
        $L = [];
        $where = $conversion['location'] === 'thank_you' ? 'thank-you page' : ($conversion['location'] === 'landing' ? 'landing page' : 'NOT FOUND');
        $L[] = "Conversion: $where, fires on: {$conversion['firesOn']}, matches configuration: AW {$s($conversion['awIdMatches'])}, label {$s($conversion['labelMatches'])}. {$conversion['firesReason']}\n";
        if ($thankYou) {
            $tt = $thankYou['tag'] ?? null;
            $L[] = "Thank-you page {$thankYou['url']}: status {$s($thankYou['status'], '—')}" . (isset($thankYou['blocked']) ? " | {$thankYou['blocked']}" : '')
                . (isset($thankYou['error']) ? " | ERROR: {$thankYou['error']}" : '')
                . ($tt ? ", AW: {$join($tt['awIds'])}, labels: {$join($tt['labels'])}" : ', not analyzed') . '.';
            $events = array_values(array_filter($tt['occurrences'] ?? [], fn($x) => $x['kind'] === 'conversion_event'));
            foreach (array_slice($events, 0, 3) as $oc) {
                $L[] = "  • conversion {$oc['awId']}/{$oc['label']}: {$oc['snippet']}";
            }
            $L[] = '';
        }
        $L[] = 'Redirects:';
        foreach ($redirects as $r) {
            $chainTxt = implode(' → ', array_map(fn($h) => (($h['status'] ?? 0) ?: '—') . " {$h['url']}" . (isset($h['blocked']) ? ' (BLOCKED)' : ''), $r['hops']));
            $pp = $r['paramsPreserved'] ?? null;
            $L[] = "- {$r['url']}: $chainTxt" . (isset($r['blocked']) ? " | {$r['blocked']}" : '') . (isset($r['error']) ? " | ERROR: {$r['error']}" : '')
                . ($pp ? " | parameters: gclid {$s($pp['gclid'])}, gbraid {$s($pp['gbraid'])}, wbraid {$s($pp['wbraid'])}" : '');
        }
        if (!$pages) {
            $L[] = 'No page fetched (status 200).';
        }
        foreach ($pageResults as $p) {
            array_push($L, ...self::renderPage($p, $phrases));
        }
        return implode("\n", $L);
    }

    /** @return list<string> description lines for one page */
    private static function renderPage(array $p, array $phrases): array
    {
        $join = fn(array $a) => $a ? implode(', ', $a) : 'none';
        $s = [self::class, 's'];
        $L = ["\nPage {$p['finalUrl']}" . ($p['truncated'] ? ' (HTML truncated after 2 MB)' : '') . ', scripts from this domain: ' . count($p['scripts']['analyzed']) . '.'];
        $t = $p['tag'];
        $L[] = 'Tag: gtag ' . ($t['gtagPresent'] ? 'yes' : 'NO') . ", AW: {$join($t['awIds'])}, labels: {$join($t['labels'])}; matches configuration: AW {$s($t['matchesConfig']['awId'])}, label {$s($t['matchesConfig']['label'])}"
            . ($t['expected']['available'] ? '' : " ({$t['expected']['note']})") . "; conversion fires on: {$t['firesOn']}. {$t['firesReason']}";
        $occ = array_slice(array_values(array_filter($t['occurrences'], fn($x) => $x['kind'] === 'conversion_event' || $x['kind'] === 'config')), 0, 6);
        foreach ($occ as $oc) {
            $L[] = "  • {$oc['kind']} " . ($oc['awId'] ?? '') . (($oc['label'] ?? '') !== '' ? '/' . $oc['label'] : '') . ' ['
                . ($oc['source'] === 'script' ? "skrypt {$oc['scriptUrl']}" : 'HTML') . ']' . (($oc['firesOn'] ?? '') !== '' ? " → {$oc['firesOn']}" : '') . ": {$oc['snippet']}";
        }
        $c = $p['consent'];
        $calls = implode('; ', array_map(fn($x) => $x['kind'] . ' ' . implode(',', array_map(fn($k, $v) => "$k=$v", array_keys((array) $x['values']), (array) $x['values'])), $c['consentCalls']));
        $L[] = "Consent (Consent Mode v2): {$c['label']}. {$c['reason']}" . ($c['consentCalls'] ? " Calls: $calls." : '');
        if (!$phrases) {
            $L[] = 'Forbidden phrases: list EMPTY — nothing checked (set LP2_FORBIDDEN_PHRASES or pass forbiddenPhrases).';
        } else {
            foreach ($p['phrases'] as $r) {
                $L[] = "Phrase \"{$r['phrase']}\": {$r['total']} occurrences (visible {$r['visible']}, hidden {$r['hidden']}, other/invisible {$r['other']}).";
                foreach (array_slice($r['hits'], 0, 5) as $h) {
                    $state = $h['hidden'] ? " [HIDDEN: {$h['hiddenReason']}]" : ($h['visible'] ? ' [visible]' : ' [invisible content]');
                    $L[] = "  • {$h['where']}" . ($h['where'] === 'script' && ($h['scriptUrl'] ?? '') !== '' ? " {$h['scriptUrl']}" : '') . " {$h['kind']}"
                        . (($h['element'] ?? '') !== '' ? " {$h['element']}" : '') . "$state: {$h['snippet']}";
                }
            }
        }
        foreach ($p['selectOptions'] as $sel) {
            $L[] = 'Select list "' . ($sel['label'] ?? $sel['name'] ?? $sel['id'] ?? '?') . '": ' . implode(' | ', array_column($sel['options'], 'text'));
        }
        return $L;
    }
}
