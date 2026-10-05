# Controller Decomposition Program Status - 2026-10-05

Status: program status record for the thin-adapter decomposition started
from the 2026-10-03 review. Eight domain extractions are merged; one
domain remains, re-estimated and re-scoped.

## Landed

| Domain | File | Lines | PR |
| --- | --- | --- | --- |
| Contract metadata | `Contract_Metadata.php` | 345 | #58 |
| Signing auth / pairing | `Signing_Auth.php` | 880 | #63 |
| Execution records / locks | `Execution_Records.php` | 382 | #67 |
| Upstream transport | `Upstream_Dispatch.php` | 387 | #70 |
| Dependency introspection | `Dependency_Status.php` | 440 | #71 |
| Preflight handoffs / bindings | `Preflight_Handoffs.php` | 588 | #73 |
| Proposal review / feedback | `Proposal_Review.php` | 591 | #74 |
| Read governance / redaction | `Read_Governance.php` | 375 | #76 |

Controller: 8630 -> 5701 lines (-34%). The contracts harness now runs
location-independent needles under a completion-marker guard (#74), and
the platform standards carry the guard clause and the split methodology.

## Remaining, Re-estimated

The final "execution orchestration" domain measures **~2236 lines** across
36 methods — media-optimization readiness and status projection live in
it alongside the executor. It should land as its own mini-program of two
or three PRs, not one extraction:

1. Media optimization readiness and artifact projection (~600 lines:
   `media_optimization_readiness` and its artifact/expiry/review/repair
   helpers, `get_proposal_media_optimization_readiness`).
2. Proposal status projection (~250 lines: `augment_proposal_status_response`,
   `proposal_derived_execution_status`, `latest_preflight_audit_event`).
3. The executor core (~1300 lines: execute routes, the locked executor,
   action normalization, implementation posture evidence, record stores,
   batch summaries).

After (3), Controller is route handlers plus thin orchestration (~4000
lines) and the program closes with a final closeout. The README pin
migration and slimming follow the program close.

## Carry-over Notes

- Pre-existing semantics parked from the #73 review remain conditional:
  strict expiry parsing for Core contexts, expiry checks in the status
  projection, the unlocked handoff option write.
- Sibling-repo adoption of Static Analysis Standard v1 (with the marker
  guard) is pending for governance-core, abilities-toolkit, cloud-addon.
