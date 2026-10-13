#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "${MAA_ADAPTER_FIXTURE_ALLOW_COMMIT:-}" == "1" ]] || {
	echo 'Set MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 to delete only newly created disposable attachments.' >&2
	exit 2
}
WP_PATH="${WP_PATH:-/Users/muze/Local Sites/magick-ai/app/public}"
WP_CLI_BIN="${WP_CLI:-$(command -v wp)}"
WP_CLI_PHP="${WP_CLI_PHP:-$(command -v php)}"
NODE_BIN="${NODE_BIN:-node}"
SOCKET="${WP_CLI_MYSQL_SOCKET:-${WP_DB_SOCKET:-}}"
CLI=( "$NODE_BIN" "$ROOT_DIR/packages/adapter-cli/bin/npcink-openclaw-adapter.mjs" request "--profile=${MAA_ADAPTER_ACCEPTANCE_PROFILE:-local}" )
if [[ "${MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS:-}" == "1" ]]; then CLI+=( --insecure-local-tls ); fi
command -v "$WP_CLI_BIN"
"$WP_CLI_BIN" --info
tmp_dir="$(mktemp -d)"
export MAA_FLAGGED_FIXTURE_STATE="$tmp_dir/state.json"
helper="$ROOT_DIR/tests/flagged-media-https-fixture.php"
run_wp() {
	local php_args=( -d display_errors=0 -d error_reporting=8191 )
	if [[ -n "$SOCKET" ]]; then php_args+=( -d "mysqli.default_socket=$SOCKET" ); fi
	MAA_FLAGGED_FIXTURE_ACTION="$1" "$WP_CLI_PHP" "${php_args[@]}" "$WP_CLI_BIN" --path="$WP_PATH" eval-file "$helper"
}
cleanup() {
	local status=$?
	trap - EXIT
	if [[ -f "$MAA_FLAGGED_FIXTURE_STATE" ]] && ! run_wp cleanup; then
		echo "[fail] Fixture cleanup failed; retain recovery state: $MAA_FLAGGED_FIXTURE_STATE" >&2
		exit 1
	fi
	rm -rf "$tmp_dir"
	exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

field() {
	"$NODE_BIN" - "$1" "$2" <<'NODE'
const fs = require('node:fs');
const [file, path] = process.argv.slice(2);
const value = path.split('.').reduce((v, k) => v?.[k], JSON.parse(fs.readFileSync(file, 'utf8')));
if (value === undefined || value === null || value === '') process.exit(1);
console.log(value);
NODE
}
request() {
	local output="$1"; shift
	if ! "${CLI[@]}" "$@" >"$output" 2>"$output.err"; then
		echo "[fail] Signed HTTPS request failed; response retained until cleanup: $output" >&2
		cat "$output" >&2
		return 1
	fi
}
refuse() {
	local output="$1" expected="$2"; shift 2
	if "${CLI[@]}" "$@" >"$output" 2>"$output.err"; then
		echo '[fail] Expected signed request refusal.' >&2; return 1
	fi
	"$NODE_BIN" - "$output" "$expected" <<'NODE'
const fs = require('node:fs');
const [file, expected] = process.argv.slice(2);
const data = JSON.parse(fs.readFileSync(file, 'utf8'));
if (data.code !== expected) throw Error(`Expected ${expected}; received ${data.code}`);
NODE
}
verify_execution() {
	"$NODE_BIN" - "$1" "$2" "$3" <<'NODE'
const fs = require('node:fs');
const [file, proposal, ability] = process.argv.slice(2);
const d = JSON.parse(fs.readFileSync(file, 'utf8'));
if (d.status !== 'executed' || d.proposal_id !== proposal || d.ability_id !== ability ||
    d.core_commit_execution !== false || d.preflight_source !== 'core_commit_preflight' ||
    d.core_preflight_evidence?.authorized !== true || d.core_preflight_evidence.commit_execution !== false ||
    !d.core_preflight_evidence.approved_input_hash || !d.correlation_id || !d.adapter_request_id ||
    d.selected_count !== 1 || d.executed_count !== 1 || d.failed_count !== 0 || d.blocked_count !== 0 ||
    d.execution_record?.status !== 'succeeded' ||
    d.execution_record.core_execution_record?.recorded !== true ||
    d.execution_record.core_execution_record.status !== 'executed' || d.result?.dry_run !== false) {
  throw Error('Signed execution lacks the expected Core preflight, result record or committed result.');
}
if (ability.endsWith('/delete-attachment') && d.result.deleted !== true) throw Error('Deletion result is incomplete.');
if (ability.endsWith('/restore-media-backup') && d.result.restored !== true) throw Error('Restore result is incomplete.');
NODE
}

echo '[flagged-https] matching CLI and WP-CLI sites before creating attachments'
request "$tmp_dir/manifest.json" GET /connection/manifest
run_wp identity >"$tmp_dir/site.json"
"$NODE_BIN" - "$tmp_dir/manifest.json" "$tmp_dir/site.json" <<'NODE'
const fs = require('node:fs');
const [manifest, site] = process.argv.slice(2).map(f => JSON.parse(fs.readFileSync(f, 'utf8')));
const canonical = value => new URL(value).href.replace(/\/$/, '');
if (canonical(manifest.site?.site_url) !== canonical(site.site_url)) {
  throw Error('CLI profile and WP_PATH point to different sites. No attachments were created.');
}
NODE
echo '[flagged-https] checking HTTP catalog before creating attachments'
request "$tmp_dir/catalog.json" GET /capabilities
"$NODE_BIN" - "$tmp_dir/catalog.json" <<'NODE'
const fs = require('node:fs');
const catalog = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
for (const name of ['delete-attachment', 'build-flagged-media-removal-plan', 'restore-media-backup']) {
  if (!catalog.items?.some(item => item.ability_id === `npcink-abilities-toolkit/${name}`)) {
    throw Error(`HTTP Core catalog is missing ${name}; finish owner dependency integration first.`);
  }
}
NODE
run_wp prepare

for branch in governed_with_backup illegal_content_no_backup; do
	export MAA_FLAGGED_FIXTURE_BRANCH="$branch"
	request "$tmp_dir/proposal.json" POST /proposals/from-plan --body-file="$tmp_dir/$branch.json"
	proposal_id="$(field "$tmp_dir/proposal.json" proposals.0.proposal_id)"
	[[ "$(field "$tmp_dir/proposal.json" proposals.0.status)" == pending ]] || { echo '[fail] Flagged deletion was not held for manual approval.' >&2; exit 1; }
	export MAA_FLAGGED_FIXTURE_PROPOSAL="$proposal_id"
	refuse "$tmp_dir/self.json" npcink_openclaw_adapter_approve_requires_admin_session POST "/proposals/$proposal_id/approve-and-execute" --intent=commit
	# Core must deny execution before approval; do not accept an unrelated HTTP failure.
	refuse "$tmp_dir/pending.json" npcink_governance_core_proposal_not_approved POST "/proposals/$proposal_id/execute" --intent=commit
	run_wp intact
	echo "[flagged-https] separate administrator test-context approval: $branch"
	run_wp approve
	request "$tmp_dir/execute.json" POST "/proposals/$proposal_id/execute" --intent=commit
	verify_execution "$tmp_dir/execute.json" "$proposal_id" npcink-abilities-toolkit/delete-attachment
	run_wp deleted
	refuse "$tmp_dir/duplicate.json" npcink_openclaw_adapter_execution_already_completed POST "/proposals/$proposal_id/execute" --intent=commit
	request "$tmp_dir/status.json" GET "/proposals/$proposal_id"
	"$NODE_BIN" - "$tmp_dir/status.json" "$tmp_dir/execute.json" <<'NODE'
const fs = require('node:fs');
const [status, execution] = process.argv.slice(2).map(f => JSON.parse(fs.readFileSync(f, 'utf8')));
const record = status.adapter_status?.execution_record;
if (record?.status !== 'succeeded' || record.adapter_request_id !== execution.adapter_request_id ||
    record.core_execution_record?.recorded !== true) throw Error('Stored deletion evidence failed readback.');
NODE
	if [[ "$branch" == governed_with_backup ]]; then
		"$NODE_BIN" - "$MAA_FLAGGED_FIXTURE_STATE" "$tmp_dir/restore.json" <<'NODE'
const fs = require('node:fs');
const [stateFile, output] = process.argv.slice(2);
const entry = JSON.parse(fs.readFileSync(stateFile, 'utf8')).entries.governed_with_backup;
fs.writeFileSync(output, JSON.stringify({ability_id:'npcink-abilities-toolkit/restore-media-backup',
  title:'Restore disposable HTTPS fixture', summary:'Acceptance of retained fixture backup',
  input:{attachment_id:entry.attachment_id, backup_id:entry.backup_id, dry_run:true, commit:false},
  preview:{action:'restore_media_backup'}}));
NODE
		request "$tmp_dir/restore-proposal.json" POST /proposals --body-file="$tmp_dir/restore.json"
		restore_id="$(field "$tmp_dir/restore-proposal.json" proposal_id)"
		MAA_FLAGGED_FIXTURE_PROPOSAL="$restore_id" run_wp approve
		request "$tmp_dir/restore-execute.json" POST "/proposals/$restore_id/execute" --intent=commit
		verify_execution "$tmp_dir/restore-execute.json" "$restore_id" npcink-abilities-toolkit/restore-media-backup
		run_wp restored
	fi
done
run_wp cleanup
rm -f "$MAA_FLAGGED_FIXTURE_STATE"
echo 'real HTTPS flagged-media fixture acceptance: ok (both branches, backup restore and cleanup)'
