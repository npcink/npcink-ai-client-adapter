#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NODE_BIN="${NODE_BIN:-node}"
PROFILE="${MAA_ADAPTER_ACCEPTANCE_PROFILE:-local}"
ALLOW_COMMIT="${MAA_ADAPTER_FIXTURE_ALLOW_COMMIT:-}"
CLEANUP_POST="${MAA_ADAPTER_FIXTURE_CLEANUP_POST:-1}"
WP_PATH="${WP_PATH:-/Users/muze/Local Sites/magick-ai/app/public}"
WP_CLI_BIN="${WP_CLI:-}"
WP_CLI_PHP="${WP_CLI_PHP:-}"
WP_CLI_ERROR_REPORTING="${WP_CLI_ERROR_REPORTING:-8191}"
WP_CLI_MYSQL_SOCKET="${WP_CLI_MYSQL_SOCKET:-${WP_DB_SOCKET:-}}"
CLI=( "$NODE_BIN" "$ROOT_DIR/packages/adapter-cli/bin/npcink-openclaw-adapter.mjs" )
COMMON_ARGS=( "--profile=$PROFILE" )
if [[ "${MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS:-}" == "1" ]]; then
	COMMON_ARGS+=( "--insecure-local-tls" )
fi

fail() {
	echo "[fail] $*" >&2
	exit 1
}

json_field() {
	local file="$1"
	local expr="$2"
	"$NODE_BIN" -e '
const fs = require("node:fs");
const file = process.argv[1];
const expr = process.argv[2];
const data = JSON.parse(fs.readFileSync(file, "utf8"));
const value = expr.split(".").reduce((current, key) => current && current[key], data);
if (value === undefined || value === null || value === "") process.exit(1);
if (typeof value === "object") console.log(JSON.stringify(value));
else console.log(String(value));
' "$file" "$expr"
}

assert_json_field_equals() {
	local file="$1"
	local expr="$2"
	local expected="$3"
	local label="$4"
	local actual
	actual="$(json_field "$file" "$expr")" || fail "$label did not return $expr."
	[[ "$actual" == "$expected" ]] || fail "$label expected $expr=$expected, got $actual."
}

assert_json_field_present() {
	local file="$1"
	local expr="$2"
	local label="$3"
	json_field "$file" "$expr" >/dev/null || fail "$label did not return $expr."
}

run_cli_json() {
	local output_file="$1"
	shift
	if ! "${CLI[@]}" "$@" >"$output_file"; then
		if [[ -s "$output_file" ]]; then
			cat "$output_file" >&2
		fi
		fail "Adapter CLI command failed: $*"
	fi
}

ensure_wp_cli() {
	if [[ -n "$WP_CLI_BIN" ]]; then
		return
	fi
	if command -v wp >/dev/null 2>&1; then
		WP_CLI_BIN="$(command -v wp)"
	elif [[ -f /tmp/wp-cli.phar ]]; then
		WP_CLI_BIN="/tmp/wp-cli.phar"
	else
		fail "Missing WP-CLI. Set WP_CLI=/path/to/wp or install wp on PATH."
	fi
}

ensure_wp_php() {
	if [[ -n "$WP_CLI_PHP" ]]; then
		return
	fi
	for candidate in \
		"$HOME/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" \
		"$HOME/Library/Application Support/Local/lightning-services/php-8.5.3+1/bin/darwin-arm64/bin/php" \
		"$(command -v php 2>/dev/null || true)"
	do
		if [[ -n "$candidate" && -x "$candidate" ]]; then
			WP_CLI_PHP="$candidate"
			return
		fi
	done
	fail "Missing PHP for WP-CLI fixture cleanup."
}

run_wp() {
	ensure_wp_cli
	if [[ -z "$WP_CLI_MYSQL_SOCKET" ]]; then
		local default_socket="$HOME/Library/Application Support/Local/run/NPb24Zg9g/mysql/mysqld.sock"
		if [[ -S "$default_socket" ]]; then
			WP_CLI_MYSQL_SOCKET="$default_socket"
		fi
	fi
	if [[ "$WP_CLI_BIN" != *.phar && -z "$WP_CLI_MYSQL_SOCKET" ]]; then
		"$WP_CLI_BIN" --path="$WP_PATH" "$@"
		return
	fi

	ensure_wp_php

	local php_args=("-d" "display_errors=0")
	if [[ -n "$WP_CLI_ERROR_REPORTING" ]]; then
		php_args+=("-d" "error_reporting=$WP_CLI_ERROR_REPORTING")
	fi
	if [[ -n "$WP_CLI_MYSQL_SOCKET" ]]; then
		php_args+=("-d" "mysql.default_socket=$WP_CLI_MYSQL_SOCKET")
		php_args+=("-d" "mysqli.default_socket=$WP_CLI_MYSQL_SOCKET")
		php_args+=("-d" "pdo_mysql.default_socket=$WP_CLI_MYSQL_SOCKET")
	fi
	"$WP_CLI_PHP" "${php_args[@]}" "$WP_CLI_BIN" --path="$WP_PATH" "$@"
}

