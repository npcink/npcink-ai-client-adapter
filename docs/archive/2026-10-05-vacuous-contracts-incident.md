# Vacuous Contracts Suite Incident - 2026-10-05

Status: closeout record for the static-contracts harness outage found
and repaired on 2026-10-05 (PR #74).

## Incident

The 2026-10-04 portability edit (PR #66) split `tests/run.php`'s `<?php`
opener into `<?` + inserted helper text + `php`. With short_open_tag off,
PHP echoed the file as plain text and exited 0, so `composer test` looked
green while executing nothing. Every "Static contracts: ok" reported for
PRs #67, #70, #71, and #73 was vacuous; the service renames those
extractions made went unverified until the Proposal_Review session.

## Detection and Repair

The advisory reviewer flagged contract-suite breakage twice (#73, #74
sessions) and was initially dismissed because "the suite is green".
The actual detection came from running `php tests/run.php` directly and
seeing source text instead of output. Repair (PR #74): restore the
opener, relocate the aggregation helper to its intended position, repair
~20 needles the vacuous window hid (renames and windows re-anchored to
service declarations, bounded to real function bodies), and require the
`Static contracts: ok` completion marker in `composer test` so a vacuous
run fails the gate.

## Lessons

1. **Verify the verifier.** A suite that fails-to-run is
   indistinguishable from a passing suite when both exit 0. Every harness
   needs a completion artifact (marker, count, or checksum) the gate
   checks — exit codes alone cannot detect non-execution.
2. **Instrument evidence beats process evidence.** "The gate is green"
   (process) lost twice to "the reviewer says the needles cannot match"
   (instrument). When an instrumented reviewer contradicts a process
   signal, run the instrument directly before dismissing either side.
3. **Never text-surgery near a file opener without re-executing.** The
   corruption was a mid-string insertion that split a token; a single
   `php tests/run.php && echo done` observation after the #66 edit would
   have caught it at introduction. The marker guard now makes that
   automatic.
4. **Blast radius was bounded by defense in depth.** During the outage
   the behavior suites (separate files), PHPStan/PHPCS, and the live
   smoke all remained real and green — which is why the four merged
   extractions were still verified at every level except the needle
   layer, and why repairing that layer was a test-only change.

## Follow-ups

- Sibling repos adopting the portable-assertion pattern (Provider Split
  Refactor Standard) must add the same completion-marker guard in the
  same change; note this in the platform standard when next touched.
- The remaining Controller decomposition (read abilities, execution
  orchestration) proceeds on the now-genuinely-green harness.
