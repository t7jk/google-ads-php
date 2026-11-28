<?php
declare(strict_types=1);

namespace App\Media;

/**
 * Read-only YouTube client (port of src/media/youtube-channel.ts): recognizes pasted text (video and channel URLs),
 * fetches the channel's video list (RSS, or the API v3 when youtube_api_key is in the config), titles via oEmbed.
 * Security: never fetches a user-supplied URL — URLs are built here from verified identifiers
 * and lead only to youtube.com / www.googleapis.com (every redirect is checked).
 * Wideo: tablica {id, title, published?, durationSec?, channel?, unavailable?}.
 */
final class YoutubeChannel
{
    public const MIN_PMAX_VIDEO_SEC = 10;
    public const MAX_INPUT_CHARS = 5000;
    public const MAX_VIDEO_LINKS = 50;
    public const MAX_CHANNELS = 5;
    private const TIMEOUT_S = 10;
    private const MAX_HTML = 3_145_728;
    private const MAX_DATA = 1_048_576;
    private const MAX_REDIRECTS = 3;
    private const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const CONSENT_COOKIE = 'SOCS=CAI; CONSENT=YES+1'; // bypasses the EU consent page
    public const RSS_NOTE = 'Showing up to the 15 newest videos; to add older or unlisted ones, paste the video link.';
    private const ID_RE = '/^[A-Za-z0-9_-]{11}$/';
    private const CHANNEL_ID_RE = '/^UC[A-Za-z0-9_-]{22}$/';
    private const NAME_RE = '/^[\p{L}\p{N}._-]{1,100}$/u';
    private const INPUT_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'];

