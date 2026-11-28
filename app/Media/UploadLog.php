<?php
declare(strict_types=1);

namespace App\Media;

use App\Lib\Db;

/**
 * Upload log in the `uploads` table (port of src/media/upload-log.ts): lets the recent_uploads tool return the uploadId
 * without retyping it. One account = one shared log.
 *
 * Row mapping: kind='image'  -> path_or_url = "<sha>.<ext>" (file in DATA_PATH/uploads), sha256, mime, width, height;
 *                    kind='youtube'-> path_or_url = the video URL.
 * The rest (batch, name, PMax type description, title, channel, duration) goes in the meta_json column.
 * Entries are returned as arrays in the Node shape: image = {ts,batch,name,uploadId,width,height,kind},
 * video = {ts,batch,type:'youtube',youtubeId,title,channel?,durationSec?}.
 */
final class UploadLog
{
    public const YT_ID_RE = '/^[A-Za-z0-9_-]{11}$/';

    /** File name without control characters, up to 120 characters. */
    public static function cleanName(string $s): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1f\x7f-\x9f]/u', '', $s), 0, 120);
    }

    private static function insert(string $kind, string $pathOrUrl, ?string $sha, ?string $mime, ?int $w, ?int $h, array $meta, ?string $clientId): void
    {
        Db::exec(
            'INSERT INTO uploads (kind, path_or_url, sha256, mime, width, height, created_at, meta_json, client_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$kind, $pathOrUrl, $sha, $mime, $w, $h, now_iso(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $clientId]
        );
    }

    /** @param array{ext:string,mime:string,width:int,height:int} $info */
    public static function addImage(string $batch, string $name, string $sha, array $info, string $kind, ?string $clientId = null): void
    {
        self::prune();
        self::insert('image', $sha . '.' . $info['ext'], $sha, $info['mime'], $info['width'], $info['height'],
            ['batch' => $batch, 'name' => self::cleanName($name), 'kind' => $kind], $clientId);
    }

    public static function addYoutube(string $batch, string $id, string $title, ?string $channel, ?int $durationSec, ?string $clientId = null): void
    {
        self::prune();
        $meta = ['batch' => $batch, 'youtubeId' => $id, 'title' => self::cleanName($title)];
        if ($channel !== null && $channel !== '') {
            $meta['channel'] = self::cleanName($channel);
        }
        if ($durationSec !== null) {
            $meta['durationSec'] = $durationSec;
        }
        self::insert('youtube', "https://www.youtube.com/watch?v=$id", null, null, null, null, $meta, $clientId);
    }

    /** Entries older than the TTL (like the files) are deleted on every write. */
    private static function prune(): void
    {
        Db::exec('DELETE FROM uploads WHERE created_at < ?', [now_iso(-Blobs::ttlHours() * 3600)]);
    }

    /**
     * Entries from the last min(hours, ttl) hours: images whose file still exists, videos unconditionally.
     * Dedup separately: images by uploadId, videos by youtubeId (the newest stays). Oldest first.
     * $clientId = the viewer's MCP client: it sees its own entries and legacy ones (client_id NULL); null = everything (admin).
     * @return list<array<string,mixed>>
     */
    public static function recent(int $hours, ?string $clientId = null): array
    {
        $since = now_iso(-min($hours, Blobs::ttlHours()) * 3600);
        $rows = $clientId === null
            ? Db::all('SELECT * FROM uploads WHERE created_at >= ? ORDER BY created_at ASC, id ASC', [$since])
            : Db::all('SELECT * FROM uploads WHERE created_at >= ? AND (client_id IS NULL OR client_id = ?) ORDER BY created_at ASC, id ASC', [$since, $clientId]);
        $latest = [];
        foreach ($rows as $r) {
            $e = self::toEntry($r);
            if ($e === null) {
                continue;
            }
            $yt = ($e['type'] ?? 'image') === 'youtube';
            if (!$yt && !Blobs::has($e['uploadId'])) {
                continue;
            }
            $latest[($yt ? 'yt:' . $e['youtubeId'] : 'img:' . $e['uploadId'])] = $e; // the later one overwrites
        }
        $out = array_values($latest);
        usort($out, fn($a, $b) => strcmp($a['ts'], $b['ts']));
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function toEntry(array $r): ?array
    {
        $m = json_decode((string) ($r['meta_json'] ?? ''), true);
        if (!is_array($m) || !is_string($m['batch'] ?? null)) {
            return null;
        }
        if ($r['kind'] === 'youtube') {
            if (!preg_match(self::YT_ID_RE, (string) ($m['youtubeId'] ?? ''))) {
                return null;
            }
            $e = ['ts' => $r['created_at'], 'batch' => $m['batch'], 'type' => 'youtube', 'youtubeId' => $m['youtubeId'], 'title' => (string) ($m['title'] ?? '')];
            if (isset($m['channel'])) {
                $e['channel'] = $m['channel'];
            }
            if (isset($m['durationSec'])) {
                $e['durationSec'] = (int) $m['durationSec'];
            }
            return $e;
        }
        if ($r['kind'] !== 'image' || !is_string($r['sha256'])) {
            return null;
        }
        return ['ts' => $r['created_at'], 'batch' => $m['batch'], 'name' => (string) ($m['name'] ?? ''), 'uploadId' => $r['sha256'],
            'width' => (int) $r['width'], 'height' => (int) $r['height'], 'kind' => (string) ($m['kind'] ?? '')];
    }
}
