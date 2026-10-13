#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
task_tmp="$(mktemp -d)"
trap 'rm -rf "$task_tmp"' EXIT
mkdir -p "$task_tmp/site/wp-content/plugins" "$task_tmp/original"
cat > "$task_tmp/php" <<'PHP'
#!/usr/bin/env bash
while [[ "${1:-}" == "-d" ]]; do shift 2; done
exec "$@"
PHP
cat > "$task_tmp/wp" <<'WP'
#!/usr/bin/env bash
[[ "${1:-}" != --path=* ]] || shift
case "$*" in
  '--info') exit 0 ;;
  'core is-installed') [[ "${MOCK_FAIL:-}" != database ]] ;;
  'plugin list '*) echo active ;;
  'plugin install '*) mkdir -p "$WP_PATH/wp-content/plugins/npcink-ai-client-adapter"; exit 1 ;;
  *) exit 0 ;;
esac
WP
cat > "$task_tmp/composer" <<'COMPOSER'
#!/usr/bin/env bash
shift
printf '%s\n' "$*" >> "$MOCK_LOG"
[[ "${MOCK_FAIL:-}" != "$*" ]] || exit 7
if [[ "$*" == smoke:wp && "${MOCK_SKIP:-}" == 1 ]]; then echo '[skip] Core grant'; fi
COMPOSER
chmod +x "$task_tmp/php" "$task_tmp/wp" "$task_tmp/composer"
export WP_PATH="$task_tmp/site" WP_CLI="$task_tmp/wp" WP_CLI_PHP="$task_tmp/php"
export WP_CLI_MYSQL_SOCKET="$task_tmp/mock.sock" MAA_ADAPTER_PACKAGE_SMOKE_ZIP="$task_tmp/package.zip"
touch "$MAA_ADAPTER_PACKAGE_SMOKE_ZIP"
plugin_dir="$WP_PATH/wp-content/plugins/npcink-ai-client-adapter"
for kind in directory symlink; do
  if [[ "$kind" == directory ]]; then mkdir "$plugin_dir"; touch "$plugin_dir/keep"; else ln -s "$task_tmp/original" "$plugin_dir"; fi
  for failure in database install; do
    if MOCK_FAIL="$failure" bash "$ROOT_DIR/tests/package-install-smoke.sh" > "$task_tmp/output" 2>&1; then echo "Expected $failure failure" >&2; exit 1; fi
    if [[ "$kind" == directory ]]; then test -f "$plugin_dir/keep"; else test "$(readlink "$plugin_dir")" = "$task_tmp/original"; fi
  done
  if [[ "$kind" == directory ]]; then rm -r "$plugin_dir"; else rm "$plugin_dir"; fi
done
unset MAA_ADAPTER_PACKAGE_SMOKE_ZIP
export COMPOSER_BIN="$task_tmp/composer" MOCK_LOG="$task_tmp/calls"
bash "$ROOT_DIR/tests/local-dev-acceptance.sh" > "$task_tmp/output" 2>&1
cat > "$task_tmp/expected" <<'EXPECTED'
test:all
analyse:php
lint:standards
check:i18n
check:wporg
smoke:wp
package:release
smoke:package-install
accept:local-ai-client
accept:local-ai-client-fixture
EXPECTED
diff -u "$task_tmp/expected" "$MOCK_LOG"
: > "$MOCK_LOG"
if MAA_ADAPTER_FIXTURE_FLAGGED_MEDIA=1 MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 MOCK_FAIL=accept:local-ai-client-flagged-media bash "$ROOT_DIR/tests/local-dev-acceptance.sh" > "$task_tmp/output" 2>&1; then
  echo 'Requested flagged-media gate failure must fail aggregate acceptance' >&2; exit 1
fi
test "$(tail -1 "$MOCK_LOG")" = accept:local-ai-client-flagged-media
! grep -q 'local development checks and acceptance: ok' "$task_tmp/output"
for failure in analyse:php smoke:package-install; do
  : > "$MOCK_LOG"
  if MOCK_FAIL="$failure" bash "$ROOT_DIR/tests/local-dev-acceptance.sh" > "$task_tmp/output" 2>&1; then echo "Expected gate failure" >&2; exit 1; fi
  test "$(tail -1 "$MOCK_LOG")" = "$failure"
done
: > "$MOCK_LOG"
if MOCK_SKIP=1 bash "$ROOT_DIR/tests/local-dev-acceptance.sh" > "$task_tmp/output" 2>&1; then echo "Skipped smoke must fail acceptance" >&2; exit 1; fi
test "$(tail -1 "$MOCK_LOG")" = smoke:wp
echo 'Local acceptance failure handling and plugin restoration: ok'
