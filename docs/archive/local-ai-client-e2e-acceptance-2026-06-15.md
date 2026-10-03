# Local AI Client E2E Acceptance - 2026-06-15

Status: accepted evidence record.

This records the first destructive-but-cleaned local AI client acceptance pass
that exercised the signed Adapter CLI against a real local WordPress site,
Governance Core, and Abilities Toolkit.

## Scope

The acceptance used the packaged local CLI surface:

```bash
packages/adapter-cli/bin/npcink-openclaw-adapter.mjs request --profile=local --insecure-local-tls
```

The route sequence was:

1. `POST /proposals`
2. `GET /proposals/{proposal_id}`
3. `POST /proposals/{proposal_id}/approve-and-execute --intent=commit`
4. WP-CLI post readback and cleanup

The write ability was `npcink-abilities-toolkit/create-draft`. This is an
allowlisted Adapter execution profile and still required Core proposal,
approval, commit-preflight, and explicit commit intent.

## Result

The passing run produced:

```json
{
  "proposal_id": "1e4f63ca-ac29-458e-8edd-532af30de3c4",
  "created_status": "pending",
  "detail_status_before_execute": "pending",
  "execute_success": true,
  "ability_id": "npcink-abilities-toolkit/create-draft",
  "post_id": 282608,
  "post_status_after_execute": "draft",
  "core_commit_execution": false,
  "execution_record_status": "succeeded",
  "execution_record_commit_execution": false,
  "correlation_id_present": true,
  "adapter_request_id_present": true
}
```

The created draft post was deleted after readback:

```text
cleaned_post_id=282608
```

An earlier attempt also successfully created a draft but used a too-strict
assertion for top-level `commit_execution`; that draft was manually cleaned:

```text
Success: Deleted post 282607.
```

## Boundary Confirmation

This pass confirms the local client can complete a governed draft write through
Adapter without Adapter becoming the source of approval truth:

- `core_proxy_execute=false` remains part of the Adapter contract.
- `commit_execution=false` remains part of Core evidence and Adapter records.
- Final execution stayed inside the explicit `create-draft` profile.
- Cleanup used WP-CLI only to remove the acceptance fixture.

## Not Covered

This pass does not prove that Core or Abilities Toolkit expose their own signed
runtime contract endpoints. Adapter currently exposes Adapter-declared
compatibility floors in `contract.core_contract_min_version`,
`contract.core_plugin_min_version`, `contract.toolkit_contract_min_version`, and
`contract.toolkit_plugin_min_version`.

These fields are not Core-emitted or Toolkit-emitted runtime proofs.

A future cross-repository contract handshake should be implemented in the owning
repositories first, then consumed by Adapter. Adapter should not fabricate Core
approval truth or Toolkit ability truth.
