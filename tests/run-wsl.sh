#!/usr/bin/env bash
# Run the test suite inside WSL at native speed.
#
# From Windows:  wsl bash tests/run-wsl.sh [-j N]
#
# PHP reading the repo through /mnt/c is roughly 10x slower than the native
# Linux filesystem, so mirror the working tree into /tmp first (only changed
# files are copied after the first run) and run the tests from there.
set -euo pipefail

src="$(cd "$(dirname "$0")/.." && pwd)"
dest="/tmp/rustc-php-mirror"

rsync -a --delete --exclude .git --exclude test_out --exclude 'test_out_*' "$src/" "$dest/"
cd "$dest"
exec php tests/run.php "$@"
