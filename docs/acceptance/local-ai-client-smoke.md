# Local AI Client Smoke

Status: accepted smoke checklist.

This is the short acceptance entry point for local AI client integration. The
full guide lives in
[`../local-ai-client-acceptance.md`](../local-ai-client-acceptance.md).

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
