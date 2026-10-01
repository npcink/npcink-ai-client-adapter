# Key Pair Device Pairing Contract

This document defines the Phase 2 MVP for connecting OpenClaw-style local
clients to Npcink OpenClaw Adapter without transferring a WordPress Application
Password.

## Boundary

Adapter owns only:

- device pairing REST routes;
- WordPress admin approval for a pending public key;
- registered public key metadata;
- Ed25519 request-signature verification for Adapter routes.

Adapter does not own:

- private keys;
- WordPress Core REST authentication;
- workflow runtime, queues, schedulers, or MCP runtime;
- Core proposal storage, approval, preflight truth, or audit truth;
- final WordPress write authority.

## Pairing Flow

1. Local client generates an Ed25519 key pair.
2. Local client calls `POST /connect/device/start` with only public metadata.
3. Adapter stores a pending pairing and returns a user code plus verification
   URL.
4. User opens the WordPress admin verification URL and approves or rejects.
5. Local client polls `POST /connect/device/poll` with the device code.
6. Adapter returns connection metadata and a `key_id`; no secret is returned.
7. Local client signs subsequent Adapter REST requests with its private key.

## REST Routes

### `POST /wp-json/npcink-openclaw-adapter/v1/connect/device/start`

Public route. Starts a pending pairing.

Request:

```json
{
  "schema_version": "npcink_openclaw_adapter_device_pairing.v1",
  "client": {
    "name": "OpenClaw",
    "device_name": "Muze Mac",
    "broker": "@npcink-abilities-toolkit/adapter-broker",
    "broker_version": "0.4.0"
  },
  "key": {
    "alg": "Ed25519",
    "public_key": "BASE64URL_RAW_32_BYTE_PUBLIC_KEY",
    "fingerprint": "sha256:..."
  },
  "requested_scopes": ["npcink.read", "npcink.propose", "npcink.status", "npcink.execute"]
}
```

Response:

```json
{
  "device_code": "dev_...",
  "user_code": "ABCD-1234",
  "verification_uri": "https://example.test/wp-admin/admin.php?page=npcink-openclaw-adapter-pair",
  "verification_uri_complete": "https://example.test/wp-admin/admin.php?page=npcink-openclaw-adapter-pair&user_code=ABCD-1234",
  "expires_in": 600,
  "interval": 3
}
```

Adapter stores only a hash of `device_code`.

### `POST /wp-json/npcink-openclaw-adapter/v1/connect/device/poll`

Public route. Polls a pending pairing.

Request:

```json
{
  "device_code": "dev_..."
}
```

Pending response: HTTP `202`.

Approved response:

```json
{
  "ok": true,
  "connection_id": "npcink_conn_...",
  "key_id": "mk_...",
  "site_url": "https://example.test",
  "adapter_base_url": "https://example.test/wp-json/npcink-openclaw-adapter/v1",
  "scopes_effective": ["npcink.read", "npcink.propose", "npcink.status"]
}
```

Rejected or expired pairings do not return private material.

## Admin Approval

The WordPress admin route is:

```text
wp-admin/admin.php?page=npcink-openclaw-adapter-pair&user_code=ABCD-1234
```

The page requires an authenticated WordPress user with `manage_options`. It
shows:

- client name;
- device name;
- broker name/version;
- key fingerprint;
- requested scopes.

The user can approve or reject. On approval, Adapter stores:

- `key_id`;
- `connection_id`;
- `user_id`;
- client metadata;
- Ed25519 public key;
- fingerprint;
- scopes;
- created/last-used/revoked timestamps.

## Request Signing

Signed Adapter calls use these headers:

```http
X-Npcink-Key-Id: mk_...
X-Npcink-Timestamp: 2026-06-01T12:00:00Z
X-Npcink-Nonce: BASE64URL_RANDOM
X-Npcink-Content-SHA256: sha256:...
X-Npcink-Signature-Alg: Ed25519
X-Npcink-Signature: BASE64URL_SIGNATURE
```

Clients should also send the same fields in `Authorization` as a transport
fallback for local web servers that drop custom `X-Npcink-*` headers:

```http
Authorization: Npcink-Signature key_id="mk_...", timestamp="2026-06-01T12:00:00Z", nonce="BASE64URL_RANDOM", content_sha256="sha256:...", alg="Ed25519", signature="BASE64URL_SIGNATURE"
```

Canonical request:

```text
NPCINK-AI-CLIENT-ADAPTER-V1
METHOD
ROUTE
CANONICAL_QUERY_JSON
TIMESTAMP
NONCE
CONTENT_SHA256
```

For requests with no query parameters, `CANONICAL_QUERY_JSON` is `[]` to match
WordPress' empty query parameter array. `CANONICAL_QUERY_JSON` is built from
the raw wire query parameters (the undecorated strings the client sent), not
from WordPress' sanitized request arguments: argument sanitization casts
declared values (for example `limit=3` becomes the integer `3`) before the
permission callback runs, and verification must hash exactly what the client
signed.

Adapter verifies:

- `key_id` exists and is not revoked;
- mapped user still has `manage_options`;
- timestamp is within 300 seconds;
- nonce has not been used;
- request body SHA-256 matches;
- Ed25519 signature is valid;
- scopes allow the route.

Nonce replay protection is claimed only after the Ed25519 signature verifies.
Adapter uses one non-autoloaded WordPress option per key/nonce digest and a
strict insert-only database operation; the unique option name is the atomic
claim, so concurrent requests cannot both accept the same nonce. Each claim
stores its expiry, expired claims are reclaimed with compare-and-delete
semantics, and cleanup is server-randomized, low-frequency, and bounded.
Adapter does not use a read-then-write transient nonce check or WordPress 7's
duplicate-update `add_option()` path for the claim.

## Local Request Wrapper

OpenClaw-style clients can use the npm CLI after pairing:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter connect --site=https://example.test --profile=local
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter status --profile=local
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local GET /health
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.4.0 -- npcink-openclaw-adapter request --profile=local POST /proposals/from-plan --body-file=/tmp/npcink-proposal.json
```

The wrapper:

- reads the local key-pair profile from
  `~/.npcink-openclaw-adapter/keypair-profiles/`;
- signs the Adapter request locally;
- rejects absolute URLs and accepts only Adapter-relative routes;
- prints only the Adapter JSON response;
- does not print the private key, profile JSON, `Authorization`, or
  `X-Npcink-*` signature headers.

## Scopes

- `npcink.status`: health, help, capabilities, connection metadata.
- `npcink.read`: direct-read ability routes.
- `npcink.propose`: proposal creation and status routes. Core remains the
  proposal, approval, preflight, and audit truth.
- `npcink.execute`: final execution routes (`POST /execute-approved-proposal`,
  `POST /proposals/{proposal_id}/execute`, and commit-preflight handoff
  consumption) for proposals a human already approved in the Core admin. This
  scope never includes proposal approval: the unified
  `POST /proposals/{proposal_id}/approve-and-execute` action requires a
  WordPress administrator session and rejects signed clients with
  `npcink_openclaw_adapter_approve_requires_admin_session`, regardless of
  scopes.

Legacy `magick.*` scopes remain accepted only for existing signed clients.
