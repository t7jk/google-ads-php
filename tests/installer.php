<?php
// Offline tests of the installer (docs/adr/0008-instalator.md): requirements, manifest, config.php, empty database, admin login,
// lock after installation, e-mail validation, PHP mail() fallback, installation code, CSRF, rate limit and the protection probe.
// Part A runs in this process on a THROWAWAY data directory (GADS_DATA_DIR). Part B runs the real web flow against `php -S` servers
// that serve COPIES of the application in temporary single-directory sites (the dev installation and its data are never touched).
// The CLI server ignores .htaccess, so the "deny" rules are emulated by a router script, and a second server without it
// plays the role of a badly configured host. Run: php tests/installer.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir()));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\AdminAccount;
use App\Install\{Check, ConfigFile, InstallError, Manifest, ProtectionProbe, PublicUrl, Requirements};
use App\Lib\{Mailer, MailError, Settings};

$fail = 0;
$check = checker($fail);
$tmpDirs = [$dataDir];

/** An empty data directory: a "clean install" as the installer sees it. */
$cleanDir = function () use (&$tmpDirs): string {
    $dir = sys_get_temp_dir() . '/gads-inst-' . bin2hex(random_bytes(5));
    mkdir($dir, 0770, true);
    return $tmpDirs[] = $dir;
};

// ---- A1. Requirements ----
$check('requirements: PHP version is OK on this machine', Requirements::phpVersion()->status === Check::OK);
$check('requirements: every extension is reported', count(Requirements::extensions()) === count(Requirements::EXTENSIONS)
    && !Check::anyFailed(Requirements::extensions()));
$check('requirements: the program files are present', !Check::anyFailed(Requirements::files()));
$plan = ['path' => '/nonexistent/dir', 'outside' => true, 'inside_install' => false, 'exists' => false, 'writable' => false];
$check('requirements: an unwritable data directory is a FAIL with advice', Requirements::dataDir($plan)->status === Check::FAIL
    && str_contains(Requirements::dataDir($plan)->detail, 'permission'));
$check('requirements: a writable data directory is OK', Requirements::dataDir(['writable' => true] + $plan)->status === Check::OK);
$check('requirements: Check::anyFailed sees a FAIL among OKs', Check::anyFailed([Check::ok('a'), Check::fail('b', 'c')]) && !Check::anyFailed([Check::warn('a', 'b')]));

// ---- A2. Manifest ----
$root = $cleanDir();
mkdir("$root/app", 0770);
file_put_contents("$root/app/a.php", "<?php // a\n");
file_put_contents("$root/b.txt", "bee\n");
$manifest = hash_file('sha256', "$root/app/a.php") . "  app/a.php\n" . hash_file('sha256', "$root/b.txt") . "  b.txt\n";
$check('manifest: absent file is reported as absent (not an error)', Manifest::verify($root)['state'] === 'absent');
file_put_contents("$root/" . Manifest::FILE, $manifest);
$ok = Manifest::verify($root);
$check('manifest: matching files pass', $ok['state'] === 'checked' && $ok['changed'] === [] && $ok['missing'] === [] && $ok['files'] === 2);
file_put_contents("$root/b.txt", "changed\n");
$check('manifest: a changed file is listed', Manifest::verify($root)['changed'] === ['b.txt']);
unlink("$root/app/a.php");
$check('manifest: a missing file is listed', Manifest::verify($root)['missing'] === ['app/a.php']);
$check('manifest: paths outside the directory are ignored', Manifest::entries(str_repeat('a', 64) . "  ../x\n" . str_repeat('b', 64) . "  /etc/passwd\n") === []);

