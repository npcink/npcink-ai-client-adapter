# Local acceptance session checkpoint — 2026-10-13

Status: implemented Adapter milestones merged; follow-up and workspace cleanup
remain open. This is a dated checkpoint, not an all-project release closeout.

## Completed work and evidence

| Milestone | Merged PR | Evidence |
| --- | --- | --- |
| Non-numeric list `limit` validation | #108 | `/proposals?limit=abc` and `/read-requests?limit=abc` return HTTP 400 / `rest_invalid_param`; retained in the local aggregate smoke. |
| Allowlisted governed attachment deletion | #109 | Explicit post-Core execution profile; Toolkit owns callbacks and Core owns approval/preflight. |
| Complete local development acceptance | #110 | Deterministic gates, WordPress smoke, current-source package install/restore and real signed client fixtures. |
| 0.4.3 candidate verification | #111 | Candidate acceptance and signed final-write evidence; production publication was not performed. |
| Plan intake and restoration bridge | #113 | Manual flagged-media plan handoff and signed restoration support. |
| Real HTTPS deletion acceptance | #112 | Both deletion branches, duplicate refusal, backup restoration, execution evidence/readback and run-owned fixture cleanup. |

Main checkout was clean at merged commit `55654d3` when this checkpoint began.
Detailed records remain authoritative for what each run actually proved:

- [Local development acceptance](local-dev-acceptance-2026-10-13.md).
- [0.4.3 candidate acceptance](2026-10-13-adapter-0.4.3-release-acceptance.md).
- [Real HTTPS flagged-media acceptance](2026-10-13-flagged-media-https-acceptance.md).
- [Consumer experience audit closeout](2026-10-12-consumer-experience-audit-closeout.md)
  for the earlier documentation, REST error and admin visibility work (#102–#104).

## Remaining work and ownership

- Provider-log correlation acceptance is owned by the Core coordination chat,
  working in the separate `adapter-provider-log-acceptance` checkout. At this
  checkpoint it contains uncommitted acceptance changes. The first live attempt
  failed because the selected provider was unavailable and fallback reached a
  Cloud endpoint that rejected the request; hook and draft cleanup completed.
  A successful provider-log acceptance is not claimed here.
- Browser administrator approval remains a separate manual acceptance, as the
  user requested. Automated administrator test-context approval already passed.
- Production tag, GitHub release and WordPress.org publication are separate
  actions; preparing or merging the candidate does not perform them.
- The latest central matrix recorded by the media acceptance passed Adapter,
  Toolkit, Core, Toolbox, Eval Lab and AI Cloud, but failed Cloud Addon's
  release-manifest assertion in its already modified checkout. Its owner must
  resolve and rerun it before claiming a current all-repository pass. Earlier
  green matrix runs are historical evidence, not a substitute for that rerun.
- Dependabot PRs #105 and #106 are still open at this checkpoint and are not
  part of the completed feature/acceptance milestones above.

## Lessons to reuse in Adapter development

1. **Check each execution environment.** CLI ability discovery does not prove
   HTTP discovery. Toolkit's registration happened before REST context existed;
   its owner fixed the lifecycle on `rest_api_init`. Inspect the owner lifecycle
   before attributing a CLI/FPM mismatch to stale caches. Match the signed
   profile's site URL with explicit `WP_PATH` before creating live fixtures.
2. **Keep acceptance failures visible.** Missing capabilities, a skipped smoke
   leg or absent Core execution evidence must fail a requested gate. Optional
   destructive fixtures need explicit opt-in; when selected, their failure must
   propagate to `accept:local-dev`. Harnesses must isolate inherited opt-in
   environment variables so ordinary tests cannot silently run destructive legs.
3. **Respect live governance and quota.** Prove pending/self-approval refusals,
   approval, preflight, execution recording and duplicate rejection through the
   actual channel. Isolate smoke app-token fixtures within the WP-CLI process;
   signed HTTPS keeps the real connection. Respect HTTP 429 and Retry-After
   without changing quotas or clearing counters.
4. **Treat cleanup as an acceptance assertion.** Use only run-owned disposable
   attachments/drafts and verify readback plus backup bytes. Cleanup must report
   failure and retain recovery state when incomplete. Recovery rejects path
   traversal and forged backup ids. Core proposals and audit evidence remain;
   fixture cleanup is not permission to delete governance history.
5. **Verify package restoration on failure as well as success.** Build from
   current source and preserve the original directory or symlink and activation
   state. A successful package install alone does not establish safe restoration.
6. **Keep evidence claims separate.** A simulated administrator context does
   not establish browser UI acceptance; a metadata correlation contract does
   not establish a successful provider log; a merged PR does not establish
   production publication or complete cross-project acceptance.

The [flagged-media guide](../local-flagged-media-https-acceptance.md) is the
reusable command and recovery procedure. Existing
[AI review-loop lessons](../ai-review-loop-lessons-2026-10-06.md) and the central
[platform index](../../../npcink-workflow-toolbox/docs/platform/README.md)
remain the pointers for advisory review and cross-project coordination. This
checkpoint introduces no new runtime or cross-project authority.

## Branch and worktree checkpoint

Merged topic refs still exist locally and remotely for #102, #103, #104, #107
and #108–#113. Squash merge does not imply Git ancestry containment; verify the
PR state and branch head before deleting any such ref. The repository publisher
deliberately retains branches for worktree safety.

The `adapter-flagged-media` worktree is clean and holds #113's merged topic
branch, but Provider acceptance reuses its `vendor` directory by symlink and
Core's isolated smoke mapping also references it. Its owner explicitly asked
to retain it until those dependencies are removed. The
`adapter-provider-log-acceptance` worktree is active and modified; preserve it
until its owner submits and verifies the work. Neither checkout was removed by
this checkpoint. A clean primary checkout is not a clean repository-wide
worktree inventory.

This chat's implemented acceptance work can be handed off using these records;
closing the chat does not complete the remaining items above or clean Git refs.
