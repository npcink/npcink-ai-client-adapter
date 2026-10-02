# Client Error Feedback and Contract Drift Closeout (0.4.1)

Date: 2026-10-02
Status: merged (PR #54, squash 82fefcf); plugin 0.4.1, companion CLI 0.6.0

## Scope

This closeout records the client-side experience review of the Adapter
channel and the three fixes shipped from it, plus the process lessons. The
review walked the full client-visible surface — REST routes, `/health` and
`/help` payloads, the MCP stdio server, the npm CLI wrapper, device pairing,
and the onboarding docs — and classified findings by client impact.

The shipped fixes:

1. CLI error passthrough: `keypair-adapter-request.mjs` now prints the full
   Adapter error envelope including the `data` object (`reason`,
   `next_step`, `retry_after`, route-specific `operator_feedback`) after
   redaction; the MCP `safeErrorMessage` carries a bounded copy into tool
   errors.
2. Structured signed-auth failures: `can_use_adapter` returns structured
   `WP_Error`s instead of a bare permission boolean, pairing rate limits set
   the `Retry-After` response header, and stale `GET /terms`/`GET /term`
   help purpose strings were removed.
3. Recipe contract adjudication: the CLI recipe helper mirrors the reviewed
   recipe contract locally and every doc claim that `GET /help` exposes
   `openclaw_recipes.*` playbooks was corrected; the consumer acceptance
   checklist now verifies the negative.

## The Findings That Drove It

- **P0 contract drift**: commit 74b6c4e intentionally removed the
  `openclaw_recipes` playbook payload from `/help` (static tests even assert
  its absence), but the CLI recipe helper still required it at runtime and
  ~70 doc claims across 14 files still told integrators to read it. The
  static string tests passed because they only check that docs *mention* the
  strings, not that the runtime still serves them.
- **P1 auth black box**: `authenticate_signed_request()` returned a bare
  bool, so eight failure causes (missing credentials, unknown key, revoked
  key, owner demoted, clock skew, body hash mismatch, bad signature, nonce
  replay) all collapsed into WordPress' generic `rest_forbidden`. A client
  could not tell clock skew from revocation.
- **P1 error data loss**: the CLI printed only `{ok, status, code, message}`
  on non-2xx and discarded the operator-guidance `data` object the README
  explicitly tells clients to display.
- **P2 polish** (deferred, listed under Follow-ups).

## Decisions

### Recipe contracts live in docs, not in `/help`

The thin-channel removal stands: Adapter does not re-host playbook payloads.
The reviewed recipe document is the contract source; the CLI may keep a
local mirror of the guardrail constants it enforces (`contract_source:
cli-local-mirror`), and the acceptance checklist asserts `/help` exposes no
recipe catalog, which matches the smoke-test snapshot. Cross-doc recipe-id
references (`openclaw_recipes.X` as identifiers) may remain in recipe docs,
but no doc may claim runtime `/help` exposure.

### Error taxonomy must not create a pre-verification oracle

The first implementation gave every failure its own code — and the advisory
review caught that any distinction between "key unavailable" and "signature
invalid" lets a caller holding only an observed key id probe key liveness,
regardless of check ordering. The merged design:

- key-state-independent causes keep specific codes and run first
  (`malformed` credentials, `timestamp_skew`, `content_hash_mismatch`);
- every cause that depends on the key record and precedes a verified
  signature collapses into one
  `npcink_openclaw_adapter_signed_request_rejected` code and message;
- `scope_denied` and `nonce_replayed` are reported only after the signature
  verifies, so they are unreachable without the private key and can stay
  specific.

A security test pins the invariant: unknown key, revoked key, and tampered
signature must all return the same code.

## Lessons and Working Norms

1. **Static string tests cannot catch docs-vs-runtime drift.** They verify
   that files mention words, not that the server serves them. The defenses
   that worked here: a real-crypto behavior suite driving the private
   method through every failure branch, a CLI contract test against a local
   stub HTTP server, and the `/help` contract snapshots in the WP smoke
   pass. Prefer behavior tests for anything a client can observe.
2. **Every client-visible error is a product surface.** Shape: stable
   `code`, machine `reason` key, human `next_step`, plus route-specific
   guidance (`operator_feedback`). Never return a bare boolean permission
   failure when the cause is diagnosable. State-independent causes may be
   specific; state-dependent causes before proof of possession must merge.
3. **Tool output must relay operator guidance.** A CLI that drops the
   server's `data` object silently discards the product's remediation copy.
   Redact, then relay. Bound appended JSON (2 KiB cap) so MCP tool errors
   stay readable.
4. **Rate limits speak HTTP.** Put `retry_after` in error data *and* the
   `Retry-After` response header; stock clients and SDKs read the header.
5. **Version discipline is a five-way sync.** A behavior change touches: the
   plugin header + version constant, `readme.txt` (stable tag + changelog),
   the CLI `package.json`, every doc that pins the CLI package version, and
   the version pins inside `tests/run.php`. One missed leg turns the static
   suite red — which is exactly why the pins exist; update them in the same
   commit.
6. **Advisory review earns its keep on second looks.** The first review pass
   approved the taxonomy; the second pass — after tests were added — found
   the oracle. Re-run the review after substantive fixes, not only before
   the first commit.

## Follow-ups (explicitly deferred)

- Proposal and read-request list pagination (needs Core-side paging
  support; Adapter can only pass through what Core serves).
- `/health` and `/help` payload layering (summary by default, full contract
  behind a detail flag) to cut noise and token cost for new clients.
- Polling rhythm guidance (`poll_interval_seconds`) on pending proposal and
  read-request responses, mirroring the pairing `interval` field.
- Canonical/alias marking for the dual execute routes in `/help`.
- MCP `initialize` instructions should state that tool calls are dispatched
  serially, so clients do not misread queuing latency as timeouts.
- MCP protocol-version negotiation (currently a fixed supported version).
- zh_CN translation pass for REST error messages and the admin pairing page.
- Release actions outside this repository: publish
  `@npcink/openclaw-adapter-cli@0.6.0` to npm and run the WordPress.org SVN
  release gate for plugin 0.4.1.
