=== Npcink AI Client Adapter ===
Contributors: muze233
Tags: ai, governance, automation, rest-api
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: npcink-abilities-toolkit, npcink-governance-core
Stable tag: 0.3.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect local AI clients such as OpenClaw to governed WordPress abilities through a thin REST adapter.

== Description ==

Npcink AI Client Adapter connects local AI clients, such as OpenClaw-compatible tools, to a WordPress site through a focused REST adapter.

It is designed for sites that use Npcink Governance Core for reviewable AI operations and WordPress Abilities API providers, such as Npcink Abilities Toolkit, for concrete WordPress capabilities. The Adapter gives clients one stable WordPress REST surface for checking readiness, reading governance guidance, running approved read abilities, creating governed proposals, and executing supported writes only after Core approval and commit-preflight.

The default connection path uses signed key-pair pairing so the AI client does not need to receive a WordPress Application Password. A WordPress Application Password fallback is available for clients that already have a dedicated secret field or credential vault.

Adapter is intentionally thin. It does not define abilities, store approval truth, run workflow queues, act as an MCP server, manage model providers, store prompts, or execute arbitrary final write mutations. Governance remains in Npcink Governance Core. Ability definitions and callbacks remain in Npcink Abilities Toolkit or another WordPress Abilities API provider.

Adapter exposes a machine-readable `client_policy` on health, help, and the connection manifest so compatible clients can consume explicit route, output, sensitive-read, and write-flow boundaries. The local CLI also redacts profile paths, key ids, signing headers, tokens, passwords, and secrets from output.

Current governed execution support covers explicit Adapter profiles after Core approval and commit-preflight: `npcink-abilities-toolkit/trash-post`, `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/update-post`, `npcink-abilities-toolkit/patch-post-content`, `npcink-abilities-toolkit/update-post-blocks`, `npcink-abilities-toolkit/update-template-blocks`, `npcink-abilities-toolkit/upsert-template-blocks`, `npcink-abilities-toolkit/update-template-part-blocks`, `npcink-abilities-toolkit/patch-setting-value`, `npcink-abilities-toolkit/set-post-seo-meta`, `npcink-abilities-toolkit/adopt-article-audio`, `npcink-abilities-toolkit/set-post-slug`, `npcink-abilities-toolkit/set-post-terms`, `npcink-abilities-toolkit/delete-term`, `npcink-abilities-toolkit/update-media-details`, `npcink-abilities-toolkit/upload-media-from-url`, `npcink-abilities-toolkit/set-post-featured-image`, `npcink-abilities-toolkit/optimize-media-asset`, `npcink-abilities-toolkit/replace-media-file`, `npcink-abilities-toolkit/restore-media-backup`, `npcink-abilities-toolkit/adopt-cloud-media-derivative`, `npcink-abilities-toolkit/rename-media-file`, `npcink-abilities-toolkit/delete-media-permanently`, `npcink-abilities-toolkit/reply-comment`, `npcink-abilities-toolkit/trash-comment`, and `npcink-abilities-toolkit/approve-comment`.

Key features:

* Connect OpenClaw-compatible and similar local AI clients to WordPress through one Adapter REST namespace.
* Check Adapter readiness and dependency status before a client starts work.
* Expose a machine-readable `client_policy` so clients can understand route, read, write, and sensitive-data boundaries.
* Route approved direct-read requests through the WordPress Abilities API.
* Forward governed write requests to Npcink Governance Core proposal and commit-preflight endpoints.
* Support a user-triggered approve-and-execute path for explicit, supported execution profiles after Core approval.
* Prefer signed key-pair pairing for local clients, with an Application Password fallback when appropriate.
* Keep channel, governance, ability, cloud, and model-provider responsibilities separate.

== Installation ==

1. Install and activate `npcink-abilities-toolkit`.
2. Install and activate `npcink-governance-core`.
3. Install and activate Npcink AI Client Adapter.
4. Open Npcink > Adapter to create the local AI client handoff.

== Frequently Asked Questions ==

= Does Adapter approve proposals? =

Adapter provides a user-triggered `approve-and-execute` action for supported execution profiles, but Npcink Governance Core remains the governance backend for proposal storage, approval, commit-preflight, and audit.
Execution abilities are declared by Npcink Abilities Toolkit and approved by Npcink Governance Core; Adapter supplies only the post-preflight channel.

= Does Adapter execute arbitrary abilities? =

No. Adapter executes only explicit, supported execution profiles after Core approval and commit-preflight. It is not a generic ability executor and does not bypass Core governance.

= Do I need other Npcink plugins? =

Yes. Npcink Abilities Toolkit provides the ability definitions and callbacks used by the Adapter. Npcink Governance Core is required for governed proposals, approval, commit-preflight, and audit.

= Which AI clients can connect? =

Adapter is built for OpenClaw-compatible local clients and similar tools that can call authenticated WordPress REST endpoints and consume the Adapter `client_policy`.

= Does Adapter send my WordPress password to an AI client? =

The recommended signed key-pair path avoids giving the client a WordPress Application Password. Adapter stores only the approved public key. The Application Password path remains available as a fallback for clients with a dedicated secret field or credential vault.

= Does Adapter run AI models or store prompts? =

No. Adapter is a channel layer. It does not provide model routing, prompt management, provider credentials, workflow queues, or hosted AI execution.

= What happens if dependencies are missing? =

Adapter health and help routes can report dependency status. Routes that require Npcink Governance Core or a WordPress Abilities API provider fail closed with a structured missing dependency error.

== Screenshots ==

1. Adapter connection overview with site readiness, active device count, and the recommended signed key-pair pairing flow.
2. Active key-pair devices with device identifiers, last-used timestamps, status labels, revoke actions, and a revoked-device summary.
3. WordPress Application Password fallback connection flow for clients that use a dedicated secret field or credential vault.

== Changelog ==

= 0.3.2 =

* Close the release-candidate boundary by keeping media derivative transport outside Adapter and retaining only reviewed adoption handoff.
* Require explicit signed-client execute scope for final Adapter write routes.
* Restore Core capability forwarding and default execution response compatibility for consumer smoke coverage.

= 0.3.1 =

* Bind Adapter final execution to Core approval and commit-preflight execution handoffs.
* Forward signed local client fingerprints to trusted Core app-token requests and fail closed on mismatched Core contexts.
* Validate Core dependency contract semantics for site and signed-client bindings before reporting dependency readiness.

= 0.3.0 =

* Add Adapter-declared Core and Abilities Toolkit compatibility floors to the machine-readable contract metadata.
* Record a signed local AI client create-draft proposal, approve-and-execute, readback, and cleanup acceptance pass.

= 0.2.1 =

* Add contract snapshot smoke coverage across health, help, and connection manifest responses.
* Reject batch write actions that request `core_proxy_execute=true` before Adapter execution.
* Expand negative smoke coverage for `core_proxy_execute` and `commit_execution` batch fail-closed behavior.

= 0.2.0 =

* Add machine-readable Adapter contract metadata and stable contract hashes on health, help, and manifest responses.
* Add the local AI client acceptance command for non-destructive Adapter/Core boundary checks.
* Align block theme visual acceptance, batch review feedback, and release package smoke documentation with the governed execution profile boundary.

= 0.1.1 =

* Add local AI client CLI helper commands, output redaction, and machine-readable client policy.

= 0.1.0 =

* Initial thin AI client adapter for Npcink Governance Core and WordPress Abilities API routing.
