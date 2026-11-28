#!/bin/sh
# Publishes the public part of the application (the contents of webroot/) into the web server's public directory.
# Usage: bin/publish-public.sh <public_dir> [--link]
#   dev:  bin/publish-public.sh /var/www/html/google-ads-php --link   (assets as a symlink: CSS edits show up at once)
#   prod: not used (tools/deploy.sh uploads webroot/ and app/ over FTP).
# ONLY what is in webroot/ goes to the public directory. Nothing outside webroot/ is copied or removed there.
set -eu
PUBLIC="${1:?give the public directory}"
MODE="${2:-copy}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$REPO/webroot"
# The script removes and recreates entries inside $PUBLIC, so refuse targets that are obviously wrong:
# an empty or relative path, the filesystem root, the home directory, the repository itself or any parent of it.
case "$PUBLIC" in
    /*) ;;
    *) echo "Refusing: the public directory must be an absolute path (got '$PUBLIC')" >&2; exit 2 ;;
esac
PUBLIC="$(printf '%s' "$PUBLIC" | sed 's:/*$::')"   # no trailing slash; "/" becomes an empty string
if [ -z "$PUBLIC" ] || [ "$PUBLIC" = "${HOME:-}" ] || [ "$PUBLIC" = "$REPO" ] || [ "$PUBLIC" = "$SRC" ]; then
    echo "Refusing to publish into '$PUBLIC'" >&2; exit 2
fi
case "$REPO/" in
    "$PUBLIC"/*) echo "Refusing: '$PUBLIC' contains the repository" >&2; exit 2 ;;
esac
mkdir -p "$PUBLIC"
for item in "$SRC"/* "$SRC"/.htaccess "$SRC"/.user.ini; do
    [ -e "$item" ] || continue
    name="$(basename "$item")"
    target="$PUBLIC/$name"
    if [ "$MODE" = "--link" ] && [ -d "$item" ]; then
        rm -rf "$target"; ln -s "$item" "$target"
    else
        rm -rf "$target"; cp -a "$item" "$target"
    fi
done
echo "Published $SRC -> $PUBLIC (${MODE})"