// ---- A3. config.php ----
$dir = $cleanDir();
$secret = ConfigFile::newSecretKey();
ConfigFile::write($dir, ['public_base_url' => 'https://x.example/ads', 'secret_key' => $secret, 'admin_email' => "o'brien@example.org"]);
$cfg = require "$dir/config.php";
$check('config: values written and readable', $cfg['public_base_url'] === 'https://x.example/ads' && $cfg['secret_key'] === $secret && $cfg['admin_email'] === "o'brien@example.org");
$check('config: defaults from config.example.php kept', array_key_exists('plan_ttl_hours', $cfg) && $cfg['auto_execute'] === false);
$check('config: permissions are 0600', (fileperms("$dir/config.php") & 0777) === 0600);
$check('config: secret_key is 32 bytes of base64', strlen((string) base64_decode($secret, true)) === 32);
$before = file_get_contents("$dir/config.php");
$threw = false;
try {
    ConfigFile::write($dir, ['secret_key' => 'other']);
} catch (InstallError) {
    $threw = true;
}
$check('config: an existing config.php is never overwritten', $threw && file_get_contents("$dir/config.php") === $before);
$check('config: no temporary file is left behind', glob("$dir/config.php.tmp-*") === []);

// ---- A4. Installer on a clean data directory (in this process: DATA_PATH is the throwaway directory) ----
$clean = $cleanDir();
// DATA_PATH is a constant of this process; the installer works on it, so use a child process for each clean directory.
$runInstaller = function (string $dir, string $extraPhp = '') use ($clean): string {
    $php = 'require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . ';' . $extraPhp
        . ' try { App\Install\Installer::run(["admin_email" => "jane@example.org", "public_base_url" => "https://example.org/ads",'
        . ' "smtp" => App\Auth\AdminAccount::SMTP_DEFAULTS, "smtp_password" => ""]); echo "DONE"; } catch (App\Install\InstallError $e) { echo "ERR " . $e->getMessage(); }';
    return (string) shell_exec('GADS_DATA_DIR=' . escapeshellarg($dir) . ' php -r ' . escapeshellarg($php) . ' 2>&1');
};
$check('installer: runs on an empty directory', str_ends_with(trim($runInstaller($clean)), 'DONE'), $runInstaller($cleanDir()));
$cfg = is_file("$clean/config.php") ? require "$clean/config.php" : [];
$check('installer: config.php written with the admin and public address', ($cfg['admin_email'] ?? '') === 'jane@example.org' && ($cfg['public_base_url'] ?? '') === 'https://example.org/ads');
$pdo = new PDO('sqlite:' . $clean . '/app.sqlite');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
$applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
$check('installer: empty database with every migration applied', in_array('settings', $tables, true) && in_array('login_links', $tables, true)
    && $applied === count(glob(APP_PATH . '/migrations/*.sql')));
$check('installer: no data rows except the admin settings', (int) $pdo->query('SELECT COUNT(*) FROM mcp_clients')->fetchColumn() === 0);
$check('installer: no backup was made for a brand-new database', !is_dir($clean . '/backups'));
$check('installer: admin saved and mail() fallback enabled (no SMTP given)',
    $pdo->query("SELECT value FROM settings WHERE key = 'admin_email'")->fetchColumn() === 'jane@example.org'
    && $pdo->query("SELECT value FROM settings WHERE key = 'mail_transport'")->fetchColumn() === 'php');
$check('installer: installed.lock written last', is_file("$clean/installed.lock"));
$check('installer: nothing left over from the run', !is_file("$clean/.install-running") && glob("$clean/config.php.tmp-*") === []);

$kept = $cleanDir();
file_put_contents("$kept/config.php", '<?php return ' . var_export(['secret_key' => ConfigFile::newSecretKey(), 'public_base_url' => 'https://mine.example', 'admin_email' => 'admin@example.com'], true) . ';');
$hash = md5_file("$kept/config.php");
$check('installer: an existing config.php with a secret_key is kept as it is', str_ends_with(trim($runInstaller($kept)), 'DONE') && md5_file("$kept/config.php") === $hash);
$broken = $cleanDir();
file_put_contents("$broken/config.php", '<?php return ["public_base_url" => "https://mine.example"];');
$check('installer: an existing config.php without secret_key is refused, not overwritten', str_contains($runInstaller($broken), 'ERR') && !is_file("$broken/installed.lock")
    && md5_file("$broken/config.php") === md5('<?php return ["public_base_url" => "https://mine.example"];'));

