#!/usr/bin/env bash
# Production deployment (FTP only). The FTP account root is the domain directory:
#   webroot/ -> /public_html   (public: index.php, .htaccess, assets)
#   app/     -> /google-ads-mcp-php/app   (private, one level above public_html)
# Does NOT touch the data directory (config.php, app.sqlite, logs/, uploads/) in /google-ads-mcp-php/google-ads-mcp-php-data.
# Credentials: environment variables or the tools/deploy.env file (outside git; template: deploy.env.example).
#
# Usage:
#   tools/deploy.sh             deploy (checks, upload, swap, smoke test)
#   tools/deploy.sh --rollback  swap app/ and the previous release app.prev/ back, then smoke test
#   tools/deploy.sh --log       print today's production log (logs/app-YYYY-MM-DD.log)
#
# How a deploy works:
#   1. Local checks: php -l on every PHP file and the offline test suite (tests/run.php). Nothing is uploaded when they fail.
#   2. The new app/ is uploaded next to the live one as app.new (the live site is untouched while this runs).
#   3. Two quick renames: app -> app.prev (the previous release, kept for rollback), app.new -> app.
#      FTP has no atomic swap, so there is a gap of a moment between the two renames.
#      Files that no longer exist in the repository disappear from the server (the whole app/ is replaced).
#   4. webroot/ is mirrored to /public_html (without deleting, so extra files such as a hosting default page stay).
#   5. Smoke test: the home page returns 200 and /health reports a status other than "error" and the version from app/VERSION.
#      On failure the script says so; run --rollback.
# The FTPS certificate is verified (see FTP_SSL_* in deploy.env.example). The database is never touched by a deploy;
# back it up with tools/backup-sqlite.php (README, "Backups").
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# The env file only fills in variables that are not set in the environment.
if [[ -f "$ROOT/tools/deploy.env" ]]; then
    set -a; source "$ROOT/tools/deploy.env"; set +a
fi
for v in FTP_USER FTP_HOST SITE_URL FTP_PASSWORD_CMD; do
    [[ -n "${!v:-}" ]] || { echo "Missing variable $v (set it in the environment or in tools/deploy.env, template: tools/deploy.env.example)" >&2; exit 2; }
done
PRIVATE_DIR="${PRIVATE_DIR:-/google-ads-mcp-php}"
DATA_DIR="${DATA_DIR:-$PRIVATE_DIR/google-ads-mcp-php-data}"
FTP_PORT="${FTP_PORT:-21}"
FTP_SSL_VERIFY="${FTP_SSL_VERIFY:-yes}"
FTP_SSL_CA_FILE="${FTP_SSL_CA_FILE:-}"
FTP_CERT_SHA256="${FTP_CERT_SHA256:-}"
SITE_URL="${SITE_URL%/}"

die() { echo "$*" >&2; exit 1; }

# ---- FTPS certificate verification ----

# Prints the SHA-256 fingerprint (64 lowercase hex characters) of the certificate the FTP server presents.
server_cert_fingerprint() {
    openssl s_client -starttls ftp -connect "$FTP_HOST:$FTP_PORT" -servername "$FTP_HOST" </dev/null 2>/dev/null \
        | openssl x509 -noout -fingerprint -sha256 | sed 's/^.*=//; s/://g' | tr 'A-F' 'a-f' || true
}

# Pinning: compare the server certificate with FTP_CERT_SHA256. Because the pinned certificate is exactly the one we trust,
# the CA chain is not needed (shared hosts often use a self-signed or hostname-mismatched certificate for FTP).
# Pitfall: the fingerprint is checked in a separate connection just before lftp connects; that is fine against passive
# MITM and misconfiguration, but not a substitute for a CA-verified certificate. Re-pin when the host renews its certificate.
check_pinned_certificate() {
    local pin actual
    pin="$(printf '%s' "$FTP_CERT_SHA256" | tr -d ':' | tr 'A-F' 'a-f')"
    [[ "$pin" =~ ^[0-9a-f]{64}$ ]] || die "FTP_CERT_SHA256 must be a SHA-256 fingerprint (64 hex characters, colons allowed)"
    actual="$(server_cert_fingerprint)"
    [[ "$actual" == "$pin" ]] || die "FTPS certificate fingerprint mismatch: expected $pin, server presented ${actual:-nothing}. Possible MITM, or the host renewed its certificate."
}

# Prints the lftp "set ..." commands for certificate handling according to the FTP_SSL_* variables.
lftp_ssl_settings() {
    if [[ -n "$FTP_CERT_SHA256" ]]; then
        check_pinned_certificate
        echo "set ssl:verify-certificate no;"       # trust comes from the pinned fingerprint checked above
    elif [[ "$FTP_SSL_VERIFY" == "no" ]]; then
        echo "WARNING: FTPS certificate verification is OFF (FTP_SSL_VERIFY=no): the code and password can be intercepted." >&2
        echo "set ssl:verify-certificate no;"
    else
        echo "set ssl:verify-certificate yes; set ssl:check-hostname yes;"
        if [[ -n "$FTP_SSL_CA_FILE" ]]; then
            [[ -f "$FTP_SSL_CA_FILE" ]] || die "FTP_SSL_CA_FILE not found: $FTP_SSL_CA_FILE"
            [[ "$FTP_SSL_CA_FILE" =~ ^[A-Za-z0-9_./+-]+$ ]] || die "FTP_SSL_CA_FILE contains characters that are not allowed in a path"
            echo "set ssl:ca-file $FTP_SSL_CA_FILE;"
        fi
    fi
}