    /** Video ID from a youtube.com / youtu.be URL or a bare ID; null when it is not a video. */
    public static function videoId(string $src): ?string
    {
        $s = trim($src);
        if (preg_match(self::ID_RE, $s)) {
            return $s;
        }
        $u = parse_url($s);
        if ($u === false || !isset($u['host'])) {
            return null;
        }
        $host = (string) preg_replace('/^(www\.|m\.)/', '', strtolower($u['host']));
        $path = $u['path'] ?? '';
        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', $path)[1] ?? null;
        } elseif ($host === 'youtube.com' || $host === 'music.youtube.com') {
            if ($path === '/watch') {
                parse_str($u['query'] ?? '', $q);
                $id = is_string($q['v'] ?? null) ? $q['v'] : null;
            } elseif (preg_match('#^/(?:shorts|embed|live)/([^/]+)#', $path, $m)) {
                $id = $m[1];
            }
        }
        return $id !== null && preg_match(self::ID_RE, $id) ? $id : null;
    }

    /**
     * Extracts video IDs and channel references from the text; fetches nothing, rejects foreign hosts.
     * @return array{videos:list<string>, channels:list<array>, rejected:list<array{text:string,reason:string}>}
     */
    public static function parseInput(string $text): array
    {
        $out = ['videos' => [], 'channels' => [], 'rejected' => []];
        $seenC = [];
        $addChannel = function (array $c) use (&$out, &$seenC): void {
            $k = $c['kind'] . ':' . ($c['id'] ?? $c['name']);
            if (!isset($seenC[$k])) {
                $seenC[$k] = true;
                $out['channels'][] = $c;
            }
        };
        $reject = function (string $t, string $reason) use (&$out): void {
            $out['rejected'][] = ['text' => mb_substr($t, 0, 80), 'reason' => $reason];
        };
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match(self::CHANNEL_ID_RE, $tok)) {
                $addChannel(['kind' => 'id', 'id' => $tok]);
                continue;
            }
            if ($tok[0] === '@') {
                $n = substr($tok, 1);
                preg_match(self::NAME_RE, $n) ? $addChannel(['kind' => 'handle', 'name' => $n]) : $reject($tok, 'invalid channel name');
                continue;
            }
            if (preg_match(self::ID_RE, $tok)) {
                if (!in_array($tok, $out['videos'], true)) {
                    $out['videos'][] = $tok;
                }
                continue;
            }
            $withScheme = preg_match('#^(?:www\.|m\.)?(?:youtube\.com|youtu\.be)(?:[/?\#]|$)#i', $tok) ? "https://$tok" : $tok;
            $u = parse_url($withScheme);
            if ($u === false || !isset($u['scheme'], $u['host'])) {
                $reject($tok, 'does not look like a YouTube URL');
                continue;
            }
            if (!in_array(strtolower($u['scheme']), ['http', 'https'], true)) {
                $reject($tok, 'only http(s) URLs are allowed');
                continue;
            }
            $host = strtolower($u['host']);
            if (!in_array($host, self::INPUT_HOSTS, true)) {
                $reject($tok, 'only youtube.com and youtu.be URLs are allowed');
                continue;
            }
            $vid = self::videoId("https://$host" . ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : ''));
            if ($vid !== null) {
                if (!in_array($vid, $out['videos'], true)) {
                    $out['videos'][] = $vid;
                }
                continue;
            }
            $seg = array_map('rawurldecode', array_values(array_filter(explode('/', $u['path'] ?? ''), fn($s) => $s !== '')));
            if ($host !== 'youtu.be' && isset($seg[0])) {
                if ($seg[0][0] === '@' && preg_match(self::NAME_RE, substr($seg[0], 1))) {
                    $addChannel(['kind' => 'handle', 'name' => substr($seg[0], 1)]);
                    continue;
                }
                if ($seg[0] === 'channel' && preg_match(self::CHANNEL_ID_RE, $seg[1] ?? '')) {
                    $addChannel(['kind' => 'id', 'id' => $seg[1]]);
                    continue;
                }
                if (($seg[0] === 'c' || $seg[0] === 'user') && preg_match(self::NAME_RE, $seg[1] ?? '')) {
                    $addChannel(['kind' => $seg[0], 'name' => $seg[1]]);
                    continue;
                }
            }
            $reject($tok, 'neither a video nor a channel was recognized');
        }
        return $out;
    }

    public static function formatDuration(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** Czas ISO 8601 (PT1M2S) w sekundach. */
    public static function parseIsoDuration(string $s): ?int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $s, $m)) {
            return null;
        }
        return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) ($m[4] ?? 0);
    }

    private static function xmlTag(string $block, string $name): ?string
    {
        if (!preg_match('#<' . preg_quote($name, '#') . '[^>]*>(.*?)</' . preg_quote($name, '#') . '>#s', $block, $m)) {
            return null;
        }
        return trim(html_entity_decode(trim((string) preg_replace('/<!\[CDATA\[|\]\]>/', '', $m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** @return array{channel:?string, videos:list<array>} YouTube RSS channel */
    public static function parseFeed(string $xml): array
    {
        $first = strpos($xml, '<entry>');
        $channel = self::xmlTag($first === false ? $xml : substr($xml, 0, $first), 'title');
        $videos = [];
        if (preg_match_all('#<entry>(.*?)</entry>#s', $xml, $all)) {
            foreach ($all[1] as $block) {
                $id = self::xmlTag($block, 'yt:videoId');
                if ($id === null || !preg_match(self::ID_RE, $id)) {
                    continue;
                }
                $v = ['id' => $id, 'title' => self::xmlTag($block, 'title') ?? ''];
                if (($p = self::xmlTag($block, 'published')) !== null) {
                    $v['published'] = $p;
                }
                if ($channel !== null) {
                    $v['channel'] = $channel;
                }
                $videos[] = $v;
            }
        }
        return ['channel' => $channel, 'videos' => $videos];
    }

    public static function extractChannelId(string $html): ?string
    {
        $pats = [
            '#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})["\']#',
            '#<link[^>]+href=["\']https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})["\'][^>]+rel=["\']canonical["\']#',
            '#"externalId":"(UC[A-Za-z0-9_-]{22})"#',
            '#itemprop=["\'](?:identifier|channelId)["\'][^>]+content=["\'](UC[A-Za-z0-9_-]{22})["\']#',
            '#content=["\'](UC[A-Za-z0-9_-]{22})["\'][^>]+itemprop=["\'](?:identifier|channelId)["\']#',
        ];
        foreach ($pats as $p) {
            if (preg_match($p, $html, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /** Only youtube.com and the API. */
    private static function allowedUrl(string $raw): bool
    {
        $u = parse_url($raw);
        if ($u === false || strtolower($u['scheme'] ?? '') !== 'https' || !isset($u['host'])) {
            return false;
        }
        $h = strtolower($u['host']);
        return $h === 'youtube.com' || str_ends_with($h, '.youtube.com') || $h === 'www.googleapis.com';
    }

    /** @return array{status:int, text:string} @throws YoutubeError */
    private static function get(string $url, int $max): array
    {
        $cur = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::allowedUrl($cur)) {
                throw new YoutubeError('The redirect outside youtube.com was blocked.');
            }
            $headers = ['User-Agent' => self::UA, 'Accept-Language' => 'pl,en;q=0.8'];
            if (!str_starts_with($cur, 'https://www.googleapis.com/')) {
                $headers['Cookie'] = self::CONSENT_COOKIE;
            }
            try {
                $r = Download::get($cur, $headers, $max, self::TIMEOUT_S);
            } catch (\RuntimeException) {
                throw new YoutubeError('Could not connect to YouTube (or the 10 s timeout was exceeded).');
            }
            if ($r['status'] >= 300 && $r['status'] < 400) {
                $loc = $r['headers']['location'] ?? '';
                $next = $loc !== '' ? \App\Lp2\UrlHost::resolve($loc, $cur) : null;
                if ($next === null) {
                    throw new YoutubeError('Invalid YouTube redirect.');
                }
                $cur = $next;
                continue;
            }
            if ($r['truncated']) {
                throw new YoutubeError('The YouTube response is too large.');
            }
            return ['status' => $r['status'], 'text' => $r['body']];
        }
        throw new YoutubeError('Too many YouTube redirects.');
    }

    private static function api(string $path, array $params): array
    {
        $qs = http_build_query($params + ['key' => (string) config('youtube_api_key', '')]);
        $r = self::get("https://www.googleapis.com/youtube/v3/$path?$qs", self::MAX_DATA);
        if ($r['status'] !== 200) {
            throw new YoutubeError("The YouTube API returned an error (HTTP {$r['status']}).");
        }
        $j = json_decode($r['text'], true);
        if (!is_array($j)) {
            throw new YoutubeError('Invalid YouTube API response.');
        }
        return $j;
    }

    /** Title and author of a video via oEmbed (also works for non-public "unlisted" videos). */
    public static function oembed(string $id): array
    {
        if (!preg_match(self::ID_RE, $id)) {
            return ['id' => $id, 'title' => '', 'unavailable' => true];
        }
        try {
            $r = self::get('https://www.youtube.com/oembed?url=' . rawurlencode("https://www.youtube.com/watch?v=$id") . '&format=json', self::MAX_DATA);
            $j = json_decode($r['text'], true);
            if ($r['status'] !== 200 || !is_array($j)) {
                return ['id' => $id, 'title' => '', 'unavailable' => true];
            }
            return ['id' => $id, 'title' => is_string($j['title'] ?? null) ? $j['title'] : ''] + (is_string($j['author_name'] ?? null) ? ['channel' => $j['author_name']] : []);
        } catch (YoutubeError) {
            return ['id' => $id, 'title' => '', 'unavailable' => true];
        }
    }

    private static function resolveChannelId(array $ref): string
    {
        if ($ref['kind'] === 'id') {
            return $ref['id'];
        }
        $enc = rawurlencode($ref['name']);
        $url = match ($ref['kind']) {
            'handle' => "https://www.youtube.com/@$enc",
            'c' => "https://www.youtube.com/c/$enc",
            default => "https://www.youtube.com/user/$enc",
        };
        $r = self::get($url, self::MAX_HTML);
        if ($r['status'] === 404) {
            throw new YoutubeError('No such YouTube channel found.');
        }
        $id = $r['status'] === 200 ? self::extractChannelId($r['text']) : null;
        return $id ?? throw new YoutubeError('Could not read the channel ID from the YouTube page — paste the address as youtube.com/channel/UC… or video links.');
    }

    private static function viaRss(array $ref): array
    {
        $id = self::resolveChannelId($ref);
        $r = self::get("https://www.youtube.com/feeds/videos.xml?channel_id=$id", self::MAX_DATA);
        if ($r['status'] !== 200) {
            throw new YoutubeError("Could not fetch the channel's video list (HTTP {$r['status']}).");
        }
        $f = self::parseFeed($r['text']);
        return ['channel' => $f['channel'], 'videos' => $f['videos'], 'notes' => [self::RSS_NOTE]];
    }

    private static function viaApi(array $ref): array
    {
        $q = match ($ref['kind']) {
            'id' => ['id' => $ref['id']],
            'handle' => ['forHandle' => '@' . $ref['name']],
            'user' => ['forUsername' => $ref['name']],
            default => ['id' => self::resolveChannelId($ref)],
        };
        $ch = self::api('channels', ['part' => 'contentDetails,snippet'] + $q)['items'][0] ?? null;
        $uploads = $ch['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (!is_string($uploads)) {
            throw new YoutubeError('The YouTube API did not find the channel.');
        }
        $channel = $ch['snippet']['title'] ?? null;
        $videos = [];
        $pageToken = null;
        for ($page = 0; $page < 4; $page++) {
            $j = self::api('playlistItems', ['part' => 'contentDetails,snippet', 'maxResults' => '50', 'playlistId' => $uploads] + ($pageToken ? ['pageToken' => $pageToken] : []));
            foreach ($j['items'] ?? [] as $it) {
                $id = $it['contentDetails']['videoId'] ?? null;
                $title = $it['snippet']['title'] ?? null;
                if (!is_string($id) || !preg_match(self::ID_RE, $id) || $title === 'Private video' || $title === 'Deleted video') {
                    continue;
                }
                $videos[$id] = ['id' => $id, 'title' => (string) $title, 'published' => $it['contentDetails']['videoPublishedAt'] ?? $it['snippet']['publishedAt'] ?? null, 'channel' => $channel];
            }
            $pageToken = is_string($j['nextPageToken'] ?? null) ? $j['nextPageToken'] : null;
            if (!$pageToken) {
                break;
            }
        }
        foreach (array_chunk(array_keys($videos), 50) as $ids) {
            foreach (self::api('videos', ['part' => 'contentDetails,snippet', 'id' => implode(',', $ids)])['items'] ?? [] as $it) {
                if (isset($videos[$it['id'] ?? ''], $it['contentDetails']['duration'])) {
                    $d = self::parseIsoDuration($it['contentDetails']['duration']);
                    if ($d !== null) {
                        $videos[$it['id']]['durationSec'] = $d;
                    }
                }
            }
        }
        return ['channel' => $channel, 'videos' => array_values($videos), 'notes' => []];
    }

    private static function channel(array $ref): array
    {
        if ((string) config('youtube_api_key', '') !== '') {
            try {
                return self::viaApi($ref);
            } catch (YoutubeError $e) {
                $value = self::viaRss($ref);
                array_unshift($value['notes'], "YouTube API unavailable ({$e->getMessage()}) — the RSS feed was used.");
                return $value;
            }
        }
        return self::viaRss($ref);
    }

    /**
     * Recognizes the pasted text and returns a merged, de-duplicated list of videos plus notes and problems.
     * @return array{videos:list<array>, notes:list<string>, problems:list<string>}
     */
    public static function listing(string $input): array
    {
        $p = self::parseInput(mb_substr($input, 0, self::MAX_INPUT_CHARS));
        $problems = array_map(fn($r) => "{$r['text']} — {$r['reason']}", $p['rejected']);
        $notes = [];
        $out = [];
        foreach (array_slice($p['channels'], 0, self::MAX_CHANNELS) as $ref) {
            $name = $ref['id'] ?? ($ref['kind'] === 'handle' ? '@' . $ref['name'] : $ref['name']);
            try {
                $r = self::channel($ref);
                foreach ($r['videos'] as $v) {
                    $out[$v['id']] ??= $v;
                }
                foreach ($r['notes'] as $n) {
                    if (!in_array($n, $notes, true)) {
                        $notes[] = $n;
                    }
                }
                if (!$r['videos']) {
                    $problems[] = 'Channel ' . ($ref['id'] ?? '@' . $ref['name']) . ' has no public videos.';
                }
            } catch (YoutubeError $e) {
                $problems[] = "Channel $name: " . $e->getMessage();
            }
        }
        if (count($p['channels']) > self::MAX_CHANNELS) {
            $problems[] = 'Skipped channels over the limit (' . self::MAX_CHANNELS . ').';
        }
        $ids = array_slice(array_values(array_filter($p['videos'], fn($id) => !isset($out[$id]))), 0, self::MAX_VIDEO_LINKS);
        if (count($p['videos']) > self::MAX_VIDEO_LINKS) {
            $problems[] = 'Skipped video links over the limit (' . self::MAX_VIDEO_LINKS . ').';
        }
        foreach ($ids as $id) {
            $out[$id] = self::oembed($id);
        }
        return ['videos' => array_values($out), 'notes' => $notes, 'problems' => $problems];
    }
}
