#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
export FLAGGED_TEST_ROOT="$work"
export FLAGGED_TEST_REAL_NODE="$(command -v node)"
cat >"$work/wp" <<'SH'
#!/usr/bin/env bash
echo wp-info >>"$FLAGGED_TEST_ROOT/calls"
SH
cat >"$work/php" <<'SH'
#!/usr/bin/env bash
echo "$MAA_FLAGGED_FIXTURE_ACTION" >>"$FLAGGED_TEST_ROOT/calls"
case "$MAA_FLAGGED_FIXTURE_ACTION" in
 identity) printf '{"site_url":"https://fixture.invalid"}\n';;
 prepare)
  printf '{"run":"test"}\n' >"$MAA_FLAGGED_FIXTURE_STATE"
  printf '{}\n' >"$(dirname "$MAA_FLAGGED_FIXTURE_STATE")/governed_with_backup.json"
  ;;
 cleanup)
  printf '%s\n' "$MAA_FLAGGED_FIXTURE_STATE" >"$FLAGGED_TEST_ROOT/state-path"
  [[ "${FLAGGED_TEST_CLEANUP_FAIL:-0}" != 1 ]]
  ;;
esac
SH
cat >"$work/node" <<'SH'
#!/usr/bin/env bash
if [[ "$1" != */npcink-openclaw-adapter.mjs ]]; then exec "$FLAGGED_TEST_REAL_NODE" "$@"; fi
case "$*" in
 *'GET /connection/manifest'*)
  if [[ "${FLAGGED_TEST_WRONG_SITE:-0}" == 1 ]]; then
   printf '{"site":{"site_url":"https://another-site.invalid"}}\n'
  else printf '{"site":{"site_url":"https://fixture.invalid"}}\n'; fi
  ;;
 *'GET /capabilities'*) printf '{"items":[{"ability_id":"npcink-abilities-toolkit/delete-attachment"},{"ability_id":"npcink-abilities-toolkit/build-flagged-media-removal-plan"},{"ability_id":"npcink-abilities-toolkit/restore-media-backup"}]}\n';;
 *'POST /proposals/from-plan'*) printf '{"proposals":[{"proposal_id":"fixture-proposal","status":"pending"}]}\n';;
 *'/approve-and-execute'*) printf '{"code":"npcink_openclaw_adapter_approve_requires_admin_session"}\n'; exit 1;;
 *'/execute'*)
  if ! grep -qx approve "$FLAGGED_TEST_ROOT/calls"; then
   printf '{"code":"npcink_governance_core_proposal_not_approved"}\n'; exit 1
  fi
  # A nominal HTTP success with missing Core evidence must fail acceptance.
  printf '{"status":"executed"}\n'
  ;;
 *) echo 'unexpected mocked CLI request' >&2; exit 1;;
esac
SH
chmod +x "$work/wp" "$work/php" "$work/node"
run_fixture() {
 WP_CLI="$work/wp" WP_CLI_PHP="$work/php" NODE_BIN="$work/node" \
 bash "$ROOT_DIR/tests/local-ai-client-flagged-media-fixture.sh" >"$work/output" 2>&1
}
if MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=0 run_fixture; then echo 'Missing commit opt-in was accepted.' >&2; exit 1; fi
[[ ! -e "$work/calls" ]] || { echo 'Opt-in refusal invoked site tooling.' >&2; exit 1; }
if FLAGGED_TEST_WRONG_SITE=1 MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 run_fixture; then echo 'Different CLI and WP sites were accepted.' >&2; exit 1; fi
grep -q 'point to different sites' "$work/output"
! grep -qx prepare "$work/calls" || { echo 'Site mismatch created fixtures.' >&2; exit 1; }
rm "$work/calls"
if MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 run_fixture; then echo 'Missing Core evidence was accepted.' >&2; exit 1; fi
grep -q 'expected Core preflight' "$work/output"
grep -qx cleanup "$work/calls"
state="$(cat "$work/state-path")"
[[ ! -e "$state" ]] || { echo 'Successful cleanup left temporary state.' >&2; exit 1; }
rm "$work/calls"
if FLAGGED_TEST_CLEANUP_FAIL=1 MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 run_fixture; then echo 'Cleanup failure was accepted.' >&2; exit 1; fi
grep -q 'Fixture cleanup failed; retain recovery state' "$work/output"
state="$(cat "$work/state-path")"
[[ -f "$state" ]] || { echo 'Cleanup failure lost recovery state.' >&2; exit 1; }
rm -rf "$(dirname "$state")"
echo 'Flagged-media HTTPS acceptance opt-in, site mismatch, evidence refusal and cleanup failure handling: ok'
