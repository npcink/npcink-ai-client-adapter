# Adapter Threat Model

Status: 2026-09-30, advisory security reference for the Adapter channel layer.

This document states what the Adapter approval gate is designed to stop, what
authentication modes actually enforce, and what Adapter explicitly does not
defend against. It supplements the boundary rules in `AGENTS.md` and
`docs/local-ai-client-policy.md`; it does not change any REST contract.
Npcink Governance Core and the Abilities Toolkit own their own threat models.

Review this document whenever a new authentication mode, execution profile
family, read-authorization path, or `client_policy` change is introduced.

## Assets to protect

1. WordPress site integrity: content, media, templates, and settings. Governed
   writes reach these only through Core-approved, commit-preflighted,
   allowlisted execution.
2. Credential material: WordPress administrator Application Passwords
   (WordPress-owned), Adapter Ed25519 client key pairs (Adapter pairing state),
   and the Core app token (`NPCINK_OPENCLAW_ADAPTER_CORE_APP_TOKEN`, constant
   or environment only, never printed).
3. Sensitive read data: error-log tails, diagnostics detail, and other
   redline fields that direct-read abilities return.
4. Governance truth: Core proposal, approval, and audit records. Adapter
   bridge state (device pairings, preflight handoffs, execution records) is
   local bridge state and must never become a second truth source.
5. Observability metadata: events under
   `npcink_openclaw_adapter_observability_event` stay bounded and secret-free.

## Actors

| Actor | Trust | Note |
| --- | --- | --- |
| AI client (OpenClaw-compatible) | Authorized, error-prone | May hallucinate or be steered by prompt injection; must never hold final-write authority. |
| Operator / WordPress administrator | Trusted human | Approval authority; reviews proposals through the Core admin surface. |
| Holder of a leaked client credential | Adversary | Treat as the AI client with intent. |
| Unauthenticated network attacker | Adversary | Contained by WordPress REST authentication. |
| Other server-side code | Environment | Plugins and themes sharing the WordPress process. |

## Authentication modes and boundary classes

| Mode | Verified by | Effective authority | Boundary class |
| --- | --- | --- | --- |
| Application Password | WordPress core | Admin-equivalent, valid on all of `wp/v2` site-wide | Conventional |
| Ed25519 key pair (device pairing) | Adapter only | Adapter routes only | Enforced |
| Core app token | Core REST, server-to-server | Scoped internal calls such as `proposals:read` | Server-side only |

The boundary class is the honest core of this threat model:

- **Conventional.** With an Application Password, the approval gate is a
  convention the client must choose to honor. The same credential can call
  WordPress core REST (`wp/v2/*`) directly and mutate the site with no
  proposal, no approval, and no governance audit. Application Password
  connections are therefore a convenience mode, not an enforcement boundary.
- **Enforced.** With an Ed25519 key pair, WordPress core has no verifier for
  that credential. A client that holds only the paired key has no reachable
  path into WordPress except the Adapter surface, where every governed write
  still requires Core approval and commit-preflight.

Operator guidance: when the boundary must be enforceable, pair a key and do
not issue the client an Application Password. Review and revoke unused
administrator Application Passwords when key-pair pairing is in use.

## What the approval gate is designed to stop

- AI-initiated destructive or undesired writes, including hallucinated and
  prompt-injection-steered actions: proposal, human approval,
  commit-preflight, and allowlisted execution each fail closed.
- Write-scope drift: execution profiles accept only declared fields, reject
  undeclared write shapes, and cannot be extended through filters, options,
  database rows, remote configuration, wildcards, or arbitrary ability ids.
- Sensitive-data leakage: direct reads are redaction-bounded, and
  `core_read_authorization_required` reads demand a Core grant that Adapter
  re-verifies immediately before execution.
- Accountability gaps: `proposal_id`, `correlation_id`, and request context
  flow into Core audit and AI Request Logs correlation without merging the
  two log systems.

## Approval authority rule for the unified action

The Adapter unified `approve-and-execute` action programmatically approves a
pending Core proposal through the Adapter-held app token, so it carries the
human-approval step itself. To keep that step human, the route requires a
WordPress administrator session (cookie, Application Password, or basic auth
as a `manage_options` user). Signed client keys are denied at the permission
gate, at the client-key scope gate (no scope can ever allow the route), and
again inside the route handler; signed attempts fail closed with
`npcink_openclaw_adapter_approve_requires_admin_session`. A signed client's
governed final-write path is: create the proposal, wait for a human to
approve it in the Core admin, then call
`POST /proposals/{proposal_id}/execute` with commit intent. The
`npcink.execute` key scope grants execution of already-human-approved
proposals only; it never grants approval, and the pairing screen says so.

Note the honest boundary class distinction from the table above still
applies: an Application Password held by an AI client is admin-equivalent and
can reach the unified action (and `wp/v2`) directly. That is the documented
conventional mode; only Ed25519 key-pair clients are confined to the enforced
Adapter surface where self-approval is impossible.

## Explicit non-claims

Adapter does not defend against:

- A client (or credential thief) bypassing Adapter and calling WordPress core
  REST directly with an Application Password. See boundary classes above.
- A hostile or compromised WordPress administrator.
- Compromise of the WordPress host, database, or other server-side plugins.
- An operator approving proposals without reading them.
- Weak client-side storage of the private key, such as a plain CLI profile
  file. Production guidance remains OS keychain or the client credential
  vault.
- Attacks on the AI client itself: its process, its prompts, or its provider
  traffic. Adapter never controls a customer-selected client.

Security claims in documentation, admin UI copy, or marketing material must
stay inside this list. Do not describe Application Password connections as an
enforced boundary, and do not describe Adapter as protecting against threats
in the non-claims list.

## Fail-closed properties (restated as security invariants)

- No execution profile for an ability means no final write for that ability.
- No client key scope can authorize the unified approve-and-execute action;
  only a WordPress administrator session can.
- Undeclared input fields, invalid enums, or oversized values are rejected
  before the proposal or plan reaches Core.
- Missing Core or Toolkit dependencies fail closed with
  `npcink_openclaw_adapter_missing_dependency`.
- Sensitive reads without a valid, unexpired Core grant fail closed with
  `npcink_openclaw_adapter_core_read_authorization_required`.
- Repeating a completed execution returns
  `npcink_openclaw_adapter_execution_already_completed` instead of re-running
  the ability.
- `log_context`, caller annotations, and observability events stay bounded,
  capped, and secret-free regardless of client input.

## Boundary enforcement signaling

`client_policy.boundary_enforcement` on `/health`, `/help`, and
`/connection/manifest` reports the boundary class of the active connection
(`client_policy_version=2`): `enforced` with `auth_mode=ed25519_key_pair_signed`
means a key-pair signed connection, `conventional` with
`auth_mode=wordpress_native` means a WordPress-native credential. This
document is the semantic source for that field.