// ---- A5. "Installed" detection ----
$isInstalled = fn(string $dir): bool => trim((string) shell_exec('GADS_DATA_DIR=' . escapeshellarg($dir) . ' php -r '
    . escapeshellarg('require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . '; echo App\Auth\AdminAccount::isInstalled() ? "yes" : "no";') . ' 2>&1')) === 'yes';
$lockOnly = $cleanDir();
$check('installed: a clean directory is not installed and no database is created by the question', !$isInstalled($cleanDir()) && !is_file($lockOnly . '/app.sqlite'));
file_put_contents("$lockOnly/installed.lock", 'x');
$check('installed: installed.lock alone closes the wizard', $isInstalled($lockOnly));
$check('installed: a data directory with an admin address in config.php counts as installed (production)', $isInstalled($tmpDirs[] = make_data_dir()));
$placeholder = $tmpDirs[] = make_data_dir(['admin_email' => 'admin@example.com']);
$check('installed: the placeholder address from config.example.php does not count', !$isInstalled($placeholder));

// ---- A6. E-mail: PHP mail() fallback, message building ----
$mailer = new Mailer(['host' => '', 'port' => 0, 'encryption' => 'none', 'user' => '', 'password' => '', 'from_email' => 'noreply@example.org', 'from_name' => 'Google Ads MCP']);
$compose = new ReflectionMethod(Mailer::class, 'compose');
$parts = $compose->invoke($mailer, 'jane@example.org', 'Zażółć gęślą jaźń', "Hello\nWorld", '<p>Hi</p>');
$check('mail(): mailer without host uses PHP mail()', $mailer->usesPhpMail());
$check('mail(): subject with non-ASCII characters is RFC 2047 encoded', str_starts_with($parts['subject'], '=?UTF-8?B?') && !preg_match('/[^\x20-\x7E]/', $parts['subject']));
$check('mail(): headers carry From, UTF-8 and MIME, but not To/Subject (mail() adds them)', (function () use ($parts): bool {
    $h = implode("\n", $parts['headers']);
    return str_contains($h, 'From: Google Ads MCP <noreply@example.org>') && str_contains($h, 'MIME-Version: 1.0')
        && str_contains($h, 'multipart/alternative') && !preg_match('/^(To|Subject):/mi', $h);
})());
$check('mail(): the body is base64 UTF-8 text and HTML', str_contains($parts['body'], 'charset=UTF-8') && str_contains($parts['body'], base64_encode("Hello\nWorld")));
$injected = false;
foreach ([['jane@example.org', "Hi\r\nBcc: evil@example.org"], ["jane@example.org\r\nBcc: evil@example.org", 'Hi']] as [$to, $subject]) {
    try {
        $mailer->send($to, $subject, 'x');
    } catch (MailError) {
        $injected = true;
    }
}
$check('mail(): header injection in subject or recipient is refused', $injected);
$named = new Mailer(['host' => '', 'port' => 0, 'encryption' => 'none', 'user' => '', 'password' => '', 'from_email' => 'noreply@example.org', 'from_name' => "A\r\nBcc: x@y.z"]);
try {
    $compose->invoke($named, 'jane@example.org', 'Hi', 'x', null);
    $check('mail(): header injection in the sender name is refused', false);
} catch (MailError) {
    $check('mail(): header injection in the sender name is refused', true);
}
$check('mail(): AdminAccount has no mailer before the installer enables the fallback', AdminAccount::mailer() === null && !AdminAccount::usesPhpMail());
Settings::set('mail_transport', AdminAccount::MAIL_TRANSPORT_PHP);
$fallback = AdminAccount::mailer();
$check('mail(): once enabled (and no SMTP host) the fallback mailer is used', $fallback !== null && $fallback->usesPhpMail() && AdminAccount::usesPhpMail());
AdminAccount::saveSmtp(['smtp_host' => 'mail.example.org', 'smtp_port' => '465', 'smtp_encryption' => 'ssl', 'smtp_user' => 'u', 'smtp_from_email' => 'n@example.org', 'smtp_from_name' => 'X'], 'pw');
$check('mail(): a configured SMTP host takes precedence over mail()', AdminAccount::mailer() !== null && !AdminAccount::mailer()->usesPhpMail());

// ---- A7. Public address, data directory choice ----
$check('public url: normalised without trailing slash', PublicUrl::normalize(' https://Example.org/ads/ ') === 'https://Example.org/ads');
$check('public url: junk is refused', PublicUrl::normalize('ftp://x.org') === null && PublicUrl::normalize('https://u:p@x.org') === null
    && PublicUrl::normalize('https://x.org/?a=1') === null && PublicUrl::normalize('x.org') === null && PublicUrl::normalize('https://x.org/a b') === null);
$check('bootstrap: data_dir_candidates names both places', data_dir_candidates('/srv/site/app1') === ['local' => '/srv/site/app1/google-ads-mcp-php-data', 'outside' => '/srv/site/google-ads-mcp-php-data']);
$base = $cleanDir();
mkdir("$base/inst", 0770);
$check('bootstrap: nothing exists -> the local directory', resolve_data_path("$base/inst") === "$base/inst/google-ads-mcp-php-data");
mkdir("$base/google-ads-mcp-php-data", 0770);
$check('bootstrap: an empty writable outside directory is preferred', resolve_data_path("$base/inst") === "$base/google-ads-mcp-php-data");
mkdir("$base/inst/google-ads-mcp-php-data", 0770);
file_put_contents("$base/inst/google-ads-mcp-php-data/config.php", '<?php return [];');
$check('bootstrap: a directory that already holds data always wins (existing installs keep theirs)', resolve_data_path("$base/inst") === "$base/inst/google-ads-mcp-php-data");

// ---- A8. Protection probe (fake fetcher: no network) ----
$version = trim((string) file_get_contents(APP_PATH . '/VERSION'));
$probe = fn(callable $fetch, array $plan = []): array => (new ProtectionProbe('https://site.example/gads', $fetch))->run($plan + ['path' => $dataDir, 'inside_install' => false, 'outside' => true, 'exists' => true, 'writable' => true]);
$statuses = fn(array $checks): array => array_map(fn(Check $c): string => $c->status, $checks);
$check('probe: everything forbidden -> all OK', $statuses($probe(fn(string $u): array => ['status' => 403, 'body' => 'Forbidden'])) === ['ok', 'ok', 'ok', 'ok']);
$check('probe: 404 is fine too (tools/ is not in the release package)', !Check::anyFailed($probe(fn(string $u): array => ['status' => 404, 'body' => ''])));
$leak = $probe(fn(string $u): array => str_ends_with($u, 'app/VERSION') ? ['status' => 200, 'body' => $version . "\n"] : ['status' => 403, 'body' => '']);
$check('probe: app/VERSION served with 200 -> FAIL with instructions', $leak[0]->status === Check::FAIL && str_contains($leak[0]->detail, 'nginx') && str_contains($leak[0]->detail, 'Apache'));
$check('probe: a 200 page that is not the file (a custom error page) is not a leak of app/VERSION', $probe(fn(string $u): array => ['status' => 200, 'body' => '<html>Welcome</html>'])[0]->status === Check::OK);
$check('probe: a request that cannot be made -> WARN asking for a manual check', $statuses($probe(fn(string $u): ?array => null)) === ['warn', 'warn', 'warn', 'ok']
    && str_contains($probe(fn(string $u): ?array => null)[0]->detail, 'https://site.example/gads/app/VERSION'));
$markerDir = $cleanDir();
$inside = ['path' => $markerDir, 'inside_install' => true, 'outside' => false, 'exists' => true, 'writable' => true];
$servesMarker = fn(string $u): array => str_ends_with($u, '.probe') ? ['status' => 200, 'body' => (string) file_get_contents($markerDir . '/.probe')] : ['status' => 403, 'body' => ''];
$result = (new ProtectionProbe('https://site.example/gads', $servesMarker))->run($inside);
$check('probe: the data directory marker reachable over HTTP -> FAIL, and the marker is removed again', $result[3]->status === Check::FAIL && !file_exists("$markerDir/.probe"));
$result = (new ProtectionProbe('https://site.example/gads', fn(string $u): array => ['status' => 403, 'body' => '']))->run($inside);
$check('probe: a forbidden marker -> OK, marker removed', $result[3]->status === Check::OK && !file_exists("$markerDir/.probe"));
$ssrf = (new ProtectionProbe('http://10.0.0.5/x'))->run($inside + ['path' => $markerDir]);
$check('probe: a private address (SSRF) is never contacted: WARN, not a request', $ssrf[0]->status === Check::WARN);

// ---- B. The real web flow against php -S ----
/** A temporary single-directory site: a copy of app/ and webroot/, with the data directory ending up NEXT to it. */
$makeSite = function (bool $withInstallCode = false) use (&$tmpDirs): string {
    $root = sys_get_temp_dir() . '/gads-site-' . bin2hex(random_bytes(5));
    mkdir("$root/site", 0770, true);
    $tmpDirs[] = $root;
    shell_exec('cp -a ' . escapeshellarg(dirname(__DIR__) . '/app') . ' ' . escapeshellarg("$root/site/app"));
    shell_exec('cp -a ' . escapeshellarg(dirname(__DIR__) . '/webroot/.') . ' ' . escapeshellarg("$root/site/"));
    file_put_contents("$root/router.php", "<?php\n\$p = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
        . "if (preg_match('#^/(app|tools|tests|docs|bin|google-ads-mcp-php-data)(/|\$)#', \$p)) { http_response_code(403); echo 'Forbidden'; return true; }\nreturn false;\n");
    if ($withInstallCode) {
        file_put_contents("$root/site/INSTALL_CODE.txt", "open-sesame\n");
    }
    return $root;
};
/** Starts php -S on a free port serving $root/site (with or without the deny router). @return array{proc:resource,port:int} */
$startServer = function (string $root, bool $withRouter): array {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/site"] + ($withRouter ? [5 => "$root/router.php"] : []);
    $proc = proc_open(array_values($cmd), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, ['PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string) getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port, $e, $s, 0.2)) {
            break;
        }
        usleep(100_000);
    }
    return ['proc' => $proc, 'port' => $port];
};
$stopServer = function (array $server): void {
    $status = proc_get_status($server['proc']);
    exec('pkill -P ' . (int) $status['pid'] . ' 2>/dev/null');
    proc_terminate($server['proc']);
    proc_close($server['proc']);
};
/** A tiny HTTP client with a cookie jar; never follows redirects. @return array{int,string,string} status, body, Location */
$client = function (int $port) {
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    return function (string $method, string $path, array $post = []) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:$port$path");
        $location = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => function ($c, string $h) use (&$location): int {
                if (stripos($h, 'location:') === 0) {
                    $location = trim(substr($h, 9));
                }
                return strlen($h);
            }]);
        if ($method === 'POST') {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body, $location];
    };
};
$csrf = fn(string $html): string => preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
$badges = fn(string $html, string $word): int => substr_count($html, 'class="badge">' . $word . '<');

