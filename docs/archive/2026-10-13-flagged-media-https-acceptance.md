# Flagged media HTTPS acceptance — 2026-10-13

Status: Adapter acceptance passed; production publication not performed.

## Dependency heads

- Toolkit `npcink-abilities-toolkit` PR #251 merged as `7d6313f` with PHP 8.0,
  PHP 8.3 and both WordPress smoke jobs passing.
- Core delete-first batch preflight fix merged as `98ffa002` in the owner
  repository.
- Adapter plan bridge PR #113 merged as `f510270`.
- This acceptance branch contains the real HTTPS fixture from PR #112 and the
  optional aggregate-gate harness correction.

## Real HTTPS evidence

`MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 composer
accept:local-ai-client-flagged-media` passed against the Local.app HTTPS site.
Before creating media it matched the CLI connection manifest `site_url` with
the explicit WP-CLI `home_url()`, then confirmed all three required abilities
in the live HTTP Core catalog.

The fixture created two run-owned PNG attachments and passed for both branches:

- `governed_with_backup`: pending proposal, signed client self-approval refusal,
  pre-approval execution refusal, administrator test-context approval, Core
  preflight/result evidence, deletion, duplicate refusal, execution-record
  readback, retained backup hash verification, and restore through a second
  approved proposal.
- `illegal_content_no_backup`: the same governance and duplicate protections,
  deletion, and checks that no backup manifest or backup directory remained.

Both restored and original run-owned attachments, files and backup records were
cleaned. The command reports failure and keeps a recovery state file if cleanup
fails. The administrator step is an automated WordPress context simulation; it
does not claim a person clicking the browser admin UI.

Log: `/tmp/adapter-flagged-https-runtime.log`.

The complete command with `MAA_ADAPTER_FIXTURE_FLAGGED_MEDIA=1` also passed:

```text
local development checks and acceptance: ok
```

Log: `/tmp/adapter-flagged-https-aggregate-2.log`.

## Cross-repository closeout

The central `composer quality:matrix:run` run at 2026-10-13T07:29:45Z passed
Adapter, Toolkit, Core, Toolbox, Eval Lab and AI Cloud. Cloud Addon failed its
own release-manifest assertion while its worktree already contained unrelated
uncommitted changes; those files were not changed or cleaned by this acceptance.
The matrix is therefore evidence for the Adapter slice, not an all-repository
closeout.

No real production tag, GitHub release or WordPress.org SVN publication was
performed. The optional provider-log acceptance remains a separate follow-up
and was not run during this live media fixture.
