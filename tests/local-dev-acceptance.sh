#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
PROFILE="${MAA_ADAPTER_ACCEPTANCE_PROFILE:-local}"
WP_PATH="${WP_PATH:-/Users/muze/Local Sites/magick-ai/app/public}"
WP_CLI_MYSQL_SOCKET="${WP_CLI_MYSQL_SOCKET:-${WP_DB_SOCKET:-}}"

if [[ -z "$WP_CLI_MYSQL_SOCKET" && -d "$HOME/Library/Application Support/Local/run" ]]; then
	for candidate in "$HOME/Library/Application Support/Local/run"/*/mysql/mysqld.sock; do
		[[ -S "$candidate" ]] || continue
		run_dir="$(cd "$(dirname "$candidate")/.." && pwd)"
		if [[ -f "$run_dir/conf/nginx/site.conf" ]] && grep -Fq "root   \"$WP_PATH\"" "$run_dir/conf/nginx/site.conf"; then
			WP_CLI_MYSQL_SOCKET="$candidate"
			break
		fi
	done
fi

# A TCP database needs no socket override; site checks report connection failures.
command -v "${WP_CLI:-wp}"
"${WP_CLI:-wp}" --info

composer_env=( "WP_PATH=$WP_PATH" "WP_CLI_MYSQL_SOCKET=$WP_CLI_MYSQL_SOCKET" )

run_composer() {
	env "${composer_env[@]}" "$COMPOSER_BIN" --working-dir="$ROOT_DIR" "$@"
}

run_acceptance() {
	env "${composer_env[@]}" \
		MAA_ADAPTER_ACCEPTANCE_PROFILE="$PROFILE" \
		MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS="${MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS:-0}" \
		"$COMPOSER_BIN" --working-dir="$ROOT_DIR" "$@"
}

echo "[local-dev] deterministic gates"
run_composer test:all
run_composer analyse:php
run_composer lint:standards
run_composer check:i18n
run_composer check:wporg

echo "[local-dev] WordPress smoke and package-install checks"
echo "[local-dev] smoke includes HTTP 400 validation for proposals/read-requests limit=abc"
smoke_log="$(mktemp)"
trap 'rm -f "$smoke_log"' EXIT
MAA_ADAPTER_SMOKE_CREATE_READ_GRANTS="${MAA_ADAPTER_SMOKE_CREATE_READ_GRANTS:-1}" run_composer smoke:wp | tee "$smoke_log"
if grep -q '\[skip\]' "$smoke_log"; then
	echo "[local-dev] Incomplete WordPress smoke: grant-dependent checks were skipped." >&2
	exit 1
fi
# Build current source unless intentionally testing an explicit external artifact.
if [[ -z "${MAA_ADAPTER_PACKAGE_SMOKE_ZIP:-}" ]]; then
	run_composer package:release
fi
run_composer smoke:package-install

echo "[local-dev] signed local AI client acceptance"
run_acceptance accept:local-ai-client

echo "[local-dev] signed non-destructive proposal fixture acceptance"
if [[ "${MAA_ADAPTER_FIXTURE_ALLOW_COMMIT:-}" == "1" ]]; then
	MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 run_acceptance accept:local-ai-client-fixture
else
	run_acceptance accept:local-ai-client-fixture
fi

echo "local development checks and acceptance: ok"
