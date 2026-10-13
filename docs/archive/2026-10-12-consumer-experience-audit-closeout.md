# Consumer Experience Audit Closeout - 2026-10-12

Status: closeout record for the 2026-10-11/12 consumer-perspective
experience audit and the three PRs it produced. The audit examined the
product from two user ends: the OpenClaw/AI-client integrator (REST error
semantics, doc trust chain, discoverability) and the WordPress site owner
(connection flow, failure visibility, uninstall hygiene). All fixes landed
through PRs #102, #103, and #104 (squash-merged as `7dd1988`, `b94dc94`,
`16a989f`).

## Audit Method (what was checked, reuse it)

Three parallel read-only sweeps over `includes/Rest/`, `includes/Admin/`,
`docs/`, and `languages/`:

1. **REST error semantics**: every route, permission callback, and error
   path compared for envelope consistency (reason/next_step data, HTTP
   status classification, Retry-After headers, id-format 404s, the single
   authored 500).
2. **Consumer doc trust chain**: every documented route, allowlist, and
   stub behavior diffed against `register_rest_route` and
   `Execution_Profile_Registry` across README, readme.txt, quickstart,
   contract, acceptance, readiness, batch policy, and recipes.
3. **Admin UX**: pairing handshake, dependency communication, failure
   visibility, i18n coverage, and uninstall hygiene walked end to end.

Key verdicts: adapter-native validation/auth errors were already
best-in-class (`reason`/`next_step`, field-level detail); the doc trust
chain and admin failure visibility were the weak ends.

## What shipped

**PR #102 — doc trust chain sync + permanent drift gate**
(`docs/consumer-route-trust-sync`):

- removed quickstart curl examples for four deleted diagnostics shortcut
  routes and the README leftover name; rewrote approve/reject stub
  semantics to the actual unregistered `rest_no_route` 404 across
  quickstart, acceptance, and readiness; fixed the acceptance step that
  could never pass against current `/help`;
- aligned every execution allowlist to the 26 registered profiles
  (contract had three internally inconsistent lists; quickstart/readiness
  listed 17; batch policy missed `adopt-article-audio`);
- fixed real drift the earlier positioning audit missed: recipe and visual
  docs referencing the removed `GET /post-blocks` shortcut, and a
  Core read-preflight path written without its namespace;
- added `composer check:doc-routes`
  (`scripts/check-doc-route-drift.php`) wired into `test:all` and
  `release:verify`; it parses `register_rest_route` registrations and
  fails when consumer docs or hand-maintained route literals in
  `Controller.php` reference unregistered routes, with an explicit
  "not registered"-wording escape hatch for intentional absence.

**PR #103 — REST error shape unification + /help completion**
(`rest/error-shape-consistency`):

- administrator-only routes return the structured privilege/authentication
  envelope (reason + next_step) instead of bare `rest_forbidden`;
- execution lock contention (409 `execution_in_progress`) now carries the
  standard `Retry-After` response header on both execute paths;
- `invalid_core_guidance` reclassified 500 → 502 with reason/next_step,
  matching its invalid_core_proposal/preflight siblings;
- id path regexes relaxed so malformed `{proposal_id}`/`{request_id}`/
  `{key_id}` values return a 400 `npcink_openclaw_adapter_invalid_path_id`
  naming field, value, and expected shape instead of a misleading 404;
- `GET /help` advertises `auth.preferred=key_pair_device_pairing` with the
  Application Password flow as `auth.fallback`; the connection manifest
  `urls` cover `run_read`, `proposals`, `proposal_item`, and `execute`.

**PR #104 — admin visibility + uninstall credentials + pagination**
(`admin/failure-visibility`):

- a missing-dependency administrator notice on plugins/dashboard/Adapter
  screens (60s-cached probe, purged on plugin activation/deactivation), so
  silent broken activation now has a dashboard-level signal;
- the connection page explains dependency contract mismatches per
  component and version (including empty-version reporting) instead of an
  empty "Needs dependencies" state;
- a collapsed `Recent client errors` disclosure backed by a bounded
  15-record channel buffer (length-capped fields, identical-failure
  dedupe over the newest five records, global 5/minute write cap;
  diagnostics-only, Core audit untouched);
- fallback Application Passwords get uniquely keyed tracking rows
  (race-free), revoked on uninstall and on normal revocation via
  `wp_delete_application_password`;
- Core list pagination totals relay as `X-WP-Total`/`X-WP-TotalPages` on
  the proposal and read-request proxies.

All three PRs passed `composer test:all` (now including
`check:doc-routes`), `analyse:php`, `lint:standards`, `check:i18n`,
`check:doc-routes`, `smoke:wp`, plus live WP-CLI behavior verification
against the LocalWP site (malformed-id 400s, structured 403s, /help auth
block, manifest urls, X-WP-Total: 275 on the proposals list, flood
throttling 32→5, ledger create→enumerate→revoke round trip). zh_CN is
100% current again; POT regeneration also surfaced and fixed six
pre-existing untranslated verification-read strings.

## Review loop notes

Eight OpenCodeReview rounds were triaged across the three PRs (5/4/2 on
#102, 0 on #103, 5/4/5/2+3emb/2/6+3emb/2 findings on #104). Substantive
hardening landed: gate-1 whole-file literal scanning, tiered absent-cue
suppression, markdown-table mention matching, per-credential ledger rows,
windowed+global record throttling, single-source dependency probes, and
label-safe i18n placeholders. Two delivery-contract mismatches (summary
count vs posted inline count) were handled by the gate failing closed and
a workflow re-run; a dozen low findings were accepted with one-line
rationales under the advisory standard. The auto-merge BLOCKED state
twice on `required_conversation_resolution`; resolving the delivered
threads via GraphQL `resolveReviewThread` unblocked both merges.

## Remaining follow-ups (deliberately out of scope)

- Execution as a job/202 flow with pollable status remains a future
  boundary decision (needs a boundary note before implementation).
- Pending-pairing inbox and a test-connection button on the Adapter page
  are candidate UX additions; approval currently relies on the
  CLI-emitted verification URL.
- Key expiry/rotation policy is a product decision; keys currently never
  expire and rotation is revoke-then-repair.
- `?limit=abc` is still silently coerced (absint) rather than a 400.
- No exception→error mapping exists; a PHP fatal still escapes the
  envelope (relevant only for upstream handler fatals, bounded by
  `rest_do_request`).