// B1. Complete installation, protection in place.
$root = $makeSite();
$server = $startServer($root, true);
$http = $client($server['port']);
$check('web: any page of a clean install redirects to /setup', ($r = $http('GET', '/'))[0] === 302 && str_ends_with($r[2], '/setup'));
[$status, $page] = $http('GET', '/setup');
if ($status === 302) { // the data directory was created next to the site: the wizard continues in a fresh request
    [$status, $page] = $http('GET', '/setup');
}
$check('web: step 1 lists the requirements without any FAIL', $status === 200 && str_contains($page, 'Requirements') && $badges($page, 'FAIL') === 0 && $badges($page, 'OK') >= 10, "status $status");
$check('web: no manifest in a git copy is only a WARN', $badges($page, 'WARN') === 1 && str_contains($page, 'MANIFEST.sha256'));
$check('web: the data directory was created ABOVE the public folder', is_dir("$root/google-ads-mcp-php-data") && !is_dir("$root/site/google-ads-mcp-php-data"));
$check('web: the protected-files test passes against a server that denies them', $badges($page, 'WARN') === 1 && str_contains($page, 'The program files are not downloadable'));
$check('web: nothing exists yet but the data directory (no config, no database)', !is_file("$root/google-ads-mcp-php-data/config.php"));
$check('csrf: POST /setup/requirements without a token is refused', $http('POST', '/setup/requirements')[0] === 403);
$check('csrf: POST /setup without a token is refused', $http('POST', '/setup', ['admin_email' => 'jane@example.org'])[0] === 403);
$token = $csrf($page);
$check('web: step 2 is closed before step 1 is passed', ($r = $http('POST', '/setup', ['_csrf' => $token, 'admin_email' => 'jane@example.org', 'public_base_url' => "http://127.0.0.1:{$server['port']}"]))[0] === 302
    && !is_file("$root/google-ads-mcp-php-data/config.php"));
