<?php
declare(strict_types=1);

namespace App\Install;

/**
 * Step 1 of the installer: is this server able to run the application, and are the uploaded files complete?
 * Every method returns plain-language Check lines; FAIL tells the visitor what to do. No network access here (see ProtectionProbe).
 * Paths are shown to the visitor, which is acceptable only because the wizard exists solely until the installation is finished.
 */
final class Requirements
{
    public const MIN_PHP = '8.4.0';
    /** extension => what it is used for (shown when missing) */
    public const EXTENSIONS = [
        'sodium' => 'encrypts stored secrets',
        'pdo_sqlite' => 'the SQLite database',
        'curl' => 'talks to Google',
        'mbstring' => 'text handling',
        'openssl' => 'secure connections (HTTPS, SMTP)',
        'ctype' => 'input checks',
        'json' => 'data exchange',
    ];
    /** Files and directories the application cannot start without (relative to the installation or the public directory). */
    private const REQUIRED_APP = ['bootstrap.php', 'routes.php', 'helpers.php', 'config.example.php', 'VERSION', 'migrations'];
    /** Files the host or the user may legitimately edit: a changed hash is a warning, not an error. */
    private const EDITABLE = ['.htaccess', '.user.ini'];

    /** @return list<Check> */
    public static function all(array $dataPlan): array
    {
        return [
            self::phpVersion(),
            ...self::extensions(),
            ...self::files(),
            self::manifest(),
            self::dataDir($dataPlan),
        ];
    }

    public static function phpVersion(): Check
    {
        $label = 'PHP version ' . self::MIN_PHP . ' or newer';
        return version_compare(PHP_VERSION, self::MIN_PHP, '>=')
            ? Check::ok($label, 'This server runs PHP ' . PHP_VERSION . '.')
            : Check::fail($label, 'This server runs PHP ' . PHP_VERSION . '. In your hosting panel choose PHP 8.4 (often called "Select PHP version" or "MultiPHP Manager").');
    }

    /** @return list<Check> */
    public static function extensions(): array
    {
        $checks = [];
        foreach (self::EXTENSIONS as $name => $purpose) {
            $label = "PHP extension \"$name\" ($purpose)";
            $checks[] = extension_loaded($name)
                ? Check::ok($label)
                : Check::fail($label, "Missing. Enable \"$name\" in your hosting panel's PHP extensions page, or ask your hosting support.");
        }
        return $checks;
    }

    /** @return list<Check> */
    public static function files(): array
    {
        $checks = [];
        $missing = [];
        foreach (self::REQUIRED_APP as $name) {
            if (!file_exists(APP_PATH . '/' . $name)) {
                $missing[] = 'app/' . $name;
            }
        }
        $missing = [...$missing, ...self::missingPublic()];
        if (is_dir(APP_PATH . '/migrations') && (glob(APP_PATH . '/migrations/*.sql') ?: []) === []) {
            $missing[] = 'app/migrations/*.sql';
        }
        $label = 'All program files and folders are present';
        $checks[] = $missing === []
            ? Check::ok($label)
            : Check::fail($label, 'Missing: ' . implode(', ', $missing) . '. Upload the whole package again (including hidden files such as .htaccess).');
        return $checks;
    }

    /** @return list<string> */
    private static function missingPublic(): array
    {
        $missing = [];
        foreach (['assets/style.css', '.htaccess'] as $name) {
            if (!is_file(PUBLIC_PATH . '/' . $name)) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    public static function manifest(): Check
    {
        $label = 'The uploaded files are complete and unchanged';
        $result = Manifest::verify(PRIVATE_PATH);
        if ($result['state'] === 'absent') {
            return Check::warn($label, 'No ' . Manifest::FILE . ' found (installed from git or an unknown source), so the files could not be verified.');
        }
        $bad = [...$result['missing'], ...array_diff($result['changed'], self::EDITABLE)];
        if ($bad !== []) {
            $shown = implode(', ', array_slice($bad, 0, 15)) . (count($bad) > 15 ? ', … (' . count($bad) . ' in total)' : '');
            return Check::fail($label, 'Damaged or missing: ' . $shown . '. Upload the package again; with FTP use binary mode (not "ASCII"/"auto").');
        }
        $edited = array_intersect($result['changed'], self::EDITABLE);
        if ($edited !== []) {
            return Check::warn($label, 'All files match except ' . implode(', ', $edited) . ' (changed by you or your host). That is fine if you know why.');
        }
        return Check::ok($label, $result['files'] . ' files checked.');
    }

    public static function dataDir(array $plan): Check
    {
        $label = 'A private folder for the data can be created and written';
        $where = $plan['path'] . ($plan['outside'] ? ' (above the public folder: safest)' : ' (inside the installation folder, protected by .htaccess)');
        if (!$plan['writable']) {
            return Check::fail($label, "Cannot write to $where. Create the folder yourself and give it write permission (chmod 775 or 755, depending on your host).");
        }
        return Check::ok($label, $where);
    }
}
