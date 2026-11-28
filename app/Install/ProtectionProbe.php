<?php
declare(strict_types=1);

namespace App\Install;

/**
 * "Can the outside world download the private files?" — answered by trying, over HTTP, from the server itself.
 *
 * It requests three things that must NOT be served: app/ (source code), tools/ (does not exist in the release package, so a 404
 * is fine; a directory listing is not) and a marker file that is created in the data directory for the test and removed after it
 * (only when the data directory lies inside the served directory). A 200 for any of them = FAIL with instructions.
 *
 * SSRF safety (the wizard is reachable by anyone while the install is open): only GET, only these fixed paths, appended to the
 * address derived from the current request; the host must resolve to this server's own address or to public addresses only
 * (never a private/loopback address of another machine), the connection is pinned to the checked IP, no redirects are followed.
 * Only the status code of three fixed files is ever reflected to the visitor, never a body.
 * Where the test cannot run (no curl, no loopback to itself) the result is a WARN asking for a manual check.
 */
final class ProtectionProbe
{
    private const TIMEOUT = 4;
    private const MAX_BODY = 65536;

    /** @param (callable(string):?array{status:int,body:string})|null $fetch replaceable in tests; null = real curl */
    public function __construct(private readonly string $baseUrl, private readonly mixed $fetch = null)
    {
    }

    /** @return list<Check> */
    public function run(array $dataPlan): array
    {
        $checks = [
            $this->checkLeak('The program files are not downloadable', 'app/VERSION', 'app/', $this->isVersionFile(...)),
            $this->checkLeak('The program code is not executable from outside', 'app/bootstrap.php', 'app/', fn(string $body): bool => true),
            $this->checkLeak('Development folders are not browsable', 'tools/', 'tools/', fn(string $body): bool => true),
        ];
        $checks[] = $this->checkDataDir($dataPlan);
        return $checks;
    }

    private function checkLeak(string $label, string $path, string $folder, callable $isLeak): Check
    {
        $response = $this->get($path);
        if ($response === null) {
            return $this->cannotTest($label, $path);
        }
        if ($response['status'] === 200 && $isLeak($response['body'])) {
            return Check::fail($label, "$path can be opened from the internet. " . $this->howToBlock($folder));
        }
        return Check::ok($label);
    }

    private function checkDataDir(array $plan): Check
    {
        $label = 'The data directory (database, settings) is not downloadable';
        $relative = DataDir::urlPath($plan);
        if ($relative === null) {
            return Check::ok($label, 'It is outside the installation directory, so the web server cannot serve it.');
        }
        $token = bin2hex(random_bytes(16));
        $marker = $plan['path'] . '/.probe';
        if (!is_dir($plan['path']) || @file_put_contents($marker, $token) === false) {
            return Check::warn($label, 'Could not create a test file in the data directory. Check the folder permissions first.');
        }
        try {
            $response = $this->get($relative . '/.probe');
        } finally {
            @unlink($marker);
        }
        if ($response === null) {
            return $this->cannotTest($label, $relative . '/.probe');
        }
        if ($response['status'] === 200 && str_contains($response['body'], $token)) {
            return Check::fail($label, 'Files in ' . $relative . '/ can be downloaded from the internet. ' . $this->howToBlock($relative . '/')
                . ' Best of all: create the folder "google-ads-mcp-php-data" ABOVE your public folder (next to it) and reload this page.');
        }
        return Check::ok($label);
    }

    private function cannotTest(string $label, string $path): Check
    {
        return Check::warn($label, 'The installer could not test this itself. Please open ' . $this->baseUrl . '/' . $path
            . ' in your browser: you must see an error ("Forbidden" or "Not found"), not file contents.');
    }

    private function howToBlock(string $folder): string
    {
        return 'Apache: make sure the .htaccess file from the package was uploaded and the server allows it (AllowOverride All, mod_rewrite). '
            . "nginx: add a rule such as \"location ~ ^/(app|tools|google-ads-mcp-php-data)/ { deny all; }\" (mind the sub-folder of this site). Folder: $folder";
    }

    private function isVersionFile(string $body): bool
    {
        $version = is_file(APP_PATH . '/VERSION') ? trim((string) file_get_contents(APP_PATH . '/VERSION')) : '';
        return $version !== '' && trim($body) === $version;
    }

    /** @return array{status:int,body:string}|null null = the request could not be made (not "forbidden"). */
    private function get(string $path): ?array
    {
        $url = $this->baseUrl . '/' . $path;
        if ($this->fetch !== null) {
            return ($this->fetch)($url);
        }
        return self::curlGet($url);
    }

    /** @return array{status:int,body:string}|null */
    private static function curlGet(string $url): ?array
    {
        $target = self::safeTarget($url);
        if ($target === null || !function_exists('curl_init')) {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            CURLOPT_USERAGENT => 'google-ads-mcp-php-installer',
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > self::MAX_BODY ? 0 : strlen($chunk); // 0 aborts the transfer: only a prefix is ever needed
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status > 0 ? ['status' => $status, 'body' => $body] : null;
    }

    /**
     * Validates the destination: http(s), standard port or this server's port, and an IP that is this server's own address
     * or a public one. @return array{host:string,port:int,ip:string}|null
     */
    private static function safeTarget(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $serverPort = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if (!in_array($port, [80, 443, $serverPort], true)) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        $own = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $localVisitor = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true); // a developer testing on their own machine
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            $ownLoopback = $localVisitor && str_starts_with($ip, '127.');
            if (!$public && $ip !== $own && !$ownLoopback) {
                return null;
            }
        }
        return $ips ? ['host' => $host, 'port' => $port, 'ip' => $ips[0]] : null;
    }
}
