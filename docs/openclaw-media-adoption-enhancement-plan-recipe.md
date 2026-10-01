# OpenClaw Media Adoption Enhancement Plan Recipe

Status: active.

Use this recipe when OpenClaw or another AI client has already selected and
reviewed one remote visual asset, and the local WordPress site needs one Core
batch proposal to import it, generate an optimized local derivative, and
optionally replace an existing post or page media URL with the optimized
derivative URL.

This is a governed handoff, not a direct media write path.

## Contract

- Plan ability:
  `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`
- Plan artifact: `media_adoption_enhancement_plan`
- Handoff route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- Status route: `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}`
- Final route:
  `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`
- Final write abilities:
  `npcink-abilities-toolkit/upload-media-from-url`,
  `npcink-abilities-toolkit/optimize-media-asset`, and optional
  `npcink-abilities-toolkit/patch-post-content`

## Flow

1. Select and review one remote image URL outside Adapter. Preserve source,
   license, attribution, and provenance evidence in the plan input.
2. Run `npcink-abilities-toolkit/build-media-adoption-enhancement-plan` through
   `POST /run-read-ability`.
3. Forward the returned `media_adoption_enhancement_plan` to Core through
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`.
4. Poll the proposal and show the ordered batch actions to the operator.
5. Only after Core approval and commit preflight, call
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`.

## Guardrails

- `core_proxy_execute=false`
- `commit_execution=false`
- `batch_approval=true`
- `cloud_control_plane=false`
- `generic_write_executor=false`
- `direct_wordpress_write=false`
- Adapter does not search image providers, generate images, import media, create
  optimized derivatives, patch posts, or store execution truth before Core
  approval.
- The optional post-content repair must be an exact replacement from a reviewed
  old URL to the optimized derivative output reference.
- The selected asset must be a reviewed input. This recipe is not a search,
  generation, or provider-routing feature.
