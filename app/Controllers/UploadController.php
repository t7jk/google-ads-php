<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\LinkToken;
use App\Lib\Events;
use App\Media\{Blobs, ImageInfo, Ratio, UploadLog, YoutubeChannel};

/**
 * Upload tab: uploading JPG/PNG images and picking YouTube videos (port of src/http/upload-page.ts and youtube-page.ts).
 * Access: a logged-in admin OR a valid token from upload_link (`?t=`, 2 h; it also says which MCP client asked for the link). The token is carried in the URL (`$this->q`), also in form actions.
 * POST results are shown after a redirect (PRG) via $_SESSION['upload_result'] — refreshing the page does not upload the files again.
 * Image files live outside the web root (DATA_PATH/uploads), so thumbnails are served by the /upload/thumb route.
 * CSRF is checked by the Router (a session token, also for users who are not logged in).
 */
final class UploadController
{
    public const MAX_FILES = 10;
    public const MAX_FILE_BYTES = 5_242_880;
    private const MAX_SELECTED = 30;
    private const LIST_PER_MIN = 20;
    private const LISTING_TTL = 600;
    private const RECENT_HOURS = 24;
    private const EXPIRED = 'The link has expired or is invalid — ask Claude for a new one (the upload_link tool).';
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'The file is too large (limit 5 MB)', UPLOAD_ERR_FORM_SIZE => 'The file is too large (limit 5 MB)',
        UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded — try again', UPLOAD_ERR_NO_FILE => 'No file selected',
    ];

    /** URL fragment with the token ("?t=…") or an empty string (admin). Set by authorize(). */
    private string $q = '';

    /** MCP client the link was issued to (from the token); uploads are attributed to it. Null for the admin and for legacy tokens. */
    private ?string $clientId = null;

    /** Lets in the admin or the owner of a valid token; otherwise redirects to login / a 403 error. */
    private function authorize(): void
    {
        $t = (string) ($_GET['t'] ?? '');
        if ($t !== '') {
            if (LinkToken::verify(LinkToken::UPLOAD, $t)) {
                $this->q = '?t=' . rawurlencode($t);
                $this->clientId = LinkToken::context(LinkToken::UPLOAD, $t);
                return;
            }
            if (!is_admin()) {
                view('error', ['title' => 'Upload', 'message' => self::EXPIRED], 403);
            }
        }
        require_admin();
    }

    public function index(): void
    {
        $this->authorize();
        $result = $_SESSION['upload_result'] ?? null;
        unset($_SESSION['upload_result']);
        view('upload', [
            'title' => 'Upload', 'loggedIn' => is_admin(), 'q' => $this->q, 'result' => $result, 'recent' => array_reverse(UploadLog::recent(self::RECENT_HOURS, $this->clientId)),
            'maxFiles' => self::MAX_FILES, 'maxMb' => self::MAX_FILE_BYTES / 1048576, 'ttlHours' => Blobs::ttlHours(),
        ]);
    }

    /** POST /upload: up to 10 JPG/PNG files, each ≤ 5 MB. The type is determined by CONTENT (getimagesize), not by name or Content-Type. */
    public function store(): void
    {
        $this->authorize();
        $files = $this->uploadedFiles();
        if (!$files) {
            flash('error', 'No file selected (or the request exceeded the server size limit — upload fewer files at once).');
            redirect('/upload' . $this->q);
        }
        if (count($files) > self::MAX_FILES) {
            flash('error', 'Too many files — at most ' . self::MAX_FILES . ' at once.');
            redirect('/upload' . $this->q);
        }
        $batch = bin2hex(random_bytes(12));
        $rows = array_map(fn(array $f) => $this->storeOne($batch, $f), $files);
        Events::log('web', ['event' => 'upload', 'batch' => $batch, 'files' => count($files), 'accepted' => count(array_filter($rows, fn($r) => !isset($r['error']))), 'ok' => true]);
        $_SESSION['upload_result'] = ['type' => 'images', 'rows' => $rows];
        redirect('/upload' . $this->q);
    }

    /** @return array{name:string,dims:string,kind:string,uploadId:string}|array{name:string,error:string} */
    private function storeOne(string $batch, array $f): array
    {
        $name = UploadLog::cleanName((string) $f['name']);
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return ['name' => $name, 'error' => self::UPLOAD_ERRORS[$f['error']] ?? 'File upload error'];
        }
        if ($f['size'] > self::MAX_FILE_BYTES) {
            return ['name' => $name, 'error' => 'The file is too large (limit 5 MB)'];
        }
        $bytes = (string) file_get_contents($f['tmp_name']);
        $info = ImageInfo::read($bytes);
        if (isset($info['error'])) {
            return ['name' => $name, 'error' => $info['error']];
        }
        $kind = Ratio::describe($info['width'], $info['height']);
        $sha = Blobs::put($bytes, $info['ext']);
        UploadLog::addImage($batch, $name, $sha, $info, $kind, $this->clientId);
        return ['name' => $name, 'dims' => "{$info['width']}×{$info['height']}", 'kind' => $kind, 'uploadId' => $sha];
    }

    /** @return list<array{name:string,tmp_name:string,size:int,error:int}> the `files[]` form fields reduced to a list of files */
    private function uploadedFiles(): array
    {
        $in = $_FILES['files'] ?? null;
        if (!is_array($in) || !is_array($in['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($in['name'] as $i => $name) {
            if (($in['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) $in['tmp_name'][$i], 'size' => (int) $in['size'][$i], 'error' => (int) $in['error'][$i]];
        }
        return $out;
    }

    /** GET /upload/thumb?id=<sha256>: miniatura obrazu z magazynu (outside the web root). */
    public function thumb(): void
    {
        $this->authorize();
        $path = Blobs::path((string) ($_GET['id'] ?? ''));
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        $png = str_ends_with($path, '.png');
        header('Content-Type: ' . ($png ? 'image/png' : 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($path)) : false;
        if ($img === false) {
            readfile($path);
            exit;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, 240 / max($w, 1));
        $small = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        $png ? imagepng($small) : imagejpeg($small, null, 80);
        exit;
    }

    // ---------- YouTube ----------

    /** POST /upload/youtube/list: recognizes the pasted channel / links and shows a grid of videos to select. */
    public function youtubeList(): void
    {
        $this->authorize();
        $calls = array_filter($_SESSION['yt_calls'] ?? [], fn($t) => $t > time() - 60);
        if (count($calls) >= self::LIST_PER_MIN) {
            flash('error', 'Too many requests — try again in a minute.');
            redirect('/upload' . $this->q);
        }
        $_SESSION['yt_calls'] = [...$calls, time()];
        $input = mb_substr((string) ($_POST['input'] ?? ''), 0, YoutubeChannel::MAX_INPUT_CHARS);
        if (trim($input) === '') {
            flash('error', 'Paste a channel URL or video links.');
            redirect('/upload' . $this->q);
        }
        $r = YoutubeChannel::listing($input);
        $_SESSION['yt_listing'] = ['at' => time(), 'videos' => array_column($r['videos'], null, 'id')];
        Events::log('web', ['event' => 'youtube_list', 'videos' => count($r['videos']), 'problems' => count($r['problems']), 'ok' => true]);
        view('upload_youtube', ['title' => 'YouTube videos', 'loggedIn' => is_admin(), 'q' => $this->q, 'input' => $input, 'r' => $r,
            'minSec' => YoutubeChannel::MIN_PMAX_VIDEO_SEC, 'maxSelected' => self::MAX_SELECTED]);
    }

    /** POST /upload/youtube/select: saves the selected videos in the log (for recent_uploads). We trust only the IDs; titles come from the session list or oEmbed. */
    public function youtubeSelect(): void
    {
        $this->authorize();
        $ids = array_values(array_unique(array_map('strval', (array) ($_POST['v'] ?? []))));
        if (!$ids) {
            flash('error', 'No video selected.');
            redirect('/upload' . $this->q);
        }
        if (count($ids) > self::MAX_SELECTED) {
            flash('error', 'Too many videos — at most ' . self::MAX_SELECTED . ' at once.');
            redirect('/upload' . $this->q);
        }
        foreach ($ids as $id) {
            if (!preg_match(UploadLog::YT_ID_RE, $id)) {
                flash('error', 'Invalid video identifier.');
                redirect('/upload' . $this->q);
            }
        }
        $listing = $_SESSION['yt_listing'] ?? null;
        $cached = ($listing && $listing['at'] > time() - self::LISTING_TTL) ? $listing['videos'] : [];
        $batch = bin2hex(random_bytes(12));
        $rows = [];
        foreach ($ids as $id) {
            $v = (isset($cached[$id]) && empty($cached[$id]['unavailable'])) ? $cached[$id] : YoutubeChannel::oembed($id);
            $title = trim(UploadLog::cleanName((string) ($v['title'] ?? ''))) ?: '(untitled)';
            UploadLog::addYoutube($batch, $id, $title, $v['channel'] ?? null, $v['durationSec'] ?? null, $this->clientId);
            $rows[] = ['title' => $title, 'youtubeId' => $id, 'durationSec' => $v['durationSec'] ?? null, 'unavailable' => !empty($v['unavailable'])];
        }
        Events::log('web', ['event' => 'youtube_select', 'batch' => $batch, 'videos' => count($rows), 'ok' => true]);
        $_SESSION['upload_result'] = ['type' => 'videos', 'rows' => $rows];
        redirect('/upload' . $this->q);
    }
}
