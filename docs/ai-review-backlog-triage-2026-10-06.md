# AI Review Backlog Triage Record — 2026-10-06

Status: executed.

Source: the 2026-10-06 usage audit and backlog worksheet covering all 51
inline OpenCodeReview findings delivered on merged pull requests #45-#74
(plus findings re-raised on the gate PRs #79-#82). This record is the
durable triage: fixes cite their pull requests; declines record their
rationale once, per AI Code Review Standard v1, so future rounds can
cite them.

## Fixed

| Item | Finding | Pull request |
| --- | --- | --- |
| A1 | Revoked Application Passwords counted as active | #83 |
| A2 | inputSchema integer/number never type-checked | #81 |
| A3 | Server error data stringified without structured redaction | #81 |
| A4 | Retention sort dropped failed records first | #80 |
| A5 | Expired-lock takeover could delete a fresh lock | #80 |
| A6 | release_lock deleted unconditionally | #80 |
| B1/B2 | Double parse; cheap methods queued behind tool calls | #81 |
| B3 | exit(0) on stream errors | #81 |
| B4 | Silent version fallback | #81 |
| B6 | Test key file 0600 | #81 |
| B7/B8 | CI job timeouts; setup-php SHA pin (v2.37.2, f3e473d116dcc) | #84 |
| B9-B12 | INPUT docblock; stray zh_CN line; product-name mistranslation; plural coverage placeholder | #83 |
| B13/B14 | fix_upstream.py removed (changes long applied) | #84 |
| B15 | Transport constants moved to Upstream_Dispatch (Controller aliases kept) | #84 |
| B16 | preflight_operator_feedback restores ?WP_Error | #84 |
| C1 | Header-presence semantics documented on Signing_Auth | #84 |
| C2 | Unbound Core context fails closed for signed clients | #80 |
| C3 | Execution handoff must carry its own approved_input_hash | #80 |
| C4 | consume() validates fully before burning the handoff | #80 |
| C5 | Handoff serialization contract documented on the caller-held lock | #80 |
| F2 | ability_id pattern + server-side idFields enforcement | #81/#82 |
| F6 | rpcCall buffers across stdout chunks | #81 |

## Declined (recorded rationale, citable on future rounds)

- **B5 — POT Plural-Forms header.** The POT compiles without it,
  `wp-cli i18n make-pot` does not emit the header, and a manual line is
  wiped by the next regeneration; the locale .po headers carry the
  correct plural forms. (#83)
- **D1 — constant-literal recipe contract.** It is an intentional
  tripwire against accidental edits. (#54 original)
- **D2 — slice(0,2000) mid-JSON truncation.** Readability only, no
  security impact.
- **D3 — Composer caching / lock.** composer.lock is committed; caching
  is a speed nicety, not correctness.
- **D4 — needle-whitespace coupling in tests/run.php.** The static
  contracts suite is needle-based by design; the vacuous-run guard
  (#74/#75) covers the real failure mode.
- **D5 — unescape lacks \\r.** Both decoder paths are symmetric; keys
  match regardless.
- **D6 — mtime freshness check.** The release flow recompiles .mo every
  time; PO-Revision-Date comparison remains a future enhancement.
- **D7 — test stub provider returns ''.** Post-#54 the relevant
  assertions read the real fingerprint property.
- **D8 — `tee /dev/stderr` in composer test.** POSIX-only development
  and CI environments (macOS + ubuntu runners); not run on Windows.
- **F3 — oversized-line transport edge.** The strict-client truncation
  scenario requires closing stdout mid-flight; -32602 wording is a spec
  nicety. (#81)
- **F4 — intent triple-source drift.** requiredIntent is defined with
  each tool and checked centrally; the three sources are deliberate
  (schema for clients, idFields-independent gate, wrapper flag).
- **Workflow-wide `issues: write`, cancelled-run retry, composite retry
  action, marker/comment-shape drift handling** — declined or recorded
  as future options in the platform standard's 2026-10-04/05/06
  sections and the gate PR triage records (#79-#82).

## Verified gone without action (E)

- #54 `$trusted_fingerprint` undefined in tests — fixed in the same era.
- #57 po:365 "OpenClaw 交接凭据" entry — updated before the audit.
- #65 `issues: write` least-privilege — declined on record in the
  platform standard; action SHA-pinned since 2026-10-05.
- #70 Upstream_Dispatch constructor docblock — completed by the
  extraction follow-up.
- #73 consecutive-blank-line runs — mooted by later decomposition.
- #74 `"\n\t}\n"` extraction patterns — removed by the contracts-suite
  rewrite.

## Process record

The delivery+triage publisher gate (PR #79) ran its first full loop on
itself and on the four fix PRs above: every delivered round was triaged
in the pull request bodies (fix lines cite commits; accept lines cite
the rationales above), and the gate failed closed on an unparseable
summary shape (round 8 of #79, round 1 of #80/#81-2) until the parser
learned the producer's actual contract. Gate self-tests against pinned
fixtures are the recorded follow-up before the next action pin bump.
