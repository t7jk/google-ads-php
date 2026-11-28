<?php
declare(strict_types=1);

namespace App\Install;

use App\Auth\AdminAccount;
use App\Lib\{Db, Events, Settings};

/**
 * Step 3 of the installer: turns an empty upload into a working installation. Order matters and every step can be repeated:
 *   1. data directory exists, 2. config.php (secret_key, public_base_url, admin_email) unless one is already there,
 *   3. empty database + all migrations (Lib\Db runs Lib\Migrator), 4. admin address and mail settings in the database,
 *   5. installed.lock — LAST, so a failure in 1-4 leaves the wizard open and the visitor can simply try again.
 * The caller (SetupController) logs the admin in and shows the result.
 *
 * Pitfalls:
 *  - DATA_PATH is a constant fixed at the start of the request: when DataDir chose a directory other than the current DATA_PATH,
 *    the controller must redirect first (a new request resolves DATA_PATH again). run() refuses to continue otherwise.
 *  - config() caches the file; after writing it we reload, so Crypto (secret_key) and base_url() work in this same request.
 *  - Two visitors posting at once: an exclusive flock on a lock file in the data directory lets only one of them proceed.
 */
final class Installer
{
    /**
     * @param array{admin_email:string, public_base_url:string, smtp:array<string,string>, smtp_password:string} $input already validated
     * @throws InstallError
     */
    public static function run(array $input): void
    {
        $plan = DataDir::plan();
        if ($plan['path'] !== DATA_PATH || !DataDir::ensure($plan)) {
            throw new InstallError('The data directory is not ready. Go back to the checks and run them again.');
        }
        $lock = self::acquire();
        try {
            self::writeConfig($input);
            self::createDatabase();
            self::saveAdmin($input);
            self::markInstalled();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink(DATA_PATH . '/.install-running');
        }
        Events::log('setup_done', ['ok' => true]);
    }

    /** @return resource */
    private static function acquire()
    {
        $handle = fopen(DATA_PATH . '/.install-running', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new InstallError('Another installation is running right now. Wait a moment and try again.');
        }
        return $handle;
    }

    /** Writes config.php, or accepts an existing one that has a usable secret_key (never overwritten). */
    private static function writeConfig(array $input): void
    {
        if (is_file(DATA_PATH . '/config.php')) {
            self::requireUsableSecretKey();
            return;
        }
        ConfigFile::write(DATA_PATH, [
            'public_base_url' => $input['public_base_url'],
            'secret_key' => ConfigFile::newSecretKey(),
            'admin_email' => $input['admin_email'],
            'allowed_email' => $input['admin_email'], // the Google account that may connect: by default the administrator's own
        ]);
        config_all(true);
    }

    private static function requireUsableSecretKey(): void
    {
        $key = base64_decode((string) config('secret_key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InstallError('A config.php already exists in the data directory but has no valid secret_key. '
                . 'Delete that file (if it is not yours) or set secret_key in it (base64 of 32 random bytes).');
        }
        if ((string) config('public_base_url', '') === '') {
            throw new InstallError('The existing config.php has no public_base_url. Add it (your site address) or delete the file.');
        }
    }

    /** Opening the connection creates app.sqlite and applies every migration (a brand-new database needs no backup). */
    private static function createDatabase(): void
    {
        try {
            Db::one('SELECT COUNT(*) AS n FROM settings');
        } catch (\Throwable $e) {
            throw new InstallError('The database could not be created: ' . $e->getMessage());
        }
    }

    private static function saveAdmin(array $input): void
    {
        AdminAccount::setEmail($input['admin_email']);
        if ($input['smtp']['smtp_host'] !== '') {
            AdminAccount::saveSmtp($input['smtp'], $input['smtp_password']);
            Settings::delete('mail_transport');
        } else {
            Settings::set('mail_transport', AdminAccount::MAIL_TRANSPORT_PHP);
        }
    }

    private static function markInstalled(): void
    {
        $line = 'Installed ' . now_iso() . ' (version ' . \App\Lib\Version::current() . "). Delete this file only to run the installer again.\n";
        if (file_put_contents(DATA_PATH . '/' . AdminAccount::LOCK_FILE, $line) === false) {
            throw new InstallError('Could not write installed.lock into the data directory.');
        }
    }
}
