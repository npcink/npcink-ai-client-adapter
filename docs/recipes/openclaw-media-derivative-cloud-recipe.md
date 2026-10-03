# OpenClaw Media Derivative Cloud Recipe

Status: active Cloud Addon seam recipe

This recipe lets OpenClaw request an optimized media derivative while keeping
WordPress write ownership local.

## Ownership

- Core owns local media policy defaults, proposal governance, approval,
  commit-preflight, and audit.
- Abilities own the canonical
  `npcink-abilities-toolkit/build-media-derivative-cloud-request` read-only contract and the
  `npcink-abilities-toolkit/build-media-derivative-batch-plan` read-only candidate planner.
- Cloud Addon owns Cloud credentials, signing, media derivative transport, run
  reads, and result reads.
- Cloud owns runtime processing and short-TTL derivative artifacts.
- Adapter owns only the OpenClaw channel, local read ability routing, Core
  proposal handoff, and approved execution projection.

Adapter must not store Cloud API keys, register `/cloud/*` or
`/media-derivative-*` routes, create a media or artifact registry, approve
adoption, update attachment metadata, or replace media files.

## Flow

0. Optional bulk planning through `POST /run-read-ability`
   - Ability: `npcink-abilities-toolkit/build-media-derivative-batch-plan`.
   - Use for requests like "convert April media library images to PNG".
   - Inputs may include `date_from`, `date_to`, `target_format`,
     `exclude_formats`, size/dimension filters, and `max_items`.
   - Returns candidates, skipped reasons, and per-candidate
     `cloud_request_input`.
   - Does not call Cloud, create proposals, approve writes, or mutate
     WordPress.

1. Cloud Addon derivative run creation
   - Required: `input.attachment_id`.
   - Optional input: `preferred_format` or `target_format`, `target_max_width`
     or `max_width`, `quality`, bounded aspect-ratio `crop`, and `watermark`.
   - Optional artifact descriptors: `source_artifact`,
     `watermark_artifact`.
   - Cloud Addon builds or receives the local ability request, attaches the
     local source file when no source artifact reference is supplied, and
     dispatches to Cloud.

2. Cloud Addon derivative run status
   - Polls Cloud run status through Cloud Addon.
   - Adapter stores no run truth.

3. Cloud Addon derivative run result
   - Reads the Cloud result projection through Cloud Addon.
   - The result should include derivative artifact evidence such as
     `artifact_id`, `download_url`, `expires_at`, `mime_type`, dimensions,
     filesize, checksum, and warnings when available.
   - The derivative projection may include a reviewed same-origin preview URL
     owned by Cloud Addon or Cloud tooling.

4. Cloud Addon artifact preview
   - Requires the auth or short-lived preview signature defined by Cloud Addon,
     plus a non-expired artifact descriptor.
   - Cloud Addon signs the Cloud artifact download and streams the bytes as a
     local preview only.
   - Does not store artifact truth, expose a public Cloud URL, or write
     WordPress media.

5. `POST /run-read-ability`
   - Ability: `npcink-abilities-toolkit/build-media-adoption-preflight-summary`.
   - Input: `attachment_id`, the selected `derivative_artifact`, and optional
     reviewed `file_name`.
   - Returns local read-only adoption readiness, derivative comparison,
     content-reference impact, and next-step guidance.
   - Does not call Cloud, create proposals, return `write_actions`, approve,
     preflight, or write WordPress.

6. `POST /run-read-ability`
   - Ability: `npcink-abilities-toolkit/build-media-optimization-plan` or
     `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`.
   - Input: reviewed preview URL, Cloud result evidence, derivative artifact
     details, and optional reviewed `media_details_input`.
   - Returns a Core-ready plan for `POST /proposals/from-plan`.
   - When reviewed metadata is missing for an optimize-image request, stop and
     collect it; do not create a derivative-only Core proposal for the same user
     intent.
   - Does not create, approve, preflight, or execute a proposal.

7. `POST /proposals/from-plan`
   - For the user intent "optimize this media item", submit the reviewed plan.
   - Core creates one batch proposal with `input.write_actions[]` containing
     `npcink-abilities-toolkit/update-media-details` and
     `npcink-abilities-toolkit/adopt-cloud-media-derivative`.
   - If Core reports the media optimization plan ability is unavailable,
     surface the capability/version guard and update the local Abilities/Core
     stack; do not split this user intent into two proposals.
   - For lower-level derivative-only review, the legacy single
     `proposal_payload` remains available.
   - Adapter may later execute that approved local write ability, but it does
     not download artifacts or write WordPress media itself.

## Guardrails

- `final_write_owner=local_wordpress_host`.
- `wordpress_write_included=false`.
- `attachment_metadata_write_included=false`.
- Cloud artifacts are short-TTL processing artifacts, not canonical truth.
- Adapter returns derivative artifact and processing evidence only.
- Bulk conversion must start with a bounded local batch plan and proceed through
  reviewed per-attachment preview runs.
- Run the local adoption preflight summary before Core proposal submission for
  reviewed artifact readiness and content-reference impact.
- Final adoption requires Core proposal approval and commit preflight.
