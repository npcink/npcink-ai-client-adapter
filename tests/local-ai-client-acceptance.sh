#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NODE_BIN="${NODE_BIN:-node}"
PROFILE="${MAA_ADAPTER_ACCEPTANCE_PROFILE:-local}"
PUBLIC_READ_ABILITY="${MAA_ADAPTER_ACCEPTANCE_PUBLIC_READ_ABILITY:-npcink-abilities-toolkit/build-content-inventory-fix-plan}"
# Sensitive reads such as npcink-abilities-toolkit/site-info require a Core grant.
SENSITIVE_READ_ABILITY="${MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_ABILITY:-}"
SENSITIVE_READ_INPUT="${MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_INPUT:-}"
SENSITIVE_READ_REQUEST_ID="${MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_REQUEST_ID:-}"
PREFLIGHT_PROPOSAL_ID="${MAA_ADAPTER_ACCEPTANCE_PREFLIGHT_PROPOSAL_ID:-}"
COMMIT_PROPOSAL_ID="${MAA_ADAPTER_ACCEPTANCE_COMMIT_PROPOSAL_ID:-}"

CLI=( "$NODE_BIN" "$ROOT_DIR/packages/adapter-cli/bin/npcink-openclaw-adapter.mjs" )
COMMON_ARGS=( "--profile=$PROFILE" )
if [[ "${MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS:-}" == "1" ]]; then
	COMMON_ARGS+=( "--insecure-local-tls" )
fi

tmp_dir="$(mktemp -d)"
cleanup() {
	rm -rf "$tmp_dir"
}
trap cleanup EXIT

public_input="$tmp_dir/public-read-input.json"
public_output="$tmp_dir/public-read-output.json"
capabilities_output="$tmp_dir/capabilities-output.json"
health_output="$tmp_dir/health.json"
manifest_output="$tmp_dir/manifest.json"
help_output="$tmp_dir/help.json"
printf '{}\n' > "$public_input"

echo "[accept] checking CLI syntax"
npm --prefix "$ROOT_DIR/packages/adapter-cli" run check

echo "[accept] checking signed Adapter status for profile: $PROFILE"
"${CLI[@]}" status "${COMMON_ARGS[@]}"

echo "[accept] reading /health"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /health >"$health_output"

echo "[accept] reading /connection/manifest"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /connection/manifest >"$manifest_output"

echo "[accept] reading /help"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /help >"$help_output"

"$NODE_BIN" - "$health_output" "$manifest_output" "$help_output" <<'NODE'
const fs = require('node:fs');
for (const file of process.argv.slice(2)) {
  const data = JSON.parse(fs.readFileSync(file, 'utf8'));
  const contract = data.contract;
  const policy = data.client_policy;
  if (contract?.schema_version !== 'npcink_openclaw_adapter_contract.v1' ||
      contract.core_proxy_execute !== false || contract.commit_execution !== false ||
      policy?.client_posture !== 'adapter_only_fail_closed' ||
      policy.write_flow?.signed_client_self_approval !== 'forbidden') {
    console.error('[accept] Adapter contract or fail-closed client policy is invalid.');
    process.exit(1);
  }
}
const health = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (health.dependencies_ready !== true || health.dependency_contracts_ready !== true) {
  console.error('[accept] Adapter dependencies or dependency contracts are not ready.');
  process.exit(1);
}
console.log('[accept] health/help/manifest contracts, dependencies and governance boundary: ok');
NODE

echo "[accept] reading /capabilities"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /capabilities >"$capabilities_output"
if ! "$NODE_BIN" - "$capabilities_output" "$PUBLIC_READ_ABILITY" <<'NODE'
const fs = require('node:fs');
const [file, expectedAbility] = process.argv.slice(2);
const data = JSON.parse(fs.readFileSync(file, 'utf8'));
const items = Array.isArray(data.items) ? data.items : [];
const found = items.some((item) => item && item.ability_id === expectedAbility);
console.log(`[accept] Core capability catalog: count=${items.length}, expected=${expectedAbility}, found=${found ? 'yes' : 'no'}`);
if (!found) {
  console.error('[accept] The signed HTTP runtime does not expose the expected ability through Core.');
  console.error('[accept] Check Toolkit package settings and REST lifecycle registration; compare the CLI and HTTP catalogs.');
  console.error('[accept] If the ability is intentionally unavailable on this site, set MAA_ADAPTER_ACCEPTANCE_PUBLIC_READ_ABILITY to a ready Core ability id.');
  process.exit(1);
}
NODE
then

  exit 1
