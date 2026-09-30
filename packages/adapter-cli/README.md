# Npcink OpenClaw Adapter CLI

Local signed key-pair CLI for connecting OpenClaw-style clients to Npcink
Adapter without sending a WordPress Application Password to chat, prompts,
tool commands, logs, proposal payloads, or copied handoff text.

## Usage

Connect from the user's local machine:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter connect --site=https://example.com --profile=example
```

Check the local profile and signed Adapter health:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter status --profile=example
```

The status output includes derived `boundary` and `proposal_execution` fields.
In a healthy Adapter connection, `core_proxy_execute=false` and `commit_execution=false` indicate that Core keeps
final execution authority separate from Adapter diagnostics. The `boundary_enforcement` field
mirrors Adapter `client_policy.boundary_enforcement`: a signed CLI profile should report
`class=enforced`; `class=conventional` means the active credential can also reach `wp/v2`
directly, so the approval gate is voluntary for that client. To decide whether a specific
proposal can execute, read `GET /proposals/{proposal_id}` and then use the
Adapter approve-and-execute or execute routes after Core approval and
commit-preflight.

Call Adapter through the signed local wrapper:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter request --profile=example GET /health
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter request --profile=example GET /capabilities
```

Prefer the narrow read helpers for local AI client sessions. They build the
Adapter body, keep output redacted, and reduce route/JSON mistakes:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter read-request create --profile=example --ability-id=npcink-abilities-toolkit/wp-ops-diagnostics-detail --input-file=/tmp/read-input.json --purpose="Review bounded diagnostics" --data-classes=diagnostics,logs --redaction-level=strict --max-rows=10 --tail-lines=5 --denied-fields=authorization,cookie,application_password
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter read-request status --profile=example READ_REQUEST_ID
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter read-ability --profile=example --ability-id=npcink-abilities-toolkit/wp-ops-diagnostics-detail --input-file=/tmp/read-input.json --read-request-id=READ_REQUEST_ID
```

For AI-generated page visuals that need a stable aspect ratio, use the bounded
recipe helper. It reads `GET /help`, verifies
`openclaw_recipes.ai_image_ratio_crop_media_adoption`, accepts a reviewed
preview URL produced by Cloud Addon or Cloud tooling, and then builds the local
adoption plan. It does not create Cloud crop runs, approve proposals, or execute
final writes:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter recipe ai-image-ratio-crop-media-adoption inspect --profile=example
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter recipe ai-image-ratio-crop-media-adoption adoption-plan --profile=example --preview-url=PREVIEW_URL --post-id=7424 --old-url=OLD_URL --title="WordPress AI hero" --alt-text="WordPress AI proposal workflow hero" --source-type=ai_generated --attribution-text="AI-generated image reviewed before adoption"
```

Add `--submit-proposal` to `adoption-plan` only after reviewing the returned
plan. Final approval and execution still use the Core/Adapter approved proposal
flow.

Final Adapter write routes are guarded by client intent. Use
`--intent=preflight` for `POST /proposals/{proposal_id}/commit-preflight`.
Use `--intent=commit` only when the operator explicitly confirmed final write
execution:

```bash
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter request --profile=example POST /proposals/PROPOSAL_ID/commit-preflight --intent=preflight
cd ~ && npm exec --yes --package @npcink/openclaw-adapter-cli@0.3.0 -- npcink-openclaw-adapter request --profile=example POST /proposals/PROPOSAL_ID/approve-and-execute --intent=commit
```

The CLI refuses final execute routes when `--intent=commit` is missing, or when
the request body still contains preview markers such as `dry_run=true`,
`commit=false`, or `commit_execution=false`.

CLI output is redacted by default for local connection identifiers, profile
paths, key ids, signatures, authorization headers, cookies, tokens, passwords,
and secrets. The Adapter also exposes `client_policy` on `/connection/manifest`,
`/health`, and `/help` so AI clients can read a machine-readable policy before
choosing routes.

For local WordPress development sites with self-signed `.local` HTTPS, add
`--insecure-local-tls`.

## Security Boundary

The CLI generates the private key locally and stores it under
`~/.npcink-openclaw-adapter/keypair-profiles/`. Npcink OpenClaw Adapter stores only the
approved public key and verifies signed REST requests. Do not ask an AI client
to read, print, summarize, or copy the profile JSON file.
