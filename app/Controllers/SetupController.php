<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AdminAccount;
use App\Install\{Check, DataDir, InstallCode, InstallError, Installer, ProtectionProbe, PublicUrl, Requirements};
use App\Lib\{Mailer, MailError, RateLimiter};

/**
 * The installer (docs/adr/0008-instalator.md): a clean install sends every visitor here, no login needed.
 *   Step 1 "Requirements" (GET /setup, POST /setup/requirements): PHP, extensions, files vs MANIFEST.sha256, data directory,
 *          and a test that private files cannot be downloaded. Any FAIL blocks the next step.
 *   Step 2 "Administrator" (GET /setup after step 1, POST /setup): admin e-mail, public address, optional SMTP.
 *   Step 3 runs inside POST /setup (Install\Installer): config.php, empty database, admin saved, installed.lock, admin logged in.
 *
 * Assumptions and pitfalls:
 * - There is no e-mail verification at install time: whoever finishes the wizard first becomes the administrator. That is the known
 *   risk of every web installer; finish it right after uploading, or put an INSTALL_CODE.txt next to index.php (Install\InstallCode).
 * - After installation every route here redirects to / (guardNotInstalled), so server paths shown in step 1 are visible only
 *   while the installation is open.
 * - Controller stays thin: checks live in Install\Requirements/ProtectionProbe, the work in Install\Installer.
 */
final class SetupController
{
    private const SESSION_STEP2 = 'setup_requirements_ok';
    private const RATE_LIMIT = 30;     // POSTs per IP and window: the install code and the form must not be guessable in bulk
    private const RATE_WINDOW = 600;

    /** GET /setup: step 1, or step 2 once step 1 passed in this session (/setup?step=1 goes back to the checks). */
    public function index(): void
    {
        $this->guardNotInstalled();
        $plan = DataDir::plan();
        $ready = $plan['writable'] && DataDir::ensure($plan);
        if ($ready && !$plan['exists'] && $plan['path'] !== DATA_PATH) {
            redirect('/setup'); // we just created the data directory somewhere else: continue in a fresh request that resolves DATA_PATH to it
        }
        if (($_GET['step'] ?? '') === '1') {
            unset($_SESSION[self::SESSION_STEP2]);
        }
        if (!empty($_SESSION[self::SESSION_STEP2])) {
            $this->adminStep();
        }
        $this->requirementsStep($plan);
    }

    /** POST /setup/requirements: re-runs the checks on the server (never trusts the page) and opens step 2. */
    public function continueToAdmin(): void
    {
        $this->guardNotInstalled();
        $this->rateLimit();
        $plan = DataDir::plan();
        if (Check::anyFailed($this->allChecks($plan))) {
            $this->back(['Fix the problems marked FAIL first, then check again.']);
        }
        if (!InstallCode::accepts(post('install_code'))) {
            $this->back(['The installation code is not correct. It is in the file INSTALL_CODE.txt that you uploaded.']);
        }
        $_SESSION[self::SESSION_STEP2] = true;
        redirect('/setup');
    }

    /** POST /setup: validates step 2, tests SMTP (when given), runs the installer and logs the administrator in. */
    public function store(): void
    {
        $this->guardNotInstalled();
        $this->rateLimit();
        if (empty($_SESSION[self::SESSION_STEP2])) {
            redirect('/setup');
        }
        $input = $this->readForm();
        $errors = $this->validate($input);
        if (!$errors && $input['smtp']['smtp_host'] !== '') {
            $errors = $this->testSmtp($input);
        }
        if ($errors) {
            $this->backToAdmin($errors, $input);
        }
        try {
            Installer::run($input);
        } catch (InstallError $e) {
            $this->backToAdmin([$e->getMessage()], $input);
        }
        unset($_SESSION[self::SESSION_STEP2], $_SESSION['setup_old']);
        login_admin();
        $note = PublicUrl::isHttps($input['public_base_url']) ? '' : ' Note: your address starts with http://, not https://; switch to https as soon as you can.';
        flash('ok', 'Installation finished and you are logged in. Next: Connections, then connect your Google account and add an MCP client (claude.ai or Claude Code); the Help tab explains each step.' . $note);
        redirect('/');
    }