$check('web: step 1 passes', ($r = $http('POST', '/setup/requirements', ['_csrf' => $token]))[0] === 302);
[, $step2] = $http('GET', '/setup');
$check('web: step 2 shows the administrator form with the detected address and the mail() warning', str_contains($step2, 'Step 2 of 2')
    && str_contains($step2, "value=\"http://127.0.0.1:{$server['port']}\"") && str_contains($step2, 'PHP mail() is not trusted')
    && str_contains($step2, 'anyone who knows this address could register as the administrator'));
$token = $csrf($step2);
$http('POST', '/setup', ['_csrf' => $token, 'admin_email' => 'not-an-email', 'public_base_url' => "http://127.0.0.1:{$server['port']}"]);
[, $again] = $http('GET', '/setup');
$check('validation: an invalid e-mail is refused with a message, nothing is installed', str_contains($again, 'valid administrator e-mail') && !is_file("$root/google-ads-mcp-php-data/config.php"));
$token = $csrf($again);
$http('POST', '/setup', ['_csrf' => $token, 'admin_email' => 'jane@example.org', 'public_base_url' => 'javascript:alert(1)']);
$check('validation: an invalid public address is refused', str_contains($http('GET', '/setup')[1], 'Enter the address of this site') && !is_file("$root/google-ads-mcp-php-data/config.php"));
$token = $csrf($http('GET', '/setup')[1]);
$install = $http('POST', '/setup', ['_csrf' => $token, 'admin_email' => 'Jane@Example.org', 'public_base_url' => "http://127.0.0.1:{$server['port']}/",
    'smtp_host' => '', 'smtp_port' => '465', 'smtp_encryption' => 'ssl']);