tmp_dir="$(mktemp -d)"
cleanup() {
	rm -rf "$tmp_dir"
}
trap cleanup EXIT

proposal_body="$tmp_dir/create-draft-proposal.json"
proposal_out="$tmp_dir/create-draft-proposal.out.json"
status_out="$tmp_dir/proposal-status.out.json"
self_approve_out="$tmp_dir/self-approve.out"
self_approve_err="$tmp_dir/self-approve.err"
no_intent_out="$tmp_dir/no-intent.out"
no_intent_err="$tmp_dir/no-intent.err"
execute_out="$tmp_dir/execute.out.json"
executed_status_out="$tmp_dir/executed-proposal-status.out.json"
duplicate_out="$tmp_dir/duplicate.out"
duplicate_err="$tmp_dir/duplicate.err"

approve_php="$tmp_dir/approve-proposal.php"
cat >"$approve_php" <<'PHP'
<?php
$proposal_id = (string) getenv( 'MAA_FIXTURE_PROPOSAL_ID' );
if ( '' === $proposal_id ) {
	fwrite( STDERR, "Missing MAA_FIXTURE_PROPOSAL_ID.\n" );
	exit( 1 );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
if ( empty( $admins ) ) {
	fwrite( STDERR, "No administrator user found for the human approval step.\n" );
	exit( 1 );
}
wp_set_current_user( (int) $admins[0]->ID );
$request = new WP_REST_Request( 'POST', '/npcink-governance-core/v1/proposals/' . rawurlencode( $proposal_id ) . '/approve' );
$request->set_param( 'proposal_id', $proposal_id );
$request->set_param( 'note', 'Human approval for the Adapter CLI fixture acceptance flow.' );
$response = rest_do_request( $request );
if ( is_wp_error( $response ) ) {
	fwrite( STDERR, $response->get_error_message() . "\n" );
	exit( 1 );
}
if ( ! $response instanceof WP_REST_Response ) {
	fwrite( STDERR, "Core approval returned an unexpected response type.\n" );
	exit( 1 );
}
$data = $response->get_data();
if ( 200 !== $response->get_status() || ! is_array( $data ) || 'approved' !== (string) ( $data['status'] ?? '' ) ) {
	fwrite( STDERR, 'Core approval did not return an approved proposal: ' . wp_json_encode( $data ) . "\n" );
	exit( 1 );
}
PHP

approve_proposal_as_admin() {
	MAA_FIXTURE_PROPOSAL_ID="$1" run_wp eval-file "$approve_php" >/dev/null
}

cat >"$proposal_body" <<JSON
{
  "ability_id": "npcink-abilities-toolkit/create-draft",
  "title": "Adapter CLI fixture draft proposal",
  "summary": "Local AI client fixture proposal for Adapter release acceptance.",
  "input": {
    "title": "Adapter CLI fixture draft",
    "content": "Adapter CLI fixture draft created through the signed local client acceptance flow.",
    "content_format": "plain",
    "dry_run": true,
    "commit": false
  },
  "preview": {
    "action": "create_draft",
    "dry_run": true,
    "commit_execution": false
  },
  "caller": {
    "external_thread_id": "adapter-cli-fixture-acceptance"
  }
}
JSON

echo "[accept-fixture] checking signed Adapter status"
run_cli_json "$tmp_dir/status.out.json" status "${COMMON_ARGS[@]}"

echo "[accept-fixture] creating create-draft proposal through signed CLI"
run_cli_json "$proposal_out" request "${COMMON_ARGS[@]}" POST /proposals --body-file="$proposal_body"
proposal_id="$(json_field "$proposal_out" proposal_id)" || fail "Proposal creation did not return proposal_id."
ability_id="$(json_field "$proposal_out" ability_id)" || fail "Proposal creation did not return ability_id."
[[ "$ability_id" == "npcink-abilities-toolkit/create-draft" ]] || fail "Unexpected proposal ability id: $ability_id"

echo "[accept-fixture] reading proposal status through signed CLI"
run_cli_json "$status_out" request "${COMMON_ARGS[@]}" GET "/proposals/$proposal_id"
status_proposal_id="$(json_field "$status_out" proposal_id)" || fail "Proposal status did not return proposal_id."
[[ "$status_proposal_id" == "$proposal_id" ]] || fail "Proposal status id mismatch."

echo "[accept-fixture] verifying signed clients cannot self-approve through the unified route"
set +e
"${CLI[@]}" request "${COMMON_ARGS[@]}" POST "/proposals/$proposal_id/approve-and-execute" --intent=commit >"$self_approve_out" 2>"$self_approve_err"
self_approve_code=$?
set -e
if [[ "$self_approve_code" -eq 0 ]]; then
	fail "Signed client approve-and-execute unexpectedly succeeded."
fi
grep -q 'npcink_openclaw_adapter_approve_requires_admin_session' "$self_approve_out" || grep -q 'npcink_openclaw_adapter_approve_requires_admin_session' "$self_approve_err" || fail "Signed client self-approval refusal did not return the admin-session error code."

echo "[accept-fixture] verifying the final execute route refuses missing commit intent"
set +e
"${CLI[@]}" request "${COMMON_ARGS[@]}" POST "/proposals/$proposal_id/execute" >"$no_intent_out" 2>"$no_intent_err"
no_intent_code=$?
set -e
if [[ "$no_intent_code" -eq 0 ]]; then
	fail "Final route succeeded without --intent=commit."
fi
grep -q -- '--intent=commit' "$no_intent_err" || grep -q -- '--intent=commit' "$no_intent_out" || fail "Missing commit-intent refusal message."

if [[ "$ALLOW_COMMIT" != "1" ]]; then
	echo "[accept-fixture] stopping before final write. Set MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 to run human Core approval plus signed-client execution."
	echo "local AI client fixture acceptance: ok"
	exit 0
fi

echo "[accept-fixture] approving the pending proposal as a WordPress administrator through Core"
approve_proposal_as_admin "$proposal_id" || fail "Human approval through the Core admin surface failed."

echo "[accept-fixture] executing the human-approved proposal through the signed CLI"
run_cli_json "$execute_out" request "${COMMON_ARGS[@]}" POST "/proposals/$proposal_id/execute" --intent=commit
execute_status="$(json_field "$execute_out" status)" || fail "Execution did not return status."
execute_ability_id="$(json_field "$execute_out" ability_id)" || fail "Execution did not return ability_id."
post_id="$(json_field "$execute_out" post_id)" || fail "Execution did not return post_id."
[[ "$execute_status" == "executed" ]] || fail "Execution did not report executed status: $execute_status"
[[ "$execute_ability_id" == "npcink-abilities-toolkit/create-draft" ]] || fail "Unexpected execution ability id: $execute_ability_id"
assert_json_field_equals "$execute_out" proposal_id "$proposal_id" "Execution"
assert_json_field_equals "$execute_out" execution_mode "single_post" "Execution"
assert_json_field_equals "$execute_out" core_commit_execution "false" "Execution"
assert_json_field_equals "$execute_out" preflight_source "core_commit_preflight" "Execution"
assert_json_field_present "$execute_out" correlation_id "Execution"
assert_json_field_present "$execute_out" adapter_request_id "Execution"
assert_json_field_equals "$execute_out" core_preflight_evidence.authorized "true" "Execution Core preflight evidence"
assert_json_field_equals "$execute_out" core_preflight_evidence.policy_version "core-preflight-v1" "Execution Core preflight evidence"
assert_json_field_equals "$execute_out" core_preflight_evidence.commit_execution "false" "Execution Core preflight evidence"
assert_json_field_equals "$execute_out" core_preflight_evidence.preflight_source "core_commit_preflight" "Execution Core preflight evidence"
assert_json_field_present "$execute_out" core_preflight_evidence.approved_input_hash "Execution Core preflight evidence"
assert_json_field_equals "$execute_out" selected_count "1" "Execution"
assert_json_field_equals "$execute_out" submitted_count "1" "Execution"
assert_json_field_equals "$execute_out" executed_count "1" "Execution"
assert_json_field_equals "$execute_out" failed_count "0" "Execution"
assert_json_field_equals "$execute_out" blocked_count "0" "Execution"
assert_json_field_equals "$execute_out" partial_success "false" "Execution"
assert_json_field_equals "$execute_out" retryable "false" "Execution"
assert_json_field_equals "$execute_out" execution.post_status_after "draft" "Execution"
assert_json_field_equals "$execute_out" execution.result.dry_run "false" "Execution ability result"
assert_json_field_equals "$execute_out" execution_record.status "succeeded" "Execution record"
assert_json_field_equals "$execute_out" execution_record.proposal_id "$proposal_id" "Execution record"
assert_json_field_equals "$execute_out" execution_record.ability_id "npcink-abilities-toolkit/create-draft" "Execution record"
assert_json_field_equals "$execute_out" execution_record.execution_mode "single_post" "Execution record"
assert_json_field_equals "$execute_out" execution_record.execution_surface "wp_abilities_rest" "Execution record"
assert_json_field_equals "$execute_out" execution_record.commit_execution "false" "Execution record"
assert_json_field_equals "$execute_out" execution_record.executed_count "1" "Execution record"
assert_json_field_equals "$execute_out" execution_record.failed_count "0" "Execution record"
assert_json_field_equals "$execute_out" execution_record.core_preflight_evidence.authorized "true" "Execution record Core preflight evidence"
assert_json_field_equals "$execute_out" execution_record.core_preflight_evidence.policy_version "core-preflight-v1" "Execution record Core preflight evidence"
assert_json_field_equals "$execute_out" execution_record.core_preflight_evidence.commit_execution "false" "Execution record Core preflight evidence"
assert_json_field_equals "$execute_out" execution_record.core_execution_record.recorded "true" "Core execution result record"
assert_json_field_equals "$execute_out" execution_record.core_execution_record.status "executed" "Core execution result record"
assert_json_field_equals "$execute_out" execution_record.core_execution_record.proposal_id "$proposal_id" "Core execution result record"
assert_json_field_equals "$execute_out" execution_record.core_execution_record.ability_id "npcink-abilities-toolkit/create-draft" "Core execution result record"
assert_json_field_equals "$execute_out" execution_record.core_execution_record.commit_execution "false" "Core execution result record"

post_status="$(run_wp post get "$post_id" --field=post_status)" || fail "Created draft post was not readable through WP-CLI."
[[ "$post_status" == "draft" ]] || fail "Created post status expected draft, got $post_status."
post_title="$(run_wp post get "$post_id" --field=post_title)" || fail "Created draft post title was not readable through WP-CLI."
[[ "$post_title" == "Adapter CLI fixture draft" ]] || fail "Created post title mismatch: $post_title"

echo "[accept-fixture] verifying duplicate execution is rejected"
set +e
"${CLI[@]}" request "${COMMON_ARGS[@]}" POST "/proposals/$proposal_id/execute" --intent=commit >"$duplicate_out" 2>"$duplicate_err"
duplicate_code=$?
set -e
if [[ "$duplicate_code" -eq 0 ]]; then
	fail "Duplicate execution unexpectedly succeeded."
fi
grep -q 'npcink_openclaw_adapter_execution_already_completed' "$duplicate_out" || fail "Duplicate execution did not return completed execution code."

echo "[accept-fixture] reading executed proposal status through signed CLI"
run_cli_json "$executed_status_out" request "${COMMON_ARGS[@]}" GET "/proposals/$proposal_id"
recorded_post_id="$(json_field "$executed_status_out" adapter_status.execution_record.post_id)" || fail "Executed proposal status did not return stored execution record post_id."
[[ "$recorded_post_id" == "$post_id" ]] || fail "Executed proposal status record post_id mismatch: $recorded_post_id"
recorded_adapter_request_id="$(json_field "$executed_status_out" adapter_status.execution_record.adapter_request_id)" || fail "Executed proposal status did not return stored adapter_request_id."
original_adapter_request_id="$(json_field "$execute_out" adapter_request_id)" || fail "Execution did not return adapter_request_id."
[[ "$recorded_adapter_request_id" == "$original_adapter_request_id" ]] || fail "Executed proposal status did not preserve original adapter_request_id."
assert_json_field_equals "$executed_status_out" adapter_status.execution_record.status "succeeded" "Executed proposal status"
assert_json_field_equals "$executed_status_out" adapter_status.execution_record.core_execution_record.recorded "true" "Executed proposal status Core record"
assert_json_field_equals "$executed_status_out" adapter_status.execution_record.core_execution_record.status "executed" "Executed proposal status Core record"

if [[ "$CLEANUP_POST" == "1" && "$post_id" =~ ^[0-9]+$ && "$post_id" -gt 0 ]]; then
	echo "[accept-fixture] cleaning created draft post $post_id with WP-CLI"
	run_wp post delete "$post_id" --force >/dev/null
fi

echo "local AI client fixture acceptance: ok"
