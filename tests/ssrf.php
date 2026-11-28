<?php
// Offline tests of the SSRF guards: the URL host allow-list (Lp2\UrlHost) and the public-address check of Media\Download.
// Nothing connects anywhere: Download::get() refuses a bad URL before curl is started, and DNS is never asked for IP literals.
// Run: php tests/ssrf.php
declare(strict_types=1);
require __DIR__ . '/_env.php';

putenv('GADS_DATA_DIR=' . ($dataDir = make_data_dir(['allowed_url_hosts' => ['good.example']])));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Lp2\UrlHost;
use App\Media\Download;

$fail = 0;
$check = checker($fail);

// ---- A. Host allow-list ----
$check('host: https on the listed domain is allowed', UrlHost::isAllowed('https://good.example/a?b=1'));
$check('host: www. prefix is accepted', UrlHost::isAllowed('https://www.good.example/'));
$check('host: http is refused', !UrlHost::isAllowed('http://good.example/'));
$check('host: other domain refused', !UrlHost::isAllowed('https://evil.example/'));
$check('host: suffix trick (good.example.evil.example) refused', !UrlHost::isAllowed('https://good.example.evil.example/'));
$check('host: prefix trick (evilgood.example) refused', !UrlHost::isAllowed('https://evilgood.example/'));
$check('host: user-info trick (good.example@evil.example) refused', !UrlHost::isAllowed('https://good.example@evil.example/'));
$check('host: allowed host only in the path refused', !UrlHost::isAllowed('https://evil.example/good.example'));
$check('host: IP literal and non-http schemes refused', !UrlHost::isAllowed('https://127.0.0.1/') && !UrlHost::isAllowed('file:///etc/passwd')
    && !UrlHost::isAllowed('javascript:alert(1)') && !UrlHost::isAllowed('data:text/html,x'));
$check('host: upper-case host is normalised', UrlHost::isAllowed('https://GOOD.example/'));

// ---- B. Redirect targets (the caller resolves each Location against the current URL and checks it again) ----
$base = 'https://good.example/dir/page';
$check('redirect: a relative Location stays on the allowed host', UrlHost::isAllowed((string) UrlHost::resolve('../other', $base)));
$check('redirect: an absolute path stays on the allowed host', UrlHost::resolve('/x', $base) === 'https://good.example/x');
$check('redirect: an absolute Location to another host is refused', !UrlHost::isAllowed((string) UrlHost::resolve('https://evil.example/', $base)));
$check('redirect: a protocol-relative Location to another host is refused', !UrlHost::isAllowed((string) UrlHost::resolve('//evil.example/x', $base)));
$check('redirect: a downgrade to http is refused', !UrlHost::isAllowed((string) UrlHost::resolve('http://good.example/x', $base)));
$check('redirect: a Location to the cloud metadata address is refused', !UrlHost::isAllowed((string) UrlHost::resolve('https://169.254.169.254/latest/meta-data/', $base)));
$check('redirect: path traversal cannot leave the host', UrlHost::resolve('../../../../x', $base) === 'https://good.example/x');

// ---- C. Public-address check (private method: reached through reflection, the rule itself is what matters) ----
$isPublic = fn(string $ip): bool => (new ReflectionMethod(Download::class, 'isPublicIp'))->invoke(null, $ip);
$check('ip: a public IPv4 address passes', $isPublic('93.184.216.34'));
$check('ip: a public IPv6 address passes', $isPublic('2606:2800:220:1:248:1893:25c8:1946'));
$private = ['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '0.0.0.0', '169.254.169.254', '169.254.0.1', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1'];
foreach ($private as $ip) {
    $check("ip: $ip is refused", !$isPublic($ip));
}
$mapped = ['::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:192.168.0.1', '::ffff:169.254.169.254', '0:0:0:0:0:ffff:7f00:1'];
foreach ($mapped as $ip) {
    $check("ip: IPv4-mapped IPv6 $ip is refused", !$isPublic($ip));
}
$check('ip: IPv4-mapped IPv6 of a public address is judged by the IPv4 address', $isPublic('::ffff:93.184.216.34'));
$check('ip: garbage is refused', !$isPublic('not-an-ip') && !$isPublic('') && !$isPublic('999.1.1.1'));

// ---- D. Download::get refuses before connecting ----
$refused = function (string $url): string {
    try {
        Download::get($url, [], 1000, 1);
        return 'NOT REFUSED';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$check('download: http is refused', str_contains($refused('http://good.example/x'), 'only https'));
$check('download: loopback IP literal is refused', str_contains($refused('https://127.0.0.1/x'), 'non-public'), $refused('https://127.0.0.1/x'));
$check('download: metadata IP literal is refused', str_contains($refused('https://169.254.169.254/latest/'), 'non-public'));
$check('download: bracketed IPv6 loopback is refused', str_contains($refused('https://[::1]/x'), 'non-public'));
$check('download: bracketed IPv4-mapped IPv6 is refused', str_contains($refused('https://[::ffff:127.0.0.1]/x'), 'non-public'));
$check('download: a URL without a host is refused', str_contains($refused('https:///x'), 'only https') || str_contains($refused('https:///x'), 'resolve'));

remove_dir($dataDir);
echo $fail === 0 ? "All OK\n" : "FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
