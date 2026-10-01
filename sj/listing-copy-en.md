# WordPress.org Listing Draft - English

## Plugin Name

Npcink AI Client Adapter

## Short Description

Connect local AI clients such as OpenClaw to governed WordPress abilities through
a thin REST adapter.

## Tags

ai, governance, automation, rest-api, abilities

## Description

Npcink AI Client Adapter connects local AI clients, such as OpenClaw-compatible
tools, to a WordPress site through a focused REST adapter.

It is designed for sites that use Npcink Governance Core for reviewable AI
operations and WordPress Abilities API providers, such as Npcink Abilities
Toolkit, for concrete WordPress capabilities. The Adapter gives clients one
stable WordPress REST surface for checking readiness, reading governance
guidance, running approved read abilities, creating governed proposals, and
executing supported writes only after Core approval and commit-preflight.

The default connection path uses signed key-pair pairing so the AI client does
not need to receive a WordPress Application Password. A WordPress Application
Password fallback is available for clients that already have a dedicated secret
field or credential vault.

Adapter is intentionally thin. It does not define abilities, store approval
truth, run workflow queues, act as an MCP server, manage model providers, store
prompts, or execute arbitrary final write mutations. Governance remains in
Npcink Governance Core. Ability definitions and callbacks remain in Npcink
Abilities Toolkit or another WordPress Abilities API provider.

Adapter exposes a machine-readable `client_policy` on health, help, and the
connection manifest so compatible clients can consume explicit route, output,
sensitive-read, and write-flow boundaries. The local CLI also redacts profile
paths, key ids, signing headers, tokens, passwords, and secrets from output.

Current governed execution support covers explicit Adapter profiles after Core
approval, including `npcink-abilities-toolkit/trash-post`,
`npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/update-post`,
`npcink-abilities-toolkit/update-post-blocks`, `npcink-abilities-toolkit/set-post-terms`,
`npcink-abilities-toolkit/reply-comment`, and
`npcink-abilities-toolkit/approve-comment`.

## Key Features

- Connect OpenClaw-compatible and similar local AI clients to WordPress through
  one Adapter REST namespace.
- Check Adapter readiness and dependency status before a client starts work.
- Expose a machine-readable `client_policy` so clients can understand route,
  read, write, and sensitive-data boundaries.
- Route approved direct-read requests through the WordPress Abilities API.
- Forward governed write requests to Npcink Governance Core proposal and
  commit-preflight endpoints.
- Support human-approved final execution for explicit, allowlisted execution
  profiles after Core approval, plus a unified approve-and-execute action
  reserved for WordPress administrator sessions.
- Prefer signed key-pair pairing for local clients, with an Application Password
  fallback when appropriate.
- Keep channel, governance, ability, cloud, and model-provider responsibilities
  separate.

## Who This Is For

- WordPress administrators connecting OpenClaw-compatible or similar local AI
  clients to a Npcink site.
- AI client environments that need one authenticated WordPress REST entry point.
- Host setups that use Core for governance and Abilities API providers for
  execution contracts.
- Developers who want a clear separation between channel, governance, ability,
  cloud, and model-provider layers.

## Requirements

- WordPress 7.0 or later.
- PHP 8.0 or later.
- Npcink Abilities Toolkit for ability definitions and callbacks.
- Npcink Governance Core for governed proposals, approval, commit-preflight, and
  audit.

## Frequently Asked Questions

### What does Npcink AI Client Adapter do?

It gives OpenClaw-compatible and similar local AI clients a focused WordPress
REST adapter for connecting to Npcink Governance Core and WordPress Abilities
API providers.

### Does Adapter approve proposals?

Adapter provides an `approve-and-execute` action for supported execution
profiles, but it is reserved for WordPress administrator sessions. Signed AI
clients wait for human approval in the Core admin and then execute.
Npcink Governance Core remains the governance backend
for proposal storage, approval, commit-preflight, and audit.

### Does Adapter execute arbitrary abilities?

No. Adapter executes only explicit, supported execution profiles after Core
approval and commit-preflight. It is not a generic ability executor and does not
bypass Core governance.

### Do I need other Npcink plugins?

Yes. Npcink Abilities Toolkit provides the ability definitions and callbacks
used by the Adapter. Npcink Governance Core is required for governed proposals,
approval, commit-preflight, and audit.

### Which AI clients can connect?

Adapter is built for OpenClaw-compatible local clients and similar tools that
can call authenticated WordPress REST endpoints and consume the Adapter
`client_policy`.

### Does Adapter send my WordPress password to an AI client?

The recommended signed key-pair path avoids giving the client a WordPress
Application Password. Adapter stores only the approved public key. The
Application Password path remains available as a fallback for clients with a
dedicated secret field or credential vault.

### Does Adapter run AI models or store prompts?

No. Adapter is a channel layer. It does not provide model routing, prompt
management, provider credentials, workflow queues, or hosted AI execution.

### What happens if dependencies are missing?

Adapter health and help routes can report dependency status. Routes that
require Npcink Governance Core or a WordPress Abilities API provider fail closed
with a structured missing dependency error.

## Screenshots

1. Adapter connection overview with site readiness, active device count, and the
   recommended signed key-pair pairing flow.
2. Active key-pair devices with device identifiers, last-used timestamps, status
   labels, revoke actions, and a revoked-device summary.
3. WordPress Application Password fallback connection flow for clients that use
   a dedicated secret field or credential vault.

## Series Boundary

In the Npcink plugin family:

- Npcink Abilities Toolkit owns ability definitions and callbacks.
- Npcink Governance Core owns governance, approval, preflight, and audit.
- Npcink AI Client Adapter owns AI client channel adaptation.
- Npcink Cloud Addon owns cloud service connection.

This separation keeps Adapter focused on connection and routing, while Core
remains the governance truth source and Abilities remains the capability
contract layer.
