#!/usr/bin/env bash
# Builds the release package for people who upload the whole application into ONE directory on their own hosting
# ("easy layout", docs/adr/0008-instalator.md). Output: dist/google-ads-mcp-php-<version>.zip and .tar.gz
#
# Usage: tools/build-release.sh [output_dir]        (default: <repo>/dist, git-ignored)
#
# Package layout (flat: extract it INTO the target directory; there is no top-level folder, so cPanel "Extract" just works):
#   index.php  .htaccess  .user.ini  assets/      <- from webroot/
#   app/                                          <- the application (app/VERSION, migrations, views, ...)
#   LICENSE  README.md  CHANGELOG.md
#   MANIFEST.sha256                               <- sha256 of every file above (sha256sum format), checked by the installer
# NOT included: tools/, tests/, docs/, bin/, .git*, CI files, google-ads-mcp-php-data/ (created by the installer), dev configuration.
# The result is deterministic apart from archive timestamps: sorted file list, fixed permissions (dirs 755, files 644).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/app/VERSION")"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "Bad version in app/VERSION: '$VERSION'" >&2; exit 1; }
OUT="${1:-$ROOT/dist}"
NAME="google-ads-mcp-php-$VERSION"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# 1. Gather the files (rsync: exact copy, no editor leftovers; webroot content goes to the top level).
rsync -a --exclude '*.bak' --exclude '*~' --exclude '.DS_Store' "$ROOT/webroot/" "$STAGE/"
rsync -a --exclude '*.bak' --exclude '*~' --exclude '.DS_Store' "$ROOT/app" "$STAGE/"
cp "$ROOT/LICENSE" "$ROOT/README.md" "$ROOT/CHANGELOG.md" "$STAGE/"

# 2. Sanity: the package must be able to start by itself.
for f in index.php .htaccess .user.ini assets/style.css app/bootstrap.php app/VERSION app/config.example.php; do
    [[ -f "$STAGE/$f" ]] || { echo "Missing in package: $f" >&2; exit 1; }
done
ls "$STAGE/app/migrations"/*.sql > /dev/null
if find "$STAGE" -name '*.sqlite*' -o -name config.php -o -name '*.log' -o -name INSTALL_CODE.txt | grep -q .; then
    echo "Refusing: the package would contain data or secrets" >&2; exit 1
fi

# 3. Fixed permissions, then the manifest (sorted, "<hash>  <path>"; the manifest does not list itself).
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
(cd "$STAGE" && find . -type f ! -name MANIFEST.sha256 | LC_ALL=C sort | sed 's|^\./||' | while IFS= read -r f; do sha256sum -- "$f"; done > MANIFEST.sha256)
(cd "$STAGE" && sha256sum --quiet -c MANIFEST.sha256)

# 4. Archives.
mkdir -p "$OUT"
rm -f "$OUT/$NAME.zip" "$OUT/$NAME.tar.gz"
(cd "$STAGE" && zip -q -r -X "$OUT/$NAME.zip" . -x '.git/*')
(cd "$STAGE" && tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$OUT/$NAME.tar.gz" .)
echo "Built $OUT/$NAME.zip and $OUT/$NAME.tar.gz ($(wc -l < "$STAGE/MANIFEST.sha256") files in the manifest)"
