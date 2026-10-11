# Positioning Audit & Consumer Doc Sync Closeout - 2026-10-11

Status: closeout record for the 2026-10-11 systematic positioning audit
(docs versus code) and the consumer-boundary documentation sync it produced.
The audit found the thin-adapter positioning substantively intact with zero
boundary violations; every gap was consumer documentation drifting behind
the shipped 0.4.1 route surface. All fixes landed through PR #100
(squash-merged as `ac7d777`, OpenCodeReview delivered with zero findings).

## Audit Method (what was checked, reuse it)

The audit compared every positioning claim in `AGENTS.md`, `README.md`,
`docs/architecture/adapter-boundary.md`, `docs/openclaw-adapter-contract.md`,
and `readme.txt` against the implementation:

1. **Route surface**: every `register_rest_route` call enumerated and diffed
   against the documented route list (19 registrations, 22 documented routes,
   one-to-one match; no provider/model, `/cloud/*`, recipe, or media
   derivative facade routes exist).
2. **Boundary constants**: `core_proxy_execute=false` and
   `commit_execution=false` traced to `Contract_Metadata` and the `/health`
   emitter; `Dependency_Status` fails closed unless Core reports both false.
3. **Execution allowlist integrity**: `Execution_Profile_Registry` (26
   entries) diffed against every prose allowlist; the enforcement gate
   (`Execution_Input_Validator::validate_execute_ability`, 403 fail-closed)
   and the single-`apply_filters` rule (Toolkit host policy only) verified.
4. **Forbidden-ownership greps**: `dbDelta`/`CREATE TABLE`, cron scheduling,
   `wp_remote_*` (zero external HTTP; upstream dispatch is internal
   `WP_REST_Request` only), ability registration hooks, Cloud seams
   (`function_exists` probes only).
5. **Persistence and lifecycle**: `uninstall.php` scope, admin page credential
   handling (core Application Passwords API, hash-only), observability payload
   allowlist, AI Request Logs identifier-only passthrough.
6. **Gates**: `composer test:all`, `analyse:php`, `lint:standards`,
   `check:wporg`, and the adapter-cli check/test suites run against the tree.

This method is cheap (minutes) and caught all three drift classes below; run
it before any consumer-facing contract release.

## What Shipped

| Fix | Detail | PR |
| --- | --- | --- |
| Removed stale `approval_proxy_enabled` guidance | README integration checklist, CLI status guidance, Persistence Boundary section, contract doc stub section, CLI MCP health tool description | #100 |
| Stub routes documented truthfully | approve/reject routes documented as unregistered (`rest_no_route` 404) instead of the historical 403 + `npcink_openclaw_adapter_approval_proxy_disabled` body | #100 |
| Execution allowlists completed | Write-flow step 9 and OpenClaw-flow step 7 now list all 26 registry profiles (added `adopt-article-audio`, `upload-media-from-url`, `set-post-featured-image`) | #100 |
| Planning list completed | README plan-to-proposal list covers all 21 `Supported_Plan_Abilities` entries (added `build-media-optimization-plan`, `build-media-alt-apply-plan`, `build-content-metadata-apply-plan`, `build-article-audio-adoption-plan`, `build-article-block-plan`) | #100 |
| Permission summary exception noted | README and contract "all routes require `manage_options`" now state the deliberate unauthenticated device-pairing start/poll exception | #100 |

## Development Lessons

1. **Compliance-driven surface removals must sweep every consumer surface in
   the same change.** The WordPress.org review rules removed the
   `approval_proxy_enabled`/stub strings from the packaged plugin
   (`9138fa9`, 2026-06-14) and `tests/run.php` pinned their absence in
   Controller code — but the README checklist, contract doc example, and CLI
   help text kept advertising them for four months. When a rule forces an
   observable route/health field out, grep the entire repository (docs, CLI
   package, tests) for the removed contract strings before closing the
   change; the packaged surface and the documented surface drift
   independently.
2. **Prose allowlists drift; diff them against the code registry
   mechanically.** Three README lists had silently diverged from
   `Execution_Profile_Registry` (26) and `Supported_Plan_Abilities` (21).
   Extracting registry keys and diffing them against anchor-located doc
   ranges caught every gap in seconds and verified the fix at 26/26 and
   21/21. Reuse `/tmp`-style throwaway diff scripts; do not eyeball lists.
3. **Documentation is pinned by tests, so doc edits are contract edits.**
   `tests/run.php` asserts required strings in README and the contract doc
   (section headings, `unified_action_route`, route strings) and forbidden
   strings elsewhere. A doc rewrite must run `composer test:all` first and
   either preserve the pinned anchors or move them deliberately in the same
   change. Keeping the pinned strings (`Approval Disabled Stub Contract`
   heading, the two route lines) while correcting the semantics inside the
   section let the sync land without touching tests.
4. **Describe removed routes by their real failure mode.** Documenting an
   unregistered route as "returns 403 with code X" misleads integrators into
   matching an error body that never arrives. The truthful contract is the
   WordPress default `rest_no_route` 404; state that, then point at the
   fields that DO carry the boundary posture (`/health`
   `core_proxy_execute=false`, `commit_execution=false`,
   `approval_surface=npcink_governance_core_admin`).
5. **Squash merges hide branch ancestry from `--merged` checks.** After the
   protected squash merge, `git branch --no-merged origin/master` still lists
   the delivered topic branch because the commit SHAs differ. Confirm merge
   state through the pull request (`gh pr view --json state`), not ancestry,
   before any branch cleanup.

## Remaining Follow-ups

- None required. The positioning verdict (thin channel layer, Core as
  governance truth, explicit post-Core execution profiles, no
  workflow/Cloud/provider runtime ownership) matches the implementation.
- Optional future hardening, on demand only: extend the static contract test
  with a drift check that greps consumer docs for `/health` field names that
  no longer appear in `Contract_Metadata`/Controller output, generalizing
  lesson 1 from convention to gate.
