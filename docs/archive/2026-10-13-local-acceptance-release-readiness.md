# Local acceptance and release readiness — 2026-10-13

Status: Adapter local acceptance passed; production publication and current
cross-project acceptance remain open. This updates the conclusions of the
[earlier checkpoint](2026-10-13-local-acceptance-session-checkpoint.md) without
rewriting that historical snapshot.

## Change envelope

Repository: `npcink-ai-client-adapter`. This closeout updates acceptance evidence,
the reusable browser approval procedure and 0.4.3 release notes. Runtime source
was verified at `7b8431c345c4e07c37499e2270a282618a1da7f6` (#116). No public REST
contract, callback, approval policy, Cloud transport or credential setting is
changed by this documentation update. Required gates are source tests, PHPStan,
PHPCS, release verification, CLI tests and the central matrix. Rollback of this
update is a documentation revert; deployment rollback remains the published
0.4.2 package. No database migration is introduced.

## Completed follow-ups

- #105 and #106 merged the pinned OpenCodeReview 1.12.13 and setup-php 2.40.0
  updates with required checks passing.
- #115 added the persisted Provider-log assertion to signed fixture acceptance.
- #116 fixed the missing AI Request Log context wrapper on approved write
  dispatch. It projects only scoped operation identifiers, excludes private
  execution authority, reconstructs nested Core identifiers and restores the
  previous context on success, errors and exceptions.
- Advisory review could not deliver for #105/#106/#116 after retries. Standard
  publisher exceptions recorded the service failures; these are not clean AI
  review verdicts. Deterministic checks and independent diff review passed.
- The `limit=abc` fix from #108 remains in the candidate and in both runtime
  regression checks. WordPress.org already ships it in 0.4.2.

## Actual browser approval

The operator manually approved Core proposal
`a51c3c2b-0a43-4661-96fc-63cd664f33a2` in the WordPress admin page. Signed
readback returned `approved` and Core recorded `proposal.approved` with actor 1.
This used one newly created test attachment, `282139`, with
`governed_with_backup`; no automatic administrator approval was used for this
proposal.

Before approval, signed self-approval returned
`npcink_openclaw_adapter_approve_requires_admin_session`; pending execution
returned `npcink_governance_core_proposal_not_approved`. Attachment and file
readback stayed intact with no premature backup.

After approval, the signed client executed exactly one deletion with zero failed
or blocked actions. Core preflight was authorized and included the approved input
hash; `core_commit_execution=false` and Core preflight `commit_execution=false`
remained intact. Adapter request id was
`b921f12b-7004-4516-bed4-2ab6091037f2` and correlation id was
`2ccf0835-cc99-4182-9186-93abf94d87f4`.

The helper verified attachment/file deletion and retained backup manifest/byte
hashes. A repeat execution returned
`npcink_openclaw_adapter_execution_already_completed`. Proposal readback matched
the succeeded Adapter execution record and the recorded Core result. Run-owned
attachment/files/backup were then cleaned; Core proposal and audit remain.

Local evidence directory: `/tmp/adapter-manual-browser-approval/`. It contains
fixture recovery state and responses, not credentials or publishable package
files. The material facts above are preserved here rather than depending on
temporary files.

## Full local development acceptance

`composer accept:local-dev` passed on merged #116 with commit and flagged-media
opt-ins, explicit WordPress path/socket, local signed CLI profile and temporary
official Ollama Provider SDK registration. The real Provider was M4 Ollama 0.32.5
over a loopback SSH tunnel, using the existing `qwen2.5:1.5b` model and official
Provider 1.3.0 SDK. No model pull, remote service restart or Controller override
was used.

Passed legs:

- `composer test:all`, `analyse:php`, `lint:standards`, i18n and WordPress.org guards.
- Full WordPress smoke with grant fixtures and no skipped legs, including HTTP
  400 / `rest_invalid_param` for both list routes' non-numeric `limit`.
- Current-source package installation/activation and restoration of the original
  development plugin symlink.
- Signed client contracts and a committed draft fixture whose persisted AI
  Request Log matched the signed execution's proposal, correlation and request ids.
- Real HTTPS governed deletion with backup, approved restoration, no-backup
  deletion, duplicate refusals and stored execution evidence/readback.
- Run-owned draft, attachment and backup cleanup; temporary Provider probe,
  registration loader, discovery cache and SSH tunnel cleanup.

After cleanup, the Adapter was active, its symlink targeted the main checkout,
WordPress reported 7.1.1 and the Provider registry contained only `npcink-cloud`.
This real Ollama result does not establish logging for a Cloud transport path
that bypasses the AI SDK logging transporter.

Separate `composer release:verify` passed with Plugin Check reporting no errors.
CLI `npm run check` and all six tests passed. Logs:
`/tmp/adapter-full-local-dev-20261013.log` and
`/tmp/adapter-release-verify-20261013-final.log`.

## Latest central matrix and remaining owners

Central `composer quality:matrix:run`, generated `2026-10-13T10:20:34Z`, passed
Toolkit, Core, Adapter, Toolbox and Eval Lab. It failed two rows:

- Cloud Addon: its current `codex/041-refresh-service-status` checkout had 13
  changed files and `class-cloud-settings-page.php` at 2282 lines exceeded its
  2281-line complexity ratchet. Its owner is handling that work. The nearby
  `PR body section requires meaningful content: Scope` message is an expected
  negative-test diagnostic, not the actual failure.
- AI Cloud: the clean `codex/m4-image-pull-repair` HEAD's exact-SHA GitHub backend
  check failed. Toolbox was notified to coordinate with the Cloud owner.

This matrix did not request M4 runtime acceptance. Core's separate M4 packaged
smoke version-assertion repair belongs to its owner and is not an Adapter gate
pass claimed here. Earlier green snapshots cannot replace current failing rows.
Matrix log: `/tmp/adapter-final-central-matrix-20261013.log`.

## Git cleanup and candidate

All remaining Adapter topic refs for merged PRs #102–#116 were checked against
their PR head SHAs, saved in a verified recovery bundle, then removed locally
and remotely. Remote deletion used exact-head leases; no active worktree branch
was deleted. Core's Adapter fix worktree was already removed by its owner after
a verified bundle. The two managed dependency worktrees were archived with the
app's recoverable archive mechanism. Before this documentation branch, Adapter
had only `master` locally/remotely and one main worktree. This makes no cleanup
claim about other repositories.

Recovery bundle:
`/Users/muze/gitee/.worktree-backups/adapter-merged-topic-refs-2026-10-13.bundle`.
The final documentation topic must also be cleaned after its own protected merge.

The 0.4.3 package rebuilt with completed release notes has SHA-256
`3fdeffc8f79466d0fe55f2d2b1f2c27d58d9d19fd3629d1f58bd993602cc2d97`.
Documentation is excluded by `.distignore`; verify the exact reviewed package
again before publishing. WordPress.org was directly rechecked and still reports
0.4.2; no `v0.4.3` tag exists and the latest GitHub release remains v0.3.2.
No production tag, GitHub release, npm publish or WordPress.org SVN commit was
performed. Resolve the current external blockers and obtain final operator
publication approval before those production actions.

## Reusable lessons

1. Verify the real dispatch boundary: a context helper and static metadata test
   do not prove approved writes actually enter that helper. Keep success/error/
   exception restoration checks and a persisted Provider-log acceptance.
2. Treat the operator click, automated administrator context and signed execution
   as distinct evidence. Read back Core approval before executing; preserve audit
   history while cleaning only fixtures.
3. Report matrix result columns and the actual failing assertion. Negative-test
   diagnostics and older snapshots must not be promoted into current conclusions.
4. Set `COMPOSER_PROCESS_TIMEOUT=0` for deliberate long review/publisher waits.
   Retry advisory delivery through the standard publisher; document delivery
   failure without treating a timeout as a clean review or bypassing required CI.
5. For squash-merged refs, verify PR state and exact topic head, retain a recovery
   bundle and check worktree ownership before deletion. Git ancestry alone is
   insufficient; package acceptance, merge, cleanup and publication are separate.