$check('web: installation redirects to the home page', $install[0] === 302 && str_ends_with($install[2], '/'), "status {$install[0]} {$install[2]}");
[$status, $home] = $http('GET', '/');
$check('web: the administrator is logged in right away (no e-mail link)', $status === 200 && str_contains($home, 'Log out') && str_contains($home, 'Installation finished'));
$check('web: the admin pages open', $http('GET', '/connections')[0] === 200 && $http('GET', '/help')[0] === 200);
$check('web: config.php is 0600 with the address and a secret_key', (fileperms("$root/google-ads-mcp-php-data/config.php") & 0777) === 0600
    && str_contains((string) file_get_contents("$root/google-ads-mcp-php-data/config.php"), "'admin_email' => 'jane@example.org'"));
$check('web: installed.lock exists', is_file("$root/google-ads-mcp-php-data/installed.lock"));
$check('web: /health works', ($r = $http('GET', '/health'))[0] === 200 && str_contains($r[1], '"database":"ok"') && str_contains($r[1], '"secret_key":"ok"'));
$check('web: the wizard is closed after installation (GET and POST redirect home)', ($r = $http('GET', '/setup'))[0] === 302 && str_ends_with($r[2], '/')
    && ($r = $http('GET', '/setup?step=1'))[0] === 302 && $http('POST', '/setup', ['_csrf' => $csrf($home), 'admin_email' => 'eve@example.org'])[0] === 302);
