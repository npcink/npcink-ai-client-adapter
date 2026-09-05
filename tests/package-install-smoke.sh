#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-/Users/muze/Local Sites/magick-ai/app/public}"
WP_CLI_BIN="${WP_CLI:-}"
WP_CLI_PHP="${WP_CLI_PHP:-}"
WP_CLI_PHP_ARGS="${WP_CLI_PHP_ARGS:-}"
WP_CLI_ERROR_REPORTING="${WP_CLI_ERROR_REPORTING:-8191}"
WP_CLI_MYSQL_SOCKET="${WP_CLI_MYSQL_SOCKET:-${WP_DB_SOCKET:-}}"
PACKAGE_PATH="${MAA_ADAPTER_PACKAGE_SMOKE_ZIP:-$ROOT_DIR/build/npcink-ai-client-adapter.zip}"
PLUGIN_SLUG="npcink-ai-client-adapter"
# Expected local plugin path: wp-content/plugins/npcink-ai-client-adapter.
PLUGIN_DIR="$WP_PATH/wp-content/plugins/$PLUGIN_SLUG"
RESTORE_KIND="none"
RESTORE_TARGET=""
RESTORE_STATUS=""
BACKUP_DIR=""

fail() {
	echo "[fail] $*" >&2
	exit 1
}

if [[ ! -f "$PACKAGE_PATH" ]]; then
	fail "Missing release package: $PACKAGE_PATH. Run composer package:release first."
fi

if [[ -z "$WP_CLI_BIN" ]]; then
	if command -v wp >/dev/null 2>&1; then
		WP_CLI_BIN="$(command -v wp)"
	elif [[ -f /tmp/wp-cli.phar ]]; then
		WP_CLI_BIN="/tmp/wp-cli.phar"
	else
		fail "Missing WP-CLI. Set WP_CLI=/path/to/wp or install wp on PATH."
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
	fail "Missing PHP for WP-CLI. Set WP_CLI_PHP=/path/to/php."
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
		php_args=("-d" "display_errors=0")
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

restore_original_plugin() {
	set +e
	if [[ -d "$PLUGIN_DIR" && ! -L "$PLUGIN_DIR" ]]; then
		run_wp plugin deactivate "$PLUGIN_SLUG" >/dev/null 2>&1 || true
		rm -rf "$PLUGIN_DIR"
	elif [[ -L "$PLUGIN_DIR" ]]; then
		rm "$PLUGIN_DIR"
	fi

	if [[ "$RESTORE_KIND" == "symlink" && -n "$RESTORE_TARGET" ]]; then
		ln -s "$RESTORE_TARGET" "$PLUGIN_DIR"
	elif [[ "$RESTORE_KIND" == "directory" && -n "$BACKUP_DIR" && -d "$BACKUP_DIR/$PLUGIN_SLUG" ]]; then
		mv "$BACKUP_DIR/$PLUGIN_SLUG" "$PLUGIN_DIR"
	fi

	if [[ "$RESTORE_STATUS" == "active" || "$RESTORE_STATUS" == "active-network" ]]; then
		run_wp plugin activate "$PLUGIN_SLUG" >/dev/null 2>&1 || true
	fi

	if [[ -n "$BACKUP_DIR" ]]; then
		rm -rf "$BACKUP_DIR"
	fi
}
trap restore_original_plugin EXIT

run_wp core is-installed >/dev/null

RESTORE_STATUS="$(run_wp plugin list --name="$PLUGIN_SLUG" --field=status --skip-update-check 2>/dev/null || true)"
if [[ -L "$PLUGIN_DIR" ]]; then
	RESTORE_KIND="symlink"
	RESTORE_TARGET="$(readlink "$PLUGIN_DIR")"
	rm "$PLUGIN_DIR"
elif [[ -d "$PLUGIN_DIR" ]]; then
	RESTORE_KIND="directory"
	BACKUP_DIR="$(mktemp -d)"
	mv "$PLUGIN_DIR" "$BACKUP_DIR/$PLUGIN_SLUG"
fi

run_wp plugin install "$PACKAGE_PATH" --force >/dev/null
run_wp plugin activate "$PLUGIN_SLUG" >/dev/null

status="$(run_wp plugin list --name="$PLUGIN_SLUG" --field=status --skip-update-check)"
title="$(run_wp plugin list --name="$PLUGIN_SLUG" --field=title --skip-update-check)"
file="$(run_wp plugin list --name="$PLUGIN_SLUG" --field=file --skip-update-check)"
legacy_count="$(run_wp plugin list --name=npcink-openclaw-adapter --format=count --skip-update-check)"

[[ "$status" == "active" ]] || fail "Package plugin is not active after install: $status"
[[ "$title" == "Npcink AI Client Adapter" ]] || fail "Unexpected package plugin title: $title"
[[ "$file" == "$PLUGIN_SLUG/npcink-ai-client-adapter.php" ]] || fail "Unexpected package plugin file: $file"
[[ "$legacy_count" == "0" ]] || fail "Legacy bootstrap is visible as an independent plugin."

if unzip -l "$PACKAGE_PATH" "$PLUGIN_SLUG/npcink-openclaw-adapter.php" >/dev/null 2>&1; then
	fail "Legacy bootstrap file should not be packaged."
fi

run_wp eval '
$users = get_users( array( "role" => "administrator", "number" => 1, "fields" => "ID" ) );
$user_id = absint( $users[0] ?? 0 );
if ( $user_id <= 0 ) {
	fwrite( STDERR, "Missing administrator user for package REST smoke.\n" );
	exit( 1 );
}
wp_set_current_user( $user_id );
$request = new WP_REST_Request( "GET", "/npcink-openclaw-adapter/v1/health" );
$response = rest_do_request( $request );
if ( 200 !== (int) $response->get_status() ) {
	fwrite( STDERR, "Package REST health returned HTTP " . (int) $response->get_status() . "\n" );
	exit( 1 );
}
$data = $response->get_data();
if ( ! is_array( $data ) || "npcink-ai-client-adapter" !== (string) ( $data["adapter"] ?? "" ) ) {
	fwrite( STDERR, "Package REST health returned an unexpected adapter payload.\n" );
	exit( 1 );
}
if ( "npcink_openclaw_adapter_contract.v1" !== (string) ( $data["contract"]["schema_version"] ?? "" ) ) {
	fwrite( STDERR, "Package REST health is missing Adapter contract metadata.\n" );
	exit( 1 );
}
'

echo "npcink-ai-client-adapter package install smoke: ok"
