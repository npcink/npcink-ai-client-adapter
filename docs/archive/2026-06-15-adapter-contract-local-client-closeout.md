# 2026-06-15 Adapter Contract and Local Client Closeout

Status: accepted closeout record.

Date: 2026-06-15.

This document records the problem, boundary decisions, completed work,
verification, and remaining release posture from the Adapter contract metadata
and local AI client acceptance session.

## Problem

Npcink AI Client Adapter needs to be usable by OpenClaw-compatible and other
local AI clients without becoming the source of product capability, approval
truth, workflow runtime, cloud control plane, or model routing.

The prior direction was correct but still needed a stronger handoff surface:

- local AI clients need machine-readable boundary metadata, not only prose;
- clients need a repeatable non-destructive acceptance check;
- contract drift across `/health`, `/help`, and `/connection/manifest` should
  be detectable;
- package release and package-install smoke checks should prove the release
  surface, not only the source tree;
- visual and batch execution recipes needed to stay aligned with the explicit
  Adapter/Core boundary.

## Boundary Decisions

Adapter remains the thin channel layer. It owns OpenClaw-facing REST routes,
read routing to the WordPress Abilities API, proposal and commit-preflight
routing to Npcink Governance Core, explicit post-Core execution profiles, and
small readiness diagnostics.

Adapter still does not own:

- WordPress ability definitions or callbacks;
- Core proposal storage, approval, preflight, or audit truth;
- generic final write execution;
- workflow runtime, queues, MCP runtime, or Agent Gateway catalogs;
- provider credentials, model routing, prompts, or product UX;
- Cloud settings, Cloud signing clients, `/cloud/*` routes, Cloud connector
  routes, Cloud monitoring, or Cloud execution truth.

The current integration contract remains:

```text
OpenClaw/local AI client -> Adapter -> WordPress Abilities API
OpenClaw/local AI client -> Adapter -> Governance Core proposal/preflight
```

Adapter must preserve:

```text
core_proxy_execute=false
commit_execution=false
```

Final writes remain limited to explicit execution profiles after Core approval
and commit-preflight.

## Work Completed

Implementation landed in:

```text
ebf5d9c Harden adapter contract metadata and local client acceptance
```

### Contract Metadata

`includes/Rest/Controller.php` now exposes machine-readable contract metadata
through:

- `GET /health`
- `GET /help`
- `GET /connection/manifest`

The contract metadata includes:

- `adapter_contract_version`
- `client_policy_version`
- `execution_profile_registry_version`
- `supported_plan_abilities_version`
- hashes for execution profiles, supported execute ability ids, and supported
  plan ability ids.

The contract hash helper strips translated `message` fields before hashing so
that localized human text does not create false contract drift.

### Client Policy

`client_policy` now includes `policy_version`, and the local AI client policy
documentation explains the new `contract` object and hash fields.

The intent is that prompts can reference the policy, but clients and tests can
verify the policy and contract as data.

### Local AI Client Acceptance

A new non-destructive acceptance command was added:

```bash
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
composer accept:local-ai-client
```

The new script is:

```text
tests/local-ai-client-acceptance.sh
```

The new guide is:

```text
docs/local-ai-client-acceptance.md
```

The default acceptance checks:

- adapter CLI syntax;
- signed CLI `status`;
- `GET /health`;
- `GET /connection/manifest`;
- `GET /help`;
- public `read-ability` for `npcink-abilities-toolkit/site-info`.

Optional modes cover sensitive read request creation, approved sensitive read,
proposal preflight, and explicit final write execution.

A later signed local CLI E2E pass exercised the destructive-but-cleaned
`npcink-abilities-toolkit/create-draft` proposal flow through real local
Adapter/Core/Toolkit routes. The evidence record is:

```text
docs/archive/local-ai-client-e2e-acceptance-2026-06-15.md
```

That pass created proposal `1e4f63ca-ac29-458e-8edd-532af30de3c4`, executed it
with explicit commit intent, read back draft post `282608`, and deleted the
fixture after verification.

### Tests and Static Contracts

`tests/run.php` now checks the contract metadata strings, the new Composer
script, and the acceptance doc/script.

`tests/smoke-wp.php` now verifies the `contract` object and `policy_version`
in `/health`, `/help`, and `/connection/manifest`.

### Visual and Batch Policy Alignment

The same closeout preserved and verified the existing work surface around:

- `batch_review_feedback` documentation;
- block theme template layout recipe updates;
- Gutenberg visual acceptance updates;
- block theme fixture checks in `scripts/gutenberg-visual-acceptance.mjs`;
- WP-CLI socket and PHP argument handling in
  `tests/block-theme-openclaw-acceptance.sh`.

These additions remain recipe and acceptance work. They do not move ability
ownership, Core governance truth, or generic write execution into Adapter.

## Verification

The final verification pass completed successfully:

```bash
composer test:all
WP_CLI_BIN=/opt/homebrew/bin/wp composer release:verify
composer package:release
composer validate --no-check-publish
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
  MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
  composer accept:local-ai-client
git diff --check
```

Release packaging produced:

```text
build/npcink-ai-client-adapter.zip
```

The package install smoke was also rerun and completed successfully:

```text
npcink-openclaw-adapter WordPress smoke: ok
package install smoke: ok
```

An earlier package smoke attempt hit a transient Governance Core app-token
creation failure at `POST /npcink-governance-core/v1/apps`. A direct Core
repository create and REST retry later returned `201`, the temporary key was
revoked, and the full Adapter package install smoke passed on rerun. No Core
code changes were made.

## Current Repository State

At this closeout:

- Adapter `master` contains commit `ebf5d9c`.
- Adapter is one commit ahead of `origin/master`.
- The source tree was clean immediately after that commit.
- This document is a follow-up documentation artifact and should be committed
  separately if it is kept.

## Remaining Work

No additional Adapter code work is required for this phase.

Remaining actions are release or collaboration operations:

- push Adapter `master`;
- open a PR or review thread if the project wants review before merging;
- distribute or install-test `build/npcink-ai-client-adapter.zip`;
- use `docs/local-ai-client-acceptance.md` and
  `docs/archive/local-ai-client-e2e-acceptance-2026-06-15.md` for a real local AI
  client or OpenClaw-compatible acceptance conversation;
- implement Core-emitted and Toolkit-emitted runtime contract endpoints in
  their owning repositories before Adapter consumes them as stronger
  cross-repository proofs.

Re-run the verification commands only when files change, before a push or PR,
or before a formal release package is distributed.
