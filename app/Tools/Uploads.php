<?php
// Upload tools (port of upload_link and recent_uploads from src/mcp/tools-plan.ts). The upload page: Controllers/UploadController.
declare(strict_types=1);

use App\Ads\{Params, Plural};
use App\Auth\LinkToken;
use App\Lib\Mcp;
use App\Media\{UploadLog, YoutubeChannel};

/** Calendar day YYYY-MM-DD in Warsaw for an ISO instant. */
$warsawDay = fn(string $iso): string => (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');

return [
    [
        'name' => 'upload_link',
        'title' => 'Image upload link',
        'description' => 'Read-only: returns a link to the image upload page (JPG/PNG), valid for 2 hours and requiring no sign-in. The page supports dragging multiple files and gives an uploadId for each of them. The link to the page for choosing videos from a YouTube channel (no MP4 upload) is the same address ending with /youtube (same token). After uploading or choosing videos the user can simply say they are done - then call recent_uploads (uploadId is passed to prepare_asset_group_changes and prepare_campaign_assets, video IDs to videos.add).',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        'annotations' => ['readOnlyHint' => true],
        'handler' => function (array $args): array {
            $tok = LinkToken::make(LinkToken::UPLOAD, LinkToken::UPLOAD_TTL_MS, Mcp::$clientId);
            $url = base_url() . '/upload?t=' . $tok;
            $youtubeUrl = base_url() . '/upload/youtube?t=' . $tok;
            return Mcp::text(
                "Upload images here (link valid for 2 h, no sign-in): $url\nYouTube videos are chosen here (paste the channel URL, tick the thumbnails): $youtubeUrl\nAfter uploading, just say in the chat that the images are uploaded or the videos chosen - I will read the identifiers myself (recent_uploads).",
                ['url' => $url, 'youtubeUrl' => $youtubeUrl, 'expiresInHours' => 2]
            );
        },
    ],
    [
        'name' => 'recent_uploads',
        'title' => 'Recently uploaded images and chosen videos',
        'description' => 'Read-only: the list of images uploaded on the upload_link page (uploadId, file name, dimensions, type) and of YouTube videos chosen on the video chooser page (video ID, title, duration) in the last hours, grouped into batches from the newest. Call it when the user says they uploaded images (e.g. "images uploaded", "I uploaded the images today") or chose videos ("videos chosen") - usually the newest batch is meant; when they mean today\'s, set today: true. uploadId is passed to prepare_asset_group_changes and prepare_campaign_assets; video IDs to prepare_asset_group_changes → videos.add (PMax requires videos of at least 10 s).',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 48, 'description' => 'How many of the last hours to include (1-48, default 24)'],
                'today' => ['type' => 'boolean', 'description' => 'true = only uploads since midnight Polish time (today)'],
            ],
        ],
        'annotations' => ['readOnlyHint' => true],
        'handler' => function (array $args) use ($warsawDay): array {
            $today = Params::bool($args, 'today', false);
            $h = $today ? 48 : Params::int($args, 'hours', 1, 48, 24);
            $todayStr = $warsawDay('now');
            $yesterdayStr = (new DateTimeImmutable($todayStr . ' 12:00', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            $entries = array_values(array_filter(UploadLog::recent($h, Mcp::$clientId), fn($e) => !$today || $warsawDay($e['ts']) === $todayStr));
            if (!$entries) {
                return Mcp::text('No images or videos uploaded or chosen ' . ($today ? 'today' : "in the last $h h") . '. Give the user the link from upload_link.', ['batches' => []]);
            }
            $groups = [];
            foreach ($entries as $e) {
                $groups[$e['batch']][] = $e;
            }
            $batches = [];
            foreach ($groups as $batch => $files) {
                $batches[] = ['batch' => (string) $batch, 'uploadedAt' => end($files)['ts'], 'files' => $files];
            }
            usort($batches, fn($a, $b) => strcmp($b['uploadedAt'], $a['uploadedAt']));
            $label = function (string $iso) use ($warsawDay, $todayStr, $yesterdayStr): string {
                $day = $warsawDay($iso);
                $time = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('H:i');
                return ($day === $todayStr ? 'today' : ($day === $yesterdayStr ? 'yesterday' : $day)) . " $time";
            };
            $blocks = array_map(function ($b) use ($label) {
                $lines = array_map(fn($f) => ($f['type'] ?? '') === 'youtube'
                    ? "- YouTube video: {$f['title']} - ID {$f['youtubeId']}" . (isset($f['durationSec']) ? ' (' . YoutubeChannel::formatDuration($f['durationSec']) . ')' : '')
                    : "- {$f['name']} - uploadId {$f['uploadId']} - {$f['width']}×{$f['height']}, {$f['kind']}", $b['files']);
                return 'Batch ' . $label($b['uploadedAt']) . ' (' . Plural::withCount(count($b['files']), 'file', 'files', 'files') . "):\n" . implode("\n", $lines);
            }, $batches);
            $structured = array_map(fn($b) => ['batch' => $b['batch'], 'uploadedAt' => $b['uploadedAt'], 'files' => array_map(fn($f) => ($f['type'] ?? '') === 'youtube'
                ? ['type' => 'youtube', 'youtubeId' => $f['youtubeId'], 'title' => $f['title']] + (isset($f['channel']) ? ['channel' => $f['channel']] : []) + (isset($f['durationSec']) ? ['durationSec' => $f['durationSec']] : [])
                : ['type' => 'image', 'name' => $f['name'], 'uploadId' => $f['uploadId'], 'width' => $f['width'], 'height' => $f['height'], 'kind' => $f['kind']], $b['files'])], $batches);
            return Mcp::text(implode("\n\n", $blocks), ['batches' => $structured]);
        },
    ],
];
