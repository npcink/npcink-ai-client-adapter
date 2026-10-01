# Client Policy Contract

Status: accepted client contract.

This document is the stable entry point for local AI clients and other
OpenClaw-compatible clients. The detailed policy remains in
[`../local-ai-client-policy.md`](../local-ai-client-policy.md); this file records
the minimum contract that clients must follow.

## Discovery

Clients must read machine-readable policy before selecting routes:

```text
GET /wp-json/npcink-openclaw-adapter/v1/health
GET /wp-json/npcink-openclaw-adapter/v1/help
GET /wp-json/npcink-openclaw-adapter/v1/connection/manifest
```

The shared `client_policy` object currently uses:

```text
schema_version=npcink_openclaw_adapter_client_policy.v1
policy_version=2
client_posture=adapter_only_fail_closed
```

The same surfaces also expose the Adapter `contract` object with version and
hash metadata for client-side drift detection.

## Required Client Behavior

Clients must:

- Use Adapter-relative routes only.
- Treat `forbidden_outputs` as values that must not be displayed, logged,
  summarized, or copied into proposal payloads.
- Treat `forbidden_local_access` as non-negotiable local access boundaries.
- Use Core sensitive-read requests when Core marks a capability as requiring
  read authorization.
- Use Core proposals, Core approval, Core commit-preflight, explicit operator
  commit intent, and an Adapter execution profile before any final write.
- Fail closed when the requested action conflicts with `client_policy`,
  capability guidance, Core preflight, or Adapter execution profile validation.

Prompts and handoff text are convenience guidance only. They are not the
security boundary.

## Boundary Enforcement

`client_policy.boundary_enforcement` reports the boundary class of the
active connection:

```text
class=enforced      auth_mode=ed25519_key_pair_signed
class=conventional  auth_mode=wordpress_native
recommended=ed25519_key_pair_device_pairing
```

`enforced` means the request was authenticated by a registered Ed25519
key-pair signature; WordPress core has no verifier for that credential, so
Adapter routes are the only reachable path. `conventional` means the
connection uses a WordPress-native credential that can also reach `wp/v2`
directly, which makes the approval gate voluntary for that client. Clients
on a conventional connection must not present the approval gate as an
enforced control. On `/connection/manifest`, `boundary_enforcement` is merged
after `manifest_sha256` is computed and is listed in
`integrity.digest_excludes`, so the digest stays stable across authentication
modes. Semantics are owned by `docs/threat-model.md`
([`../threat-model.md`](../threat-model.md)).

## Sensitive Read Binding

Sensitive read execution is bound to:

```text
ability_id_plus_input_hash
```

If the ability id or input changes, the client must create a new Core read
request. Adapter validates the Core read-preflight grant immediately before
execution.

## Write Binding

Writes must flow through:

```text
proposal -> Core approval -> commit-preflight -> explicit commit intent -> Adapter execution profile
```

Adapter does not expose standalone approve or reject proxy routes to signed
clients. The unified approve-and-execute action holds approval authority and
is reserved for WordPress administrator sessions; signed clients wait for
human approval in the Core admin and then call
`POST /proposals/{proposal_id}/execute`.

## Source Documents

- [`../local-ai-client-policy.md`](../local-ai-client-policy.md)
- [`../local-ai-client-acceptance.md`](../local-ai-client-acceptance.md)
- [`../openclaw-adapter-contract.md`](../openclaw-adapter-contract.md)
