# OpenClaw Connection Model Notes

Status: accepted working notes for future humans and agents.

Date: 2026-06-04.

This document summarizes the product and security decisions behind the
`Npcink -> Adapter` connection flow. Read this before changing handoff,
credential, OpenClaw, WorkBuddy, or local CLI behavior.

## Current Product Shape

The Adapter admin page intentionally uses two connection paths:

1. **Default: simple Application Password connection**
   - This is the first path shown to users because it is easiest to understand
     and works with common HTTP clients.
   - It is only appropriate when the AI client has a dedicated password,
     credential, or secret field.
   - WordPress creates the Application Password and shows the raw value once in
     the browser. WordPress stores only its hash. Adapter does not store the raw
     password.
   - Any copied env, manifest, handoff text, prompt text, curl example, proposal
     payload, file, or log must contain only placeholders such as
     `<openclaw-secret-field-value>` or
     `<store-in-openclaw-secret-vault>`.

2. **Higher security: local signed key-pair**
   - This is the security recommendation when the client should not receive an
     Application Password, or when the client has no clear credential field.
   - The local CLI generates an Ed25519 key pair on the same machine or
     execution environment as OpenClaw-like clients.
   - Adapter stores only the approved public key and verifies signed Adapter
     requests. The local private key stays in the local profile.
   - Administrators can revoke authorized public keys from the Adapter admin
     page.

The order is a deliberate product decision: default simple setup for adoption,
with the stronger key-pair option visible as the safer path.

## Deployment Assumptions

Most users deploy WordPress on a server while OpenClaw-like tools run on the
user's local machine. Therefore:

- Do not assume the local CLI exists on the WordPress server.
- Do not ask users to upload CLI files to WordPress hosting.
- Do not serve executable JavaScript from the WordPress site for OpenClaw to run.
- A WordPress URL may expose only non-secret connection metadata, such as a
  manifest URL.
- Local signing tools must run beside the local AI client, not inside Adapter.

## Published CLI

The current test package is:

```bash
@npcink/openclaw-adapter-cli@0.5.0
```

Use this command form in user-facing copy:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.5.0 -- npcink-openclaw-adapter status --profile=local
```

The `cd ~ &&` prefix is intentional. Running package commands from inside the
package source directory can cause npm to resolve local project context and
fail with:

```text
sh: npcink-openclaw-adapter: command not found
```

When the official npm organization exists, the package can move to a product
scope such as `@npcink-abilities-toolkit/adapter-cli`, but update all admin copy, docs, and
tests together.

## Secret Handling Rules

Application Password values may be shown once to the browser user after
WordPress creates them. They must never be embedded in:

- OpenClaw prompt or handoff text;
- env textareas or copied setup blocks;
- tool commands;
- logs;
- proposal payloads;
- files;
- example curl commands;
- non-secret manifests.

If a client does not have a dedicated credential field, do not tell the user to
paste an Application Password into chat. Use the signed key-pair path instead.

## WorkBuddy and Other OpenClaw-Like Clients

OpenClaw itself documents secret handling, but WorkBuddy and similar tools may
not expose an equivalent dedicated credential field. Do not assume all clients
have a secret vault.

For unknown clients:

- If there is a clear password/credential/secret field, the simple Application
  Password path is acceptable.
- If there is no clear secret field, use the local signed key-pair path.
- The AI client should call the request wrapper; it should not read, print,
  summarize, or copy `~/.npcink-openclaw-adapter/keypair-profiles/*.json`.

## Explicit Non-Goals

Do not move these responsibilities into Adapter:

- workflow runtime;
- durable queue;
- scheduler;
- MCP runtime or Agent Gateway catalog;
- prompt/preset console;
- provider credential store;
- model router;
- approval store;
- generic final WordPress write executor;
- Cloud control plane or Cloud OpenClaw platform.

Core remains the proposal, approval, preflight, execution-outcome, and audit
truth. Adapter remains the thin OpenClaw channel layer and final supported
executor after Core preflight.

## Rejected Alternatives

### Plugin-generated private keys

Rejected because it makes WordPress export a usable secret to OpenClaw or the
browser. The public/private split only helps if the private key is generated and
kept local to the client.

### Running JavaScript from a WordPress URL

Rejected because `curl | node`, `node https://...`, or remote executable script
handoff gives poor auditability and would make a compromised WordPress site a
local code delivery channel.

### Adapter-owned MCP broker

Rejected because Adapter does not own MCP runtime. If a client needs an MCP
integration, it belongs outside Adapter or in a dedicated client-side bridge.

### Defaulting to key-pair only

Rejected for the current product stage because it creates too much setup
friction for normal users. It remains the visible higher-security option.

## Acceptance Checks

Before shipping changes to this area, verify:

```bash
composer test:all
npm --prefix packages/adapter-cli run check
git diff --check
rg "<secret-danger-pattern from the current acceptance checklist>" .
rg "wp_insert_post|wp_update_post|workflow runtime|durable queue|scheduler" /Users/muze/gitee/npcink-openclaw-adapter
```

Expected notes:

- The secret-danger scan should not find old unsafe expressions.
- Boundary scans may hit existing docs or smoke fixtures; do not introduce new
  Adapter runtime, queue, scheduler, or final write ownership.