fi

echo "[accept] probing default read ability: $PUBLIC_READ_ABILITY"
if "${CLI[@]}" read-ability "${COMMON_ARGS[@]}" --ability-id="$PUBLIC_READ_ABILITY" --input-file="$public_input" >"$public_output" 2>&1; then
	cat "$public_output"
else
	public_status=$?
	cat "$public_output"
	if ! grep -q 'npcink_openclaw_adapter_core_read_authorization_required' "$public_output"; then
		exit "$public_status"
	fi
	echo "[accept] default read ability failed closed pending a Core read grant"
fi

if [[ -n "$SENSITIVE_READ_ABILITY" && -n "$SENSITIVE_READ_INPUT" ]]; then
	echo "[accept] creating sensitive read request: $SENSITIVE_READ_ABILITY"
	"${CLI[@]}" read-request create "${COMMON_ARGS[@]}" \
		--ability-id="$SENSITIVE_READ_ABILITY" \
		--input-file="$SENSITIVE_READ_INPUT" \
		--purpose="${MAA_ADAPTER_ACCEPTANCE_SENSITIVE_PURPOSE:-Local AI client acceptance}" \
		--data-classes="${MAA_ADAPTER_ACCEPTANCE_SENSITIVE_DATA_CLASSES:-diagnostics,logs}" \
		--redaction-level="${MAA_ADAPTER_ACCEPTANCE_REDACTION_LEVEL:-strict}"
fi

if [[ -n "$SENSITIVE_READ_REQUEST_ID" && -n "$SENSITIVE_READ_ABILITY" && -n "$SENSITIVE_READ_INPUT" ]]; then
	echo "[accept] checking sensitive read request status: $SENSITIVE_READ_REQUEST_ID"
	"${CLI[@]}" read-request status "${COMMON_ARGS[@]}" "$SENSITIVE_READ_REQUEST_ID"
	echo "[accept] executing sensitive read with approved request id"
	"${CLI[@]}" read-ability "${COMMON_ARGS[@]}" \
		--ability-id="$SENSITIVE_READ_ABILITY" \
		--input-file="$SENSITIVE_READ_INPUT" \
		--read-request-id="$SENSITIVE_READ_REQUEST_ID"
fi

if [[ -n "$PREFLIGHT_PROPOSAL_ID" ]]; then
	echo "[accept] running proposal commit-preflight: $PREFLIGHT_PROPOSAL_ID"
	"${CLI[@]}" request "${COMMON_ARGS[@]}" POST "/proposals/$PREFLIGHT_PROPOSAL_ID/commit-preflight" --intent=preflight
fi

if [[ -n "$COMMIT_PROPOSAL_ID" ]]; then
	if [[ "${MAA_ADAPTER_ACCEPTANCE_ALLOW_COMMIT:-}" != "1" ]]; then
		echo "Set MAA_ADAPTER_ACCEPTANCE_ALLOW_COMMIT=1 before final execution." >&2
		exit 2
	fi
	if [[ "${MAA_ADAPTER_ACCEPTANCE_HUMAN_APPROVED:-}" != "1" ]]; then
		echo "Approve proposal $COMMIT_PROPOSAL_ID in the Npcink Governance Core admin first, then set MAA_ADAPTER_ACCEPTANCE_HUMAN_APPROVED=1. Signed clients cannot approve their own proposals." >&2
		exit 2
	fi
	echo "[accept] executing human-approved proposal as the signed client: $COMMIT_PROPOSAL_ID"
	"${CLI[@]}" request "${COMMON_ARGS[@]}" POST "/proposals/$COMMIT_PROPOSAL_ID/execute" --intent=commit
fi

echo "[accept] local AI client acceptance completed"
