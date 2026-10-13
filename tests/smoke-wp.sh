#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-/Users/muze/Local Sites/magick-ai/app/public}"
WP_CLI_BIN="${WP_CLI:-}"
WP_CLI_PHP="${WP_CLI_PHP:-}"
WP_CLI_PHP_ARGS="${WP_CLI_PHP_ARGS:-}"
WP_CLI_ERROR_REPORTING="${WP_CLI_ERROR_REPORTING:-8191}"
WP_CLI_MYSQL_SOCKET="${WP_CLI_MYSQL_SOCKET:-}"

if [[ -z "$WP_CLI_BIN" ]]; then
	if command -v wp >/dev/null 2>&1; then
		WP_CLI_BIN="$(command -v wp)"
	elif [[ -f /tmp/wp-cli.phar ]]; then
		WP_CLI_BIN="/tmp/wp-cli.phar"
	else
		echo "Missing WP-CLI. Set WP_CLI=/path/to/wp or install wp on PATH." >&2
		exit 2
	fi
fi

if [[ -z "$WP_CLI_PHP" ]]; then
	for candidate in \
		"$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" \
		"$HOME/Library/Application Support/Local/lightning-services/php-8.5.3+1/bin/darwin-arm64/bin/php" \
		"$(command -v php 2>/dev/null || true)"
	do
		if [[ -n "$candidate" && -x "$candidate" ]]; then
			WP_CLI_PHP="$candidate"
			break
		fi
	done
fi

if [[ -z "$WP_CLI_PHP" ]]; then
	echo "Missing PHP for WP-CLI. Set WP_CLI_PHP=/path/to/php." >&2
	exit 2
fi

if [[ -z "$WP_CLI_MYSQL_SOCKET" ]]; then
	default_socket="$HOME/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock"
	if [[ -S "$default_socket" ]]; then
		WP_CLI_MYSQL_SOCKET="$default_socket"
	fi
fi

wp_args=()
if [[ -n "$WP_PATH" ]]; then
	wp_args+=(--path="$WP_PATH")
fi

run_wp() {
	if [[ "$WP_CLI_BIN" == *.phar || -n "$WP_CLI_MYSQL_SOCKET" || -n "$WP_CLI_PHP_ARGS" ]]; then
		php_args=()
		if [[ -n "$WP_CLI_ERROR_REPORTING" ]]; then
			php_args+=("-d" "error_reporting=$WP_CLI_ERROR_REPORTING")
		fi
		if [[ -n "$WP_CLI_MYSQL_SOCKET" ]]; then
			php_args+=("-d" "mysql.default_socket=$WP_CLI_MYSQL_SOCKET")
			php_args+=("-d" "mysqli.default_socket=$WP_CLI_MYSQL_SOCKET")
			php_args+=("-d" "pdo_mysql.default_socket=$WP_CLI_MYSQL_SOCKET")
		fi
		if [[ -n "$WP_CLI_PHP_ARGS" ]]; then
			extra_php_args=()
			read -r -a extra_php_args <<< "$WP_CLI_PHP_ARGS"
			php_args+=("${extra_php_args[@]}")
		fi
		"$WP_CLI_PHP" "${php_args[@]}" "$WP_CLI_BIN" "${wp_args[@]}" "$@"
		return
	fi

	"$WP_CLI_BIN" "${wp_args[@]}" "$@"
}

run_wp core is-installed >/dev/null

plugin_dir="$WP_PATH/wp-content/plugins/npcink-ai-client-adapter"
if [[ ! -e "$plugin_dir" ]]; then
	ln -s "$ROOT_DIR" "$plugin_dir"
fi

if [[ "$(run_wp plugin status npcink-ai-client-adapter --field=status 2>/dev/null || true)" != "active" ]]; then
	run_wp plugin activate npcink-ai-client-adapter >/dev/null
fi

# The fixture suite uses administrator auth and creates its own scoped app key.
# Do not spend a configured site's live Core app quota in this CLI process.
smoke_args=()
if [[ "${MAA_ADAPTER_SMOKE_USE_CONFIGURED_CORE_TOKEN:-}" != "1" && "${MAA_ADAPTER_SMOKE_FLAGGED_MEDIA:-}" != "1" ]]; then
	smoke_args+=(--exec='if (!defined("NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN")) { define("NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN", ""); }')
fi
if [[ "${MAA_ADAPTER_SMOKE_FLAGGED_MEDIA:-}" == "1" ]]; then
	run_wp eval-file "$ROOT_DIR/tests/flagged-media-acceptance.php"
else
	run_wp ${smoke_args[@]+"${smoke_args[@]}"} eval-file "$ROOT_DIR/tests/write-provider-log-context-wp.php"
	run_wp ${smoke_args[@]+"${smoke_args[@]}"} eval-file "$ROOT_DIR/tests/smoke-wp.php"
fi
