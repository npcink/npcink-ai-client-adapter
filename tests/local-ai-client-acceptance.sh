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
printf '{}\n' > "$public_input"

echo "[accept] checking CLI syntax"
npm --prefix "$ROOT_DIR/packages/adapter-cli" run check

echo "[accept] checking signed Adapter status for profile: $PROFILE"
"${CLI[@]}" status "${COMMON_ARGS[@]}"

echo "[accept] reading /health"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /health

echo "[accept] reading /connection/manifest"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /connection/manifest

echo "[accept] reading /help"
"${CLI[@]}" request "${COMMON_ARGS[@]}" GET /help

echo "[accept] running public read ability: $PUBLIC_READ_ABILITY"
"${CLI[@]}" read-ability "${COMMON_ARGS[@]}" --ability-id="$PUBLIC_READ_ABILITY" --input-file="$public_input"

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
		echo "Set MAA_ADAPTER_ACCEPTANCE_ALLOW_COMMIT=1 before approve-and-execute." >&2
		exit 2
	fi
	echo "[accept] running approve-and-execute: $COMMIT_PROPOSAL_ID"
	"${CLI[@]}" request "${COMMON_ARGS[@]}" POST "/proposals/$COMMIT_PROPOSAL_ID/approve-and-execute" --intent=commit
fi

echo "[accept] local AI client acceptance completed"
