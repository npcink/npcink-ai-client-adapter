# Npcink AI Suite Distribution Contract

Status: active packaging contract.

Npcink AI Client Adapter is the productized entry plugin for OpenClaw-compatible and similar AI clients. The
suite distribution makes installation feel unified while preserving separate
plugin ownership.

## Package Shape

The suite ships separate WordPress plugin zips:

- `npcink-ai-client-adapter.zip`
- `npcink-governance-core.zip`
- `npcink-abilities-toolkit.zip`

The suite archive may include these zips in one install bundle, but each plugin
keeps its own plugin header, text domain, REST namespace, data ownership, tests,
and release gate.

## Slug Policy

`npcink-abilities-toolkit` and `npcink-governance-core` are the current
WordPress.org dependency slugs and are declared in Adapter's `Requires Plugins`
header.

Adapter's own slug is treated as a distribution contract value until its public
WordPress.org slug is finalized. Runtime checks must therefore prefer observable
interfaces over display names:

- Core readiness is detected by
  `/wp-json/npcink-governance-core/v1/capabilities`.
- WordPress Abilities API readiness is detected by
  `/wp-json/wp-abilities/v1/abilities`.
- Toolkit readiness is detected by
  `npcink_abilities_toolkit_get_registered()`.

If names change later, update plugin headers, readmes, the suite version
matrix, and packaging defaults together. Do not change Core proposal, audit, or
Adapter execution ownership as part of a naming change.

## Local CLI Versioning

The local signed-key CLI (`packages/adapter-cli`, published as
`@npcink/openclaw-adapter-cli` on npm) carries its own semver that is
deliberately independent of the plugin version (for example plugin 0.4.1 with
CLI 0.6.0). The CLI is a client-side companion, not part of the WordPress
release zip, so it releases on its own cadence.

The two versions are coupled only through the wire contract, not the numbers:

- The CLI must speak the Adapter REST namespace and the `client_policy`
  published on `/health`, `/help`, and `/connection/manifest`.
- Adapter contract floors (for example `adapter_contract_version`) are the
  compatibility surface; a plugin bump does not require a CLI bump unless the
  contract versions change.
- When a plugin release changes signing, pairing, or policy semantics, record
  the CLI companion change in the release closeout.

## Runtime Contract

Adapter must keep `/health` and `/help` usable even when dependencies are
missing. Dependency-sensitive routes fail closed with
`npcink_openclaw_adapter_missing_dependency` instead of producing generic
upstream REST errors.

Required runtime dependencies:

- Core: capabilities, proposals, commit preflight, approved execution.
- WordPress Abilities API: direct reads and approved ability execution.
- Toolkit: reference Npcink abilities, read shortcuts, and execution profiles.

## Implementation Summary

The current implementation makes Adapter the distribution entry point without
moving Core or Toolkit code into Adapter:

- Adapter declares the confirmed WordPress.org dependency slugs
  `npcink-abilities-toolkit` and `npcink-governance-core` in the plugin header
  and WordPress readme.
- Adapter `/health` reports `dependencies_ready`, `missing_dependencies`, and
  per-dependency detector details for Core, WordPress Abilities API, and
  Toolkit.
- Adapter `/help` includes the same dependency map for client onboarding.
- Core and WordPress Abilities API upstream calls are checked before dispatch;
  missing dependencies return `npcink_openclaw_adapter_missing_dependency` with
  the missing dependency id and detector.
- `composer package:suite` builds a distribution archive containing separate
  plugin zips and a generated version matrix.

## Ownership Boundary

Distribution unifies installation, not responsibilities:

- Adapter owns OpenClaw-facing routes and post-Core execution profiles.
- Core owns proposal records, approval, preflight, app keys, rate limits, and
  audit truth.
- Toolkit owns reusable WordPress Abilities API definitions, schemas,
  permission callbacks, dry-run previews, and ability callbacks.

Do not copy Core proposal storage into Adapter. Do not copy Toolkit ability
definitions into Adapter or Core.
