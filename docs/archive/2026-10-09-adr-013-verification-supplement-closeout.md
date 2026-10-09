# ADR-013 Verification Supplement Closeout - 2026-10-09

Status: closeout record for the verification-integrity arc's adapter
side. Closes the loop tracked by adapter issue #93 and governance-core
issue #125 (both closed on acceptance evidence): in-transaction batch
objects now verify through Core-recorded evidence instead of degrading.

## Scope

- Repository `npcink-ai-client-adapter`; the arc landed through PR #94
  (execution-attached reads, ADR-012 consumer side, merged 2026-10-08)
  and PR #95 (the ADR-013 supplement pass, squash-merged 2026-10-09 as
  `9b30954`).
- Focused responsibility: one additive post-execution
  verification-supplement pass. Actions whose block readback could not
  be verified get a provisional-record second chance with Core-minted,
  result-bound grants.
- Non-goals and unchanged contracts: no new routes, scopes, ability
  definitions, or authorization logic; Core stays the authorization
  truth source; first-pass readbacks and all prior behavior unchanged;
  the pass stays fail-open with observable events.

## Changes

| Milestone | Delivery |
| --- | --- |
| Execution-attached verification reads (ADR-012 consumer side) | PR #94 |
| ADR-013 supplement pass: provisional record-execution dispatch, result-bound grant seeding, selective re-runs, outputs/verification projection alignment | PR #95 (`9b30954`) |
| Drained-key grant seeding pinned by a behavior probe (`tests/verification-supplement-behavior.php`) | PR #95 |
| Companion Core side: ADR-013 accepted, provisional-record minting + hardening | gc #135/#136/#138/#139 |

The review loop ran thirteen OCR rounds on PR #95 across two sessions.
Every finding is triaged on the PR body's `## AI Review Triage` section;
the standing verdicts worth keeping in mind:

1. **The supplement deliberately sends the ORIGINAL (unresolved) action
   input.** An output-reference input is precisely what proves reference
   addressing to Core; gc #139 denies statically addressed inputs at the
   result-bound mint, so resolved ids would misclassify in-transaction
   objects and deny them the mint.
2. **The provisional record is lifecycle-safe.** gc
   `Proposal_Service::record_provisional_execution` (lines 538-607)
   requires the proposal to still be approved, records only an audit
   event plus the read mints, and never transitions lifecycle state; the
   definitive record path owns transitions.
3. **The queue-collision concern was a misread.** Grant seeding appends
   per queue key outside the isset initialization; `array_shift`
   consumption leaves drained keys in place and re-minted grants still
   land on them - now pinned by the probe test.

## Verification

- Source gates green on the final head `a7427af` and on master after
  the squash merge: `composer validate --no-check-publish`,
  `composer test:all`, `composer lint:standards`,
  `composer analyse:php`, `composer check:wporg` (CI: PHP contracts,
  PR body contract, and the advisory static-analysis job all green).
- Grant-mode smoke (recorded on the PR #95 body, LocalWP magick-ai with
  gc #138+#139 active): both acceptance assertions green -
  `pattern page execution verifies post-block readback` and
  `article block execution verifies post-block readback`; 1235 ok, the
  furthest this grant-mode run has ever reached.
- CI result for the final candidate revision: OCR round 13 delivered
  one low confirm-only finding (triaged); merge state CLEAN.

## Publication

- PR #95 squash-merged as `9b30954` on 2026-10-09. No release impact:
  the pass is additive and fail-open; no version bump shipped with it.

## Cleanup

- Remote `codex/adr-013-verification-supplement` deleted after merge;
  local branch deleted; the main checkout is back on a fast-forwarded
  `master`.
- Observed end state (2026-10-09): `origin` carries `master` only. The
  parallel-era `adapt/core-ux-contract` branch also disappeared from the
  remote during the day, outside this session's actions.

## Residual

- One smoke assertion - `adapter health reports environment-only Core
  app token source` - fails in never-previously-reached smoke territory
  (all earlier runs aborted before it). It concerns app-token source
  plumbing, not verification reads, and stays flagged for the
  maintainer's next pass.
- The review loop's recurring defect classes and their verdicts live on
  the PR #95 triage section; re-read them before touching the supplement
  again.
