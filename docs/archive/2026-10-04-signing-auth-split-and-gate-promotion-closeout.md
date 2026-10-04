# Signing Auth Split And Gate Promotion Closeout - 2026-10-04

Status: closeout record for the second day of the repository health
program (2026-10-03 review). Captures what shipped, the tooling lessons
from a security-domain extraction, and the refined follow-up sequencing.

## What Shipped

| Milestone | Delivery |
| --- | --- |
| Static analysis gates promoted from advisory to required (zero-finding streak precondition met) | PR #62 |
| `Signing_Auth` extraction: 880 lines of Ed25519 verification, nonce replay protection, pairing storage, client keys, and pairing rate limits out of `Controller` (8630 -> 7781 lines) | PR #63 |
| Platform Static Analysis Standard v1 in `npcink-workflow-toolbox` (reference setup, promotion rule, lessons, enrollment table) | toolbox PR #181 |

## Development Lessons

1. **Write-once scripts for mechanical surgery.** The extraction failed
   invisibly twice: an assert fired after the file write in one run, and a
   later patch script did two sequential `write()` calls where the second
   stale-state write silently reverted the first fix. Symptoms looked like
   impossible states (edits asserted OK but absent from disk). The fix is
   structural: one read, one transform, asserts inside, one write — and a
   `php -l` pass on an unchanged file is not evidence the edit landed.
2. **Never let a docblock regex cross a method boundary.** The greedy
   `(\t/\*\*.*?\*/\n)?` prefix on method-extraction patterns let a match
   start at an unrelated docblock and swallow hundreds of lines. The safe
   pattern is: locate the method, `rfind` the preceding docblock, and
   verify the docblock's closing `*/\n` is exactly adjacent to the method
   start. (The platform's Provider Split Refactor Standard already says
   this class of problem is why needle inventories precede moves.)
3. **Shell interpolation poisons grep-based verification.** `grep "\$this->"`
   in double quotes expands `$this` to empty and silently matches nothing,
   hiding stale references from the operator. Quote patterns with single
   quotes or check with a compiler-grade tool (PHPStan found every real
   stale reference within seconds).
4. **Network split behavior is now a documented pattern.** `github.com`
   git-over-HTTPS can stay blocked for tens of minutes while
   `api.github.com` answers. Recovery set: a repo-scoped
   `http.https://github.com.proxy` git config when a local VPN proxy
   exists, REST API for branch deletion and PR state, and patience loops
   for fetches. Recorded in the toolbox AI review standard already; the
   proxy scoping trick is new.

## Follow-up Queue (refined)

- **Before any further Controller split**, apply Provider Split Refactor
  Standard step 1 to `tests/run.php`: replace the per-file
  `maa_adapter_read()` assertion sources with sorted-glob directory
  aggregation, then replay the negative needles. The 2026-10-04 Signing_Auth
  split re-anchored every window pin by hand; portability removes that
  cost class for the remaining domains.
- Remaining domains, resequenced by the dependency inventory taken on
  2026-10-04: proposal flow (1229 lines) reads execution records
  (`execution_record_for_proposal`, `public_execution_record`) and calls
  `dispatch_upstream`, `emit_operation_event`, and
  `proposal_caller_context` directly — so an upstream-transport seam
  (injected callable, house pattern) plus the execution-record storage
  domain should land first, then proposal flow, then the dependency
  introspection trio (`rest_route_available` seam).
- README slimming is blocked by the pin contract: 75+ required strings and
  all 29 execution-profile ids are pinned into `README.md` by
  `tests/run.php`. Slim after those pins migrate to the docs that own the
  content (same P2 migration rule).
- Toolbox standard enrollment for `npcink-governance-core`,
  `npcink-abilities-toolkit`, and `npcink-cloud-addon` per the new
  platform standard.
