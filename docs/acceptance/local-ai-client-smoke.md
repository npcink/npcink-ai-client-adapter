# Local AI Client Smoke

Status: accepted smoke checklist.

This is the short acceptance entry point for local AI client integration. The
full guide lives in
[`../local-ai-client-acceptance.md`](../local-ai-client-acceptance.md).

For the complete local development pass, use the aggregate command. It also
runs the release-critical `limit=abc` smoke checks:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
composer accept:local-dev
```

## Non-Destructive Default

Run:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
composer accept:local-ai-client
```

This verifies:

- CLI syntax.
- Signed local profile status.
- `GET /health`.
- `GET /connection/manifest`.
- `GET /help`.
- Direct read execution through Adapter for a currently allowlisted planning
  ability. Sensitive reads must use the Core read-request flow.

## Fixture Proposal

Run:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
composer accept:local-ai-client-fixture
```

This creates a governed draft proposal, reads it back, and verifies that final
execution is refused unless the caller supplies explicit commit intent.

## Commit-Enabled Fixture

Run only when a local draft write and cleanup are acceptable:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 \
composer accept:local-ai-client-fixture
```

This verifies the signed-client self-approval refusal, human Core approval,
signed final execution through `POST /proposals/{id}/execute`, Core preflight
evidence, Core execution-result recording, non-dry-run WordPress draft
creation, duplicate execution rejection with the stored Adapter record, and
cleanup of the created draft post.

## Release Baseline

Before release or PR merge, run:

```bash
composer test:all
composer smoke:wp
composer release:verify
composer package:release
composer smoke:package-install
git diff --check
```

The fixture checks are separate from the default release baseline because the
commit-enabled path intentionally performs a local WordPress write.

## M4 Ollama over PgyVPN

For the local M4 acceptance profile, use the M4 PgyVPN address
`172.16.3.35` with the already verified SSH identity. Keep Ollama bound to the
M4 loopback (`127.0.0.1:11434`) and create only a temporary SSH loopback
tunnel for the test process. Do not expose port 11434 on the PgyVPN interface,
change the persistent Provider registry, or pull a model as part of acceptance.

The M4 matrix must include both Docker smoke profiles for Core and Toolkit,
the signed HTTPS `limit=abc` checks for `/proposals` and `/read-requests`, the
real Provider-log correlation, and cleanup of the tunnel, temporary SDK/MU
loader, fixtures, containers, volumes, and backups. Record M4 evidence against
the exact source revisions; a passing local fixture is not real-user or
production Cloud evidence.

When a Provider-log correlation fails, inspect the temporary loader's provider
identification first. The WordPress AI logging integration compares the parsed
hostname, so a test loader that compares a host-plus-port string can create a
false product failure. Fix the fixture and rerun the strict assertions instead
of weakening correlation requirements.

For Adapter closeout, report source merge, runtime acceptance, release publication,
and branch/worktree disposition separately. Check the current default central
matrix before making a cross-repository completion claim. Preserve named remote
refs used by fixed manifests; a squash merge alone does not replace the exact
source pin. Follow the platform's [publishing standard](../../../npcink-workflow-toolbox/docs/platform/pr-publishing-standard-v1.md)
for shared publication and cleanup rules. The [2026-10-17 review](../archive/2026-10-17-session-closeout-review.md)
records the historical handoff and intentionally retained Adapter ref.

The [Adapter 0.4.3 RC3 review](adapter-043-rc3-2026-10-17/review.md) records the
2026-10-17 frozen source manifest, exact compatibility revalidation, artifact
checksums and proposed publication/rollback steps. It is pending operator
publication approval; the historical snapshot and RC2 pins remain unchanged.

For a local administrator-only runtime pass that provisions temporary Core
read requests, set `MAA_ADAPTER_SMOKE_CREATE_READ_GRANTS=1` when running
`composer smoke:wp`. Core still redacts sensitive plan output; if a redacted
plan is submitted to `proposals/from-plan`, Adapter must reject it rather than
turning redaction into executable proposal input.

## Expected Boundary

Acceptance passes only when the client uses Adapter as the entry point,
preserves `core_proxy_execute=false` and `commit_execution=false`, follows Core
read/write governance, and never substitutes direct filesystem, database, log,
or custom script access for Adapter routes.
