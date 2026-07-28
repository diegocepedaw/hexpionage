#!/usr/bin/env bash
#
# tools/check.sh — run every offline check for Hexpionage.
#
#   ./tools/check.sh            # fast:  lint + contract + links + 40 playouts
#   ./tools/check.sh 300        # slow:  lint + contract + links + 300 playouts
#
# Requires: php 8.1+ (brew install php), node, python3 (all preinstalled or brew).
# Nothing here touches BGA Studio; it all runs locally in ~1-70 seconds.

set -uo pipefail

cd "$(dirname "$0")/.."
GAMES="${1:-40}"
FAILED=0

step() { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }
fail() { printf '\033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }
pass() { printf '\033[32mok\033[0m   %s\n' "$1"; }

step "PHP syntax"
while IFS= read -r f; do
  if out=$(php -l "$f" 2>&1); then :; else fail "$f"; echo "$out"; fi
done < <(find src tools -name '*.php')
[ "$FAILED" -eq 0 ] && pass "all PHP files parse"

step "JS syntax"
# node --check treats a .js file as a SCRIPT, so ES-module syntax errors (and a
# missing `export`) slip through silently. modules/js/Game.js is loaded by BGA as
# an ES module, so any file using import/export must be parsed in module mode —
# which node only does for a .mjs extension.
JSTMP=$(mktemp -d)
for f in src/modules/js/*.js; do
  if grep -qE '^[[:space:]]*(export|import)[[:space:]]' "$f"; then
    cp "$f" "$JSTMP/mod.mjs"; target="$JSTMP/mod.mjs"; mode="module"
  else
    cp "$f" "$JSTMP/scr.js";  target="$JSTMP/scr.js";  mode="script"
  fi
  if out=$(node --check "$target" 2>&1); then pass "$f ($mode)"; else fail "$f ($mode)"; echo "$out"; fi
done
rm -rf "$JSTMP"

step "server/client contract"
php tools/harness/check_contract.php || fail "contract mismatch"

step "cross-references"
python3 scripts/check_links.py || fail "broken in-repo references"

step "rules engine ($GAMES simulated games)"
php tools/harness/run_tests.php --games="$GAMES" || fail "playout failures"

printf '\n'
if [ "$FAILED" -ne 0 ]; then
  printf '\033[31mCHECKS FAILED\033[0m\n'
  exit 1
fi
printf '\033[32mALL CHECKS PASSED\033[0m\n'
