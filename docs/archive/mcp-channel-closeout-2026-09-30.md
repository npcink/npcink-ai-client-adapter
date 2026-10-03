# MCP Channel Closeout - 2026-09-30

Status: closeout record for the governed MCP channel workstream
(2026-09-30). Captures what shipped, the development lessons worth keeping,
and the remaining follow-ups. Product context lives in
[`adapter-positioning-notes-2026-09.md`](adapter-positioning-notes-2026-09.md);
security semantics live in [`threat-model.md`](threat-model.md).

## What Shipped

| Milestone | Delivery |
| --- | --- |
| Boundary enforcement classification (`client_policy.boundary_enforcement`, policy v2) | PR #44 |
| Connection readiness checklist, Application Password hygiene notice, CLI 0.3.0 boundary status | PR #45 |
| Threat model + 2026-09 positioning notes (Phase 1 docs) | PR #44/#46 |
| Core AI activity overview strip (audit view) | `npcink-governance-core` PR #84 |
| CLI `mcp` subcommand, stdio server, read/propose tools | PR #47 + npm 0.3.0 |
| Signed query-parameter canonicalization fix | PR #48 |
| Intent-gated execution tools (CLI 0.4.0, 11 tools) | PR #49 + npm 0.4.0 |
| Platform decision record and follow-ups | `npcink-workflow-toolbox` PR #158/#159/#168 |

Governed execution was verified end to end through the MCP surface against a
real paired key: propose, approve-and-execute (`intent="commit"`), Core audit
trail (`created -> approved -> commit.preflighted -> executed`), fixture
cleanup. Claude Desktop integration is configured against the globally
installed CLI.

## Development Lessons

1. **Canonicalize wire values, not framework-mutated values.** The signed
   query-parameter bug (PR #48) existed because the server hashed
   `WP_REST_Request::get_query_params()`, which argument sanitization had
   already mutated (`limit=3` string became integer `3`) before the
   permission callback ran. Signature canonical forms must be built from what
   the signer actually sent (`wp_unslash($_GET)`), because framework
   representations drift across the request lifecycle.
2. **Real-machine acceptance beats simulated coverage.** The same bug was
   invisible to every in-process smoke test, because admin-session requests
   never exercise the signature path. The MCP `list_proposals`
   tool found it in one live call. When a channel has an auth path that tests
   bypass, run at least one real end-to-end call through it.
3. **The advisory review gate is a second pair of eyes, not an oracle.** It
   caught roughly thirty real defects across this workstream, including two
   fatal ones (wrapper child inheriting the MCP server's stdin and consuming
   the JSON-RPC stream; EOF-triggered `process.exit` killing pending tool
   calls). It also produced plausible-but-wrong findings; verify claims
   against the tree before acting. One reminder it gave was correct in an
   unexpected way: the reviewer reads the COMMIT, not the working tree —
   incomplete `git add` lists (two docs missed) surfaced as "doc not updated"
   findings. Stage deliberately.
4. **Long-lived stdio servers need three guards.** Serialize message
   handling (one wrapper child at a time), cap the pending line in bytes
   (UTF-16 length undercounts multibyte payloads), and attach EPIPE error
   handlers before writing. None of these show up in one-shot CLI usage.
5. **Intent discipline scales across surfaces.** The CLI `--intent=commit`
   refusal rule translated directly to MCP as a required `intent` argument on
   execution tools, validated before dispatch. When a safety rule exists in
   one surface, project it explicitly into every new surface instead of
   relying on route naming.
6. **npm publishing reality (2026).** Bypass-2FA granular tokens are being
   restricted for direct publishing. An account without 2FA enrolled cannot
   publish at all (no OTP to present, no bypass option to enable) — enroll an
   authenticator first, then publish interactively. Registry metadata and the
   install index propagate at different speeds; retry installs for a few
   minutes before diagnosing.
7. **GUI-spawned processes have no shell PATH.** Claude Desktop config must
   use absolute binary paths and run node directly against script files
   (shebang resolution needs PATH). Also verify child processes do not
   inherit stdin from a long-lived parent.

## Remaining Follow-ups

- HTTP (streamable) transport for remote MCP clients — on demand.
- Upstream approval-hook proposal post — draft ready in
  `npcink-workflow-toolbox` `docs/platform/wordpress-mcp-approval-hook-proposal-draft.md`;
  posting requires operator approval.
- Suite onboarding wizard (time-to-first-approved-action) — on demand.
- Operator-side: open Claude Desktop to activate the configured MCP server;
  reject the leftover acceptance proposal `P-B73C10AC-DBE7` in the Core
  review queue.