LFTP_PASSWORD="$(bash -c "$FTP_PASSWORD_CMD")"
export LFTP_PASSWORD
# "cmd:fail-exit yes": any failing lftp command aborts the whole run (default is to carry on, which would swap a half-uploaded app).
SSL_SETTINGS="$(lftp_ssl_settings)"   # separate step: a failure inside must stop the script
CONN="set cmd:fail-exit yes; $SSL_SETTINGS set ftp:ssl-force true; open --env-password -p $FTP_PORT -u $FTP_USER $FTP_HOST"

# ---- Local checks ----

preflight() {
    echo "Checking PHP syntax..."
    local file
    while IFS= read -r -d '' file; do
        php -l "$file" > /dev/null || die "Syntax error in $file; nothing was uploaded."
    done < <(find "$ROOT" -name '*.php' -not -path "$ROOT/.git/*" -not -path "$ROOT/google-ads-mcp-php-data/*" -not -path "$ROOT/tools/bin/*" -print0)
    echo "Running tests..."
    local report
    if ! report="$(php "$ROOT/tests/run.php" 2>&1)"; then
        echo "$report" >&2
        die "Tests failed; nothing was uploaded."
    fi
    echo "$report" | tail -n 1
    [[ -s "$ROOT/app/VERSION" ]] || die "app/VERSION is missing"
    if [[ -n "$(git -C "$ROOT" status --porcelain 2>/dev/null)" ]]; then
        echo "WARNING: the working tree has uncommitted changes; the server will get them too." >&2
    fi
}

# ---- Smoke test ----

smoke_test() {
    local expected code health
    expected="$(tr -d '[:space:]' < "$ROOT/app/VERSION")"
    code="$(curl -s -o /dev/null --max-time 20 -w '%{http_code}' "$SITE_URL/")"
    echo "Home page: $code"
    health="$(curl -s --max-time 20 "$SITE_URL/health" || true)"
    echo "Health: $health"
    if [[ "$code" == "200" ]] && printf '%s' "$health" | EXPECTED="$expected" php -r '
        $h = json_decode((string) stream_get_contents(STDIN), true);
        exit(is_array($h) && ($h["status"] ?? "error") !== "error" && ($h["version"] ?? "") === getenv("EXPECTED") ? 0 : 1);'; then
        echo "Smoke test passed (version $expected)."
    else
        die "SMOKE TEST FAILED. Roll back with: tools/deploy.sh --rollback"
    fi
}

# ---- Commands ----

deploy() {
    preflight
    echo "Uploading version $(tr -d '[:space:]' < "$ROOT/app/VERSION")..."
    lftp -e "$CONN; \
        rm -rf $PRIVATE_DIR/app.new; \
        mirror -R --parallel=4 --no-perms $ROOT/app $PRIVATE_DIR/app.new; \
        rm -rf $PRIVATE_DIR/app.prev; \
        mv $PRIVATE_DIR/app $PRIVATE_DIR/app.prev; \
        mv $PRIVATE_DIR/app.new $PRIVATE_DIR/app; \
        mkdir -p $PRIVATE_DIR/tools; \
        put -O $PRIVATE_DIR/tools $ROOT/tools/backup-sqlite.php; \
        put -O $PRIVATE_DIR $ROOT/LICENSE; \
        mirror -R --parallel=4 --no-perms $ROOT/webroot /public_html; \
        bye"
    smoke_test
}

rollback() {
    echo "Rolling back to the previous release (app.prev)..."
    lftp -e "$CONN; \
        mv $PRIVATE_DIR/app $PRIVATE_DIR/app.rollback-tmp; \
        mv $PRIVATE_DIR/app.prev $PRIVATE_DIR/app; \
        mv $PRIVATE_DIR/app.rollback-tmp $PRIVATE_DIR/app.prev; \
        bye"
    # The version check of the smoke test is meaningless here: the previous release has its own version.
    local code
    code="$(curl -s -o /dev/null --max-time 20 -w '%{http_code}' "$SITE_URL/health" || true)"
    echo "Health after rollback: HTTP $code (a second --rollback restores the release that was rolled back)"
    echo "Note: webroot/ is not rolled back; if it changed, check out the previous tag and run a deploy."
}

case "${1:-}" in
    --log) lftp -e "$CONN; cat $DATA_DIR/logs/app-$(date -u +%F).log; bye" ;;
    --rollback) rollback ;;
    "") deploy ;;
    *) die "Unknown option: $1 (use --rollback or --log)" ;;
esac