    private function requirementsStep(array $plan): never
    {
        $base = PublicUrl::detect();
        view('setup_requirements', [
            'title' => 'Installation: requirements',
            'checks' => Requirements::all($plan),
            'protection' => (new ProtectionProbe($base))->run($plan),
            'probedUrl' => $base,
            'needsCode' => InstallCode::isRequired(),
        ]);
    }

    private function adminStep(): never
    {
        $old = $_SESSION['setup_old'] ?? [];
        unset($_SESSION['setup_old']);
        view('setup', [
            'title' => 'Installation: administrator',
            'adminEmail' => (string) ($old['admin_email'] ?? ''),
            'publicUrl' => (string) ($old['public_base_url'] ?? PublicUrl::detect()),
            'smtp' => array_merge(AdminAccount::SMTP_DEFAULTS, array_intersect_key($old['smtp'] ?? [], AdminAccount::SMTP_DEFAULTS)),
            'encryptions' => AdminAccount::ENCRYPTIONS,
        ]);
    }

    /** @return list<Check> every check of step 1 (local requirements plus the HTTP protection test) */
    private function allChecks(array $plan): array
    {
        $probe = new ProtectionProbe(PublicUrl::detect());
        return [...Requirements::all($plan), ...$probe->run($plan)];
    }

    /** @return array{admin_email:string, public_base_url:string, smtp:array<string,string>, smtp_password:string} */
    private function readForm(): array
    {
        $smtp = [];
        foreach (AdminAccount::SMTP_DEFAULTS as $key => $_) {
            $smtp[$key] = post($key);
        }
        return ['admin_email' => strtolower(post('admin_email')), 'public_base_url' => post('public_base_url'),
            'smtp' => $smtp, 'smtp_password' => (string) ($_POST['smtp_password'] ?? '')];
    }

    /** @return list<string> */
    private function validate(array &$input): array
    {
        $errors = [];
        if (!filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid administrator e-mail address, e.g. jane@example.com.';
        }
        $url = PublicUrl::normalize($input['public_base_url']);
        if ($url === null) {
            $errors[] = 'Enter the address of this site as people open it, e.g. https://example.com/ads (starting with https:// or http://).';
        } else {
            $input['public_base_url'] = $url;
        }
        return [...$errors, ...AdminAccount::validateSmtp($input['smtp'])];
    }

    /** Sends a welcome message with the entered SMTP data: a working mail setup is proven before anything is installed. @return list<string> */
    private function testSmtp(array $input): array
    {
        $smtp = $input['smtp'];
        $mailer = new Mailer(['host' => $smtp['smtp_host'], 'port' => (int) $smtp['smtp_port'], 'encryption' => $smtp['smtp_encryption'],
            'user' => $smtp['smtp_user'], 'password' => $input['smtp_password'], 'from_email' => $smtp['smtp_from_email'],
            'from_name' => $smtp['smtp_from_name'], 'ehlo_host' => (string) parse_url($input['public_base_url'], PHP_URL_HOST)]);
        try {
            $mailer->send($input['admin_email'], 'Google Ads MCP is being installed',
                "Mail from your new Google Ads MCP server works.\n\nSite address: {$input['public_base_url']}\n"
                . "Later, open that address, enter this e-mail address and use the one-time login link we send.\n");
            return [];
        } catch (MailError $e) {
            return ['The test e-mail could not be sent, nothing was installed yet: ' . $e->getMessage()
                . ' Correct the mail server details, or clear them to use PHP mail() (not recommended).'];
        }
    }

    private function rateLimit(): void
    {
        RateLimiter::enforce('setup:' . client_ip(), self::RATE_LIMIT, self::RATE_WINDOW);
    }

    /** After installation the wizard is unavailable. */
    private function guardNotInstalled(): void
    {
        session_boot();
        if (AdminAccount::isInstalled()) {
            redirect('/');
        }
    }

    /** @param list<string> $errors */
    private function back(array $errors): never
    {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect('/setup');
    }

    /** @param list<string> $errors */
    private function backToAdmin(array $errors, array $input): never
    {
        unset($input['smtp_password']); // never kept, not even in the session: the field has to be typed again
        $_SESSION['setup_old'] = $input;
        $this->back($errors);
    }
}