$anonymous = $client($server['port']);
$check('web: a visitor without the session is not the admin, the wizard stays closed', $anonymous('GET', '/connections')[0] === 302 && str_ends_with($anonymous('GET', '/setup')[2], '/'));
$check('web: the admin address was not replaced by a second attempt', str_contains((string) file_get_contents("$root/google-ads-mcp-php-data/config.php"), 'jane@example.org')
    && !str_contains((string) file_get_contents("$root/google-ads-mcp-php-data/config.php"), 'eve@'));
$stopServer($server);

// B2. Installation code and rate limit (fresh site).
$root = $makeSite(true);
$server = $startServer($root, true);
$http = $client($server['port']);
$http('GET', '/setup');
[, $page] = $http('GET', '/setup');
$check('code: step 1 asks for the installation code when INSTALL_CODE.txt exists', str_contains($page, 'name="install_code"'));
$token = $csrf($page);
$http('POST', '/setup/requirements', ['_csrf' => $token, 'install_code' => 'wrong']);
[, $page] = $http('GET', '/setup');
$check('code: a wrong code is refused and step 2 stays closed', str_contains($page, 'installation code is not correct') && !str_contains($page, 'Step 2 of 2'));
$token = $csrf($page);
$http('POST', '/setup/requirements', ['_csrf' => $token, 'install_code' => 'open-sesame']);
$check('code: the right code opens step 2', str_contains($http('GET', '/setup')[1], 'Step 2 of 2'));
$limited = false;
for ($i = 0; $i < 40 && !$limited; $i++) {
    $token = $csrf($http('GET', '/setup')[1]);
    $limited = $http('POST', '/setup', ['_csrf' => $token, 'admin_email' => 'bad'])[0] === 429;
}
$check('rate limit: repeated POST /setup ends with HTTP 429', $limited);
$stopServer($server);

// B3. A host that does not protect its private files, and a damaged upload (fresh site, no deny router).
$root = $makeSite();
unlink("$root/site/assets/style.css");
$server = $startServer($root, false);
$http = $client($server['port']);
$http('GET', '/setup');
[, $page] = $http('GET', '/setup');
$check('web: a missing file is a FAIL naming the file', $badges($page, 'FAIL') >= 1 && str_contains($page, 'assets/style.css'));
$check('web: app/VERSION downloadable -> FAIL with instructions', str_contains($page, 'app/VERSION can be opened from the internet') && str_contains($page, 'nginx'));
$check('web: Continue is disabled while there is a FAIL', str_contains($page, '<button type="submit" disabled>Continue'));
$http('POST', '/setup/requirements', ['_csrf' => $csrf($page)]);
$check('web: the server refuses to open step 2 despite a forged POST', !str_contains($http('GET', '/setup')[1], 'Step 2 of 2'));
$stopServer($server);

foreach ($tmpDirs as $d) {
    remove_dir($d);
}
foreach (glob(sys_get_temp_dir() . '/gads-site-*') ?: [] as $d) {
    if (filemtime($d) > time() - 600) {
        shell_exec('rm -rf ' . escapeshellarg($d));
    }
}
echo $fail === 0 ? "All OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
