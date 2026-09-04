#!/usr/bin/env bash
set -euo pipefail

WP_PATH_VALUE="${WP_PATH:?Set WP_PATH to the WordPress installation used for release checks.}"
WP_CLI_BIN="${WP_CLI:-${WP_CLI_BIN:-}}"
if [[ -z "$WP_CLI_BIN" ]]; then
	WP_CLI_BIN="$(command -v wp 2>/dev/null || true)"
fi
if [[ -z "$WP_CLI_BIN" ]]; then
	echo "Missing WP-CLI. Set WP_CLI=/path/to/wp." >&2
	exit 127
fi

WP_CLI_PHP_VALUE="${WP_CLI_PHP:-php}"
SOCKET="${WP_DB_SOCKET:-}"
php_args=( -d display_errors=0 -d error_reporting=8191 )
if [[ -n "$SOCKET" ]]; then
	php_args+=( -d "mysql.default_socket=$SOCKET" -d "mysqli.default_socket=$SOCKET" -d "pdo_mysql.default_socket=$SOCKET" )
fi

output_file="$(mktemp)"
trap 'rm -f "$output_file"' EXIT

"$WP_CLI_PHP_VALUE" "${php_args[@]}" "$WP_CLI_BIN" \
	--path="$WP_PATH_VALUE" --no-color \
	plugin check npcink-ai-client-adapter --format=table \
	--exclude-directories=tests,.git,.github,vendor,node_modules,build,sj,scripts \
	--exclude-files=.gitignore,.distignore,AGENTS.md,composer.json,composer.lock,phpcs.xml,phpcs.xml.dist,phpstan.neon,phpstan.neon.dist \
	| tee "$output_file"

if awk 'BEGIN { found=0 } /^FILE:/ { file=$0 } /\tERROR\t/ { found=1 } END { exit(found ? 0 : 1) }' "$output_file"; then
	echo "Plugin Check reported one or more ERROR findings." >&2
	exit 1
fi
