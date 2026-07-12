# Adapter Admin Surface Standard

Status: active for `Npcink AI -> Adapter` when Toolbox is active, with
`Settings -> Npcink AI Client Adapter` as the standalone fallback.

## Purpose

The Adapter admin page is the OpenClaw connection surface. It helps an
administrator connect an AI client to this WordPress site while preserving
Adapter as a thin channel layer, Core as governance truth, and Abilities API as
the read execution source.

## Default View

The default page should answer only:

- is Adapter healthy;
- which site and Adapter URL this client should use;
- how to connect through the secure signed key-pair path;
- which signed key-pair devices are active and where to revoke
  them;
- how to fall back to a simple WordPress Application Password connection.

Primary actions:

- `Secure key pairing`, with `Copy connect command` as the primary
  action.
- `Manage devices`, which opens the active signed key-pair devices list
  without adding a separate control surface.
- `Fallback: WordPress Application Password connection`, with `Create
  Application Password connection` as the fallback action for clients that have
  a dedicated secret field.

Default copyable values:

- Adapter URL;
- signed key-pair connect command.

## Developer Reference

Do not put developer diagnostics on the default admin page. Keep these in
`docs/admin-developer-reference.md` or equivalent developer documentation:

- connection manifest URL;
- client env placeholders without secrets;
- signed key-pair status command;
- low-level key-pair client diagnostics;
- diagnostics URLs;
- `POST /run-read-ability` examples for underlying ability ids;
- proposal route examples;
- AI Request Logs correlation smoke details;
- disabled approval/rejection stub explanation;
- failure code mapping;
- verbose handoff prompt and local AI client session text.

## Created Handoff

After creating an Application Password, the completion page must prioritize the
one-time secret:

- show the Application Password in the first focused panel;
- provide a `Copy Application Password` action;
- keep manifest, env placeholders, WorkBuddy setup, and full handoff text
  behind detail disclosures;
- never pass the raw password into copied manifest, env placeholder, WorkBuddy,
  or handoff text.

## Time Display

Adapter may receive UTC timestamps from Core, local key-pair records, or REST
responses. Store and proxy those machine values without changing their contract
semantics, but format any timestamp shown in the wp-admin page through the
WordPress site timezone.

Visible admin timestamps must use `Y-m-d H:i:s`. Do not print raw UTC strings,
ISO timestamps, or `*_gmt` values directly in the admin UI unless the label
explicitly describes a machine/debug value.

## Do Not Add

Adapter admin must not add:

- Core proposal approval tables or audit tables;
- Proposal ID status lookup or a duplicate Core review queue on the main page;
- ability definitions, schema editing, or callback ownership;
- Cloud Base URL/API key, entitlement, billing, or runtime settings;
- workflow runtime, queues, MCP runtime, Agent Gateway catalogs, router,
  prompt, preset, or provider credential settings;
- workflow recipe registry or prompt/preset storage owned by
  `npcink-workflow-toolbox` or ability providers;
- execution profile allowlist ownership or ability callback ownership;
- AI Request Logs/Core audit merge UI or correlation ownership;
- generic approval/rejection proxy UX.

Proposal, route, and diagnostics details can exist in developer documentation
because they support integration work without turning the admin page into a
second control surface.

## Verification

Static contracts should preserve that Adapter is a thin OpenClaw channel:
reads go through WordPress Abilities API, governed writes go through Core, and
any execution remains supported after Core approval and commit-preflight.
