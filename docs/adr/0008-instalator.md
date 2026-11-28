# 8. Web installer and the single-directory layout

Status: accepted (2026-11)

## Context
Until now installation meant uploading two parts to two places, writing `config.php` by hand and filling in a first-run form that
only asked for an e-mail and SMTP. Anyone who is not a developer could not do it. The goal: download a package, upload it to any folder
of a hosting account, open the address, answer a few questions, be logged in as administrator.

## Decision
1. **Two layouts.** The separate public/private layout (ADR 0001) stays for production and development. The release package
   (`tools/build-release.sh`) is *flat*: `index.php`, `.htaccess`, `.user.ini`, `assets/` and `app/` in one folder. `webroot/index.php` tries `__DIR__` as the
   application directory **last**, so a separate layout never switches to a stray `app/` next to the front controller.
2. **Protection in the flat layout** rests on `webroot/.htaccess` (deny `app/ tools/ tests/ docs/ bin/ google-ads-mcp-php-data/`, `*.md|sql|sqlite|log|sha256|sh`, `INSTALL_CODE.txt`)
   and, as defence in depth, an `.htaccess` inside the data directory. Because `.htaccess` can be ignored (nginx, `AllowOverride None`), the installer *tests* it:
   `Install\ProtectionProbe` requests `app/VERSION`, `app/bootstrap.php`, `tools/` and a temporary marker in the data directory over HTTP; a 200 blocks the installation with instructions.
   If it cannot test (no curl, no loopback), it warns and asks for a manual check.
3. **Data directory.** Preferred: `../google-ads-mcp-php-data`, above the installation folder, when that parent is writable and not inside the document root
   (with the app in `public_html/gads` the parent is `public_html`, which is served, so it is not used). Otherwise `./google-ads-mcp-php-data`. `DATA_PATH` is still decided in one place
   (`resolve_data_path()` in `bootstrap.php`): a directory that already holds `config.php` or `app.sqlite` wins (local first, so every existing install is unchanged), else an existing writable
   outside directory, else the local one. No pointer file: the existence of the directory is the pointer. Creating the outside directory changes the answer, so the wizard redirects right after.
4. **No e-mail verification at install time.** The installer logs the administrator in directly. Requiring a mailed link would make a working mail setup a precondition and
   defeat the purpose of the test step. The cost is the known risk of every web installer: whoever finishes it first becomes the administrator. Mitigations: the UI says
   "finish right away", the wizard closes itself (`installed.lock`, written last, wins over the database), it is rate limited, and the optional `INSTALL_CODE.txt` makes the first step require a secret
   known only to whoever uploaded the files. Production, which has an admin address in `config.php`, never sees the wizard.
5. **PHP `mail()` as a fallback**, enabled explicitly (`settings.mail_transport = php`) when the installer gets no SMTP host. It is unreliable (no delivery feedback, often spam), so the
   UI warns at install time and on the home page. With an SMTP host the installer sends a test message first and installs nothing if it fails. Existing installs without SMTP keep their behaviour
   (the emergency link file), because the flag is not set for them.
6. **Integrity.** The package carries `MANIFEST.sha256` (`sha256sum` format). A changed or missing file is a FAIL (FTP text mode and interrupted uploads are the usual cause);
   `.htaccess` and `.user.ini` only warn (hosts and users edit them); no manifest (git checkout) is a warning.
7. **SSRF.** The probe only issues GET requests to three fixed paths under the address derived from the current request, resolves the host itself and
   pins the connection to an address that is this server's own or public, follows no redirects and returns nothing but a status to the visitor.

## Consequences
- Installation is four steps for a non-technical person; developers keep their layout and tests.
- The wizard has to stay small: checks, one form, one runner. Anything else (Google connection, MCP client) happens in the admin panel afterwards.
- The checks cannot prove that the web server is configured correctly on every host; the manual-check wording is the fallback.

## Alternatives considered
A mailed login link at install (needs working mail first; chosen against); one fixed data location (either unsafe in the flat layout or impossible on hosts that forbid writing above the web root);
a pointer file for DATA_PATH (one more thing to go stale); Composer or a framework installer (ADR 0004).
