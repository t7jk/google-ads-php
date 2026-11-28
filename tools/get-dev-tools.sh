#!/usr/bin/env bash
# Downloads the development tools as phars into tools/bin (git-ignored). The application itself has no Composer
# dependencies (ADR 0004, 0007); these tools run only on a developer machine and in CI, never on the server.
# Usage: tools/get-dev-tools.sh
# Pitfall: "latest" is not pinned, so a new Pint/PHPStan release can add findings. Pin a version by editing the URLs below.
set -euo pipefail
BIN="$(cd "$(dirname "$0")" && pwd)/bin"
mkdir -p "$BIN"
curl -fsSL -o "$BIN/pint.phar" https://github.com/laravel/pint/releases/latest/download/pint.phar
curl -fsSL -o "$BIN/phpstan.phar" https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar
php "$BIN/pint.phar" --version
php "$BIN/phpstan.phar" --version
