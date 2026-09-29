# Local AI Client Policy

Status: accepted Adapter client contract.

Npcink AI Client Adapter can support OpenClaw-compatible local AI clients, but it
does not control which AI client a customer chooses or how that client reasons
internally. Adapter-owned safety must therefore be expressed as enforceable
interfaces and fail-closed server behavior, not as prompt-only instructions.

## Adapter-Owned Controls

Adapter owns these enforceable controls:

- WordPress REST authentication and capability checks.
- Signed key-pair request verification for the local CLI profile flow.
- Adapter-relative route validation in the CLI request wrapper.
- CLI output redaction for local profile paths, key ids, connection ids,
  public/private keys, authorization headers, signing headers, cookies, tokens,
  passwords, and secrets.
- Machine-readable `client_policy` on `GET /connection/manifest`,
  `GET /health`, and `GET /help`.
- Sensitive read routing through Adapter `POST /read-requests`,
  `GET /read-requests/{request_id}`, and `POST /run-read-ability`.
- Core read-preflight validation immediately before sensitive reads.
- Proposal, approval, commit-preflight, and explicit commit intent for writes.

## Customer-Selected Client Boundary

Adapter does not own:

- The customer's AI client selection.
- The customer's client-side prompts, model routing, memory, plugins, or tool
  execution policy.
- Generic filesystem, database, log, or WordPress-internal inspection outside
  Adapter routes.
- Approval truth, read-grant truth, or commit-preflight truth; those remain in
  Npcink Governance Core.

Because of that boundary, prompts are only convenience guidance. They are not a
security boundary. A client that ignores `client_policy` should still fail at
the Adapter/Core boundary when it attempts forbidden routes, missing grants, or
unapproved writes.

## Client Policy Contract

Clients should read `client_policy` from `/help` or `/connection/manifest`
before selecting routes. The current schema is:

```json
{
  "schema_version": "npcink_openclaw_adapter_client_policy.v1",
  "policy_version": "2",
  "client_posture": "adapter_only_fail_closed",
  "boundary_enforcement": {},
  "forbidden_outputs": [],
  "forbidden_local_access": [],
  "allowed_transport": {},
  "sensitive_read_flow": {},
  "write_flow": {},
  "recommended_cli": {}
}
```

`boundary_enforcement` classifies the active connection:
`class=enforced` with `auth_mode=ed25519_key_pair_signed` means Adapter
routes are the only reachable WordPress path for that client;
`class=conventional` with `auth_mode=wordpress_native` means the client
holds a WordPress-native credential that can also reach `wp/v2` directly,
so the approval gate is voluntary for that client. The recommended
credential is `ed25519_key_pair_device_pairing`; see
[`threat-model.md`](threat-model.md) for the boundary semantics.

`GET /connection/manifest`, `GET /health`, and `GET /help` also expose a
separate `contract` object. Clients may cache or compare
`adapter_contract_version`, `execution_profile_registry_version`,
`supported_plan_abilities_version`, `execution_profile_registry_hash`,
`supported_execute_ability_ids_hash`, and `supported_plan_ability_ids_hash` to
detect whether the Adapter contract matches an acceptance-tested build.

Contract version 4 also exposes `client_contract=generic_ai_client`, keeps
OpenClaw as `priority_channel`, and publishes a `workflow_projection` object.
Clients must read canonical workflow definitions from Toolkit through Adapter
read execution, preserve the listed parity fields, and fail closed on version
mismatch. This metadata does not make Adapter a workflow registry or runtime.

The contract also exposes Adapter-declared compatibility floors:
`core_contract_min_version`, `core_plugin_min_version`,
`toolkit_contract_min_version`, and `toolkit_plugin_min_version`. These are the
minimum Core and Abilities Toolkit contract/plugin versions this Adapter build
was designed and accepted against. They are not Core-emitted or
Toolkit-emitted runtime proofs, and they do not replace Core approval,
read-preflight, commit-preflight, or WordPress Abilities execution checks.

All contract fields are informational compatibility metadata; they do not
authorize reads or writes.

Required client behavior:

- Treat `forbidden_outputs` as values that must not be displayed, summarized, or
  copied into chat, logs, files, or proposal payloads.
- Treat `forbidden_local_access` as non-negotiable local access boundaries.
- Use only Adapter CLI commands and Adapter-relative routes when
  `allowed_transport.adapter_cli_only=true` and
  `allowed_transport.adapter_relative_routes_only=true`.
- If a capability indicates sensitive read authorization, create a read request,
  wait for Core approval, then call `read-ability` with the same `ability_id`,
  identical input, and `read_request_id`.
- If the sensitive read input changes, create a new read request.
- For writes, use Core proposals, Core approval/preflight, and an Adapter final
  write route only after explicit operator commit intent.
- For Zhihu or trusted-search research atoms, use
  `POST /run-read-ability` with the canonical
  `npcink-toolbox/cloud-web-search` ability id and a documented
  `managed_source`; do not call Cloud provider endpoints, `/cloud/*` routes, or
  Zhihu/Open Platform APIs directly from the local client.

## Preferred Local CLI Commands

Use these narrow commands for local client handoff:

```bash
npcink-openclaw-adapter status --profile=local
npcink-openclaw-adapter request --profile=local GET /help
npcink-openclaw-adapter request --profile=local GET /capabilities
npcink-openclaw-adapter read-request create --profile=local --ability-id=ABILITY_ID --input-file=/tmp/read-input.json --purpose=PURPOSE --data-classes=CLASS[,CLASS]
npcink-openclaw-adapter read-request status --profile=local READ_REQUEST_ID
npcink-openclaw-adapter read-ability --profile=local --ability-id=ABILITY_ID --input-file=/tmp/read-input.json --read-request-id=READ_REQUEST_ID
```

These helpers are preferred over asking a client to hand-build sensitive read
route bodies because they preserve Adapter-relative routing, signed transport,
output redaction, and the Core grant binding shape.
