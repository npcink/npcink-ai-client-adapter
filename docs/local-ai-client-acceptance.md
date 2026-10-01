# Local AI Client Acceptance

Status: active acceptance guide.

This acceptance flow verifies that a local AI client enters WordPress through
Npcink AI Client Adapter and stays inside the Adapter/Core governance boundary.
The default command is intentionally non-destructive.

## Default Check

Run after connecting a local CLI profile:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
composer accept:local-ai-client
```

The default check runs:

- CLI syntax checks for `packages/adapter-cli`;
- signed `status`;
- `GET /health`;
- `GET /connection/manifest`;
- `GET /help`;
- a fail-closed `read-ability` probe for
  `npcink-abilities-toolkit/build-content-inventory-fix-plan`.

Sensitive abilities such as `npcink-abilities-toolkit/site-info` and content
inventory planning are not executed without a grant in the default
non-destructive check. They require the Core read-request approval flow
described below.

The full WordPress smoke can provision temporary administrator-approved read
requests with `MAA_ADAPTER_SMOKE_CREATE_READ_GRANTS=1`. A sensitive plan that
is redacted by Core is intentionally not eligible for proposal handoff until
the cross-repo plan contract defines a non-sensitive handoff representation.

## Fixture Proposal Check

Run the signed fixture flow when you need proposal lifecycle coverage without
manually preparing a proposal id:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
composer accept:local-ai-client-fixture
```

The default fixture flow creates a Core proposal for
`npcink-abilities-toolkit/create-draft`, reads it back, verifies that the
signed client cannot self-approve through `approve-and-execute`
(`npcink_openclaw_adapter_approve_requires_admin_session`), and verifies that
the local CLI refuses the final execute route unless the caller explicitly
passes `--intent=commit`. It stops before final write execution.

To include final write execution:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 \
composer accept:local-ai-client-fixture
```

The commit-enabled fixture approves the proposal through the Core admin
surface as a WordPress administrator (simulated with WP-CLI), executes the
approved proposal through the signed CLI `POST /proposals/{id}/execute`,
verifies the created draft post id, verifies duplicate execution rejection
with `npcink_openclaw_adapter_execution_already_completed`, and deletes the
created draft post by default. Set `MAA_ADAPTER_FIXTURE_CLEANUP_POST=0` only
when you intentionally want to inspect the created draft.

## Sensitive Read Check

To create a Core-owned sensitive read request, pass an ability id and input
file:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_ABILITY=npcink-abilities-toolkit/wp-ops-diagnostics-detail \
MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_INPUT=/tmp/read-input.json \
composer accept:local-ai-client
```

After the operator approves the read request in Core, run the approved read:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_ABILITY=npcink-abilities-toolkit/wp-ops-diagnostics-detail \
MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_INPUT=/tmp/read-input.json \
MAA_ADAPTER_ACCEPTANCE_SENSITIVE_READ_REQUEST_ID=READ_REQUEST_ID \
composer accept:local-ai-client
```

The same `ability_id`, input file, and `read_request_id` must be used. If input
changes, create a new Core read request.

## Proposal Preflight Check

To verify a reviewed proposal without executing a write:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_PREFLIGHT_PROPOSAL_ID=PROPOSAL_ID \
composer accept:local-ai-client
```

This uses Adapter commit-preflight with `--intent=preflight`.

## Final Write Check

Final execution must be explicit:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_COMMIT_PROPOSAL_ID=PROPOSAL_ID \
MAA_ADAPTER_ACCEPTANCE_ALLOW_COMMIT=1 \
composer accept:local-ai-client
```

The proposal must already be appropriate for final write execution. Adapter
will still require Core approval, Core commit-preflight, and an explicit
execution profile. The script does not create a proposal on its own.

## Expected Boundary

Acceptance is successful when:

- `client_policy` is present in `/health`, `/help`, and
  `/connection/manifest`;
- the `contract` object exposes version and hash metadata;
- public reads run through Adapter;
- sensitive reads fail closed unless Core grants the exact input hash;
- final writes require explicit commit intent and a profiled ability;
- commit-enabled fixture writes expose Core preflight evidence, record the
  Adapter execution outcome back to Core, and read back the created WordPress
  draft before cleanup;
- unsupported abilities or unapproved proposals fail closed.
