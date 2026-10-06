# AI Review Loop and Governed-Write Verification Lessons — 2026-10-06

Status: active reference. Companion records:
`docs/ai-review-backlog-triage-2026-10-06.md` (the executed backlog),
`npcink-workflow-toolbox` `docs/platform/ai-code-review-standard-v1.md`
(the 2026-10-06 template/publisher-gate update, PR #197), and
`docs/openclaw-quickstart.md` (the Core app token scope recipe).

This record captures the operational lessons from the 2026-10-06 arc —
the publisher delivery+triage gate's first production loops (#79-#87)
and the live governed-write verification — so future sessions do not
re-derive them.

## Advisory review loop mechanics (learned over 12+ rounds)

- **Rounds are per-head**: every push triggers a fresh full-diff review;
  findings arrive with new ids each round. Fix-worthy items are fixed and
  pushed (starting the next round); recorded accepts go into the PR body
  and need no push — the gate re-verifies the same run, so an all-accept
  round converges without another review.
- **Convergence pattern that worked**: fix real defects (correctness,
  fail-open holes, API-contract lies), accept-and-record the speculative
  tail with a one-line rationale. Declines recurred across rounds; the
  platform convention of recording a decline once and citing it holds.
- **Merges are blocked by unresolved review threads** in this repository
  ("All comments must be resolved"). Resolve every finding thread after
  its triage line lands in the PR body; auto-merge fires on its own
  afterwards.
- **The reviewer examines adjacent context too**: findings can anchor
  outside the diff hunks. The action then embeds them in the summary body
  ("Failed to post inline"); the gate parses those into `emb:<path>:<line>`
  triage keys. Expect near-context findings on small diffs.
- **Producer contract (observed, action v1.12.10 @579b931)** — five
  summary shapes: `found **N** issue(s)`; `Review skipped`; `Review
  complete: N finding(s) across K item(s)`; `Review partially complete`
  (not a delivered round — the gate fails closed); and the posted/failed
  inline split. All pinned by `composer test:ai-review-gate`; run it
  before any action pin bump (see dependabot note below).
- **Sed is not portable between BSD and GNU** for bracket-class
  backslashes; the gate's escaping uses awk `gsub` (backslash in its own
  pass). Multi-layer escaping (python → file → shell → awk) burned twice:
  `"\\&"` in awk replacement semantics is an escaped ampersand, not
  backslash+match. The self-test now pins the correct behavior.
- **composer script output can swallow failures**: `composer test:all 2>&1
  | tail -1` prints the "Script @test was called via" notice on failure;
  always grep for the actual pass/fail lines ("Static contracts: ok",
  behavior "ok", FAIL).

## Governed-write verification methodology

The #80 handoff binding was verified in four layers; reuse this ladder
before reverting a fail-closed hardening:

1. **Static**: read the counterpart's contract builder (Core's
   `Commit_Preflight_Service` writes `approved_input_hash` unconditionally;
   `Request_Context::signed_client_context()` binds fingerprints when
   forwarded).
2. **Audit**: Core's audit rows show what actually arrived
   (`key_id`, `caller_type`, fingerprint in metadata). An unbound handoff
   shows empty identity — the fast way to distinguish "counterpart broke"
   from "we broke it".
3. **Probe**: a temporary mu-plugin logging `rest_pre_dispatch` state
   (user id, token header, fingerprint headers) settles identity-routing
   questions in one run. Remove probes immediately after use.
4. **Live**: `composer accept:local-ai-client-fixture` with
   `MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1` is the full propose → refuse
   self-approve → human approve → signed execute → duplicate-reject
   round trip. It is the acceptance gate for any preflight/handoff change.

## The token-less-site failure mode (root cause chain)

Signed clients map to a WordPress user at verification
(`Controller::authenticate_signed_request`), and the fingerprint
forwarding headers live inside the Core-app-token branch of
`Upstream_Dispatch::send()`. Without a token: no forwarding, the internal
preflight rides the paired user's admin session, Core's admin branch
never sets the client binding, and the handoff is unbound — #80 correctly
fails closed. The remedy is configuration, not code: mint the app key
with the trusted-Adapter scope recipe (see quickstart) and set
`NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN`. Core deliberately omits
`commit:record_execution` from default app-key scopes; grant it
explicitly or execution recording fails with `recorded=false`.

## Local dev-site operations (magick-ai)

- WP-CLI needs the active Local socket: Local rotates
  `~/Library/Application Support/Local/run/<id>/mysql/mysqld.sock`; inject
  with `php -d mysqli.default_socket=… wp …` or `WP_CLI_MYSQL_SOCKET` for
  the scripts.
- A stale `.maintenance` file in the site root returns 503 to every
  client; remove it before blaming code.
- The local site uses a self-signed certificate: pass
  `MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1` to acceptance scripts.
- Mint/revoke Core app keys programmatically with
  `App_Key_Repository::create()/revoke_by_key_id()` via `wp eval-file`;
  revoke superseded keys immediately (scope hygiene, per Core policy).

## Open items and future options (recorded, not owed)

- Open dependabot PRs #68 (github-script 7→9) and #69
  (open-code-review 1.12.10→1.12.11): #69 is an action PIN BUMP — on
  merge, run `composer test:ai-review-gate` first and expect to
  re-validate the live summary shapes against the new action version.
- The toolbox matrix leg is red only on a parallel session's in-flight
  branch; re-run `composer quality:matrix:run` from toolbox after it
  lands.
- Recorded future options from the review rounds: a composite retry
  action to de-triplicate the workflow pin; expanding gate fixtures when
  a bumped action changes shapes.
