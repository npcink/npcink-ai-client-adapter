# OpenClaw Image Candidate Adoption Plan Recipe

Status: active.

Use this recipe when OpenClaw or another AI client has a reviewed image
candidate and needs to ask the local WordPress site to import it, write media
details, and optionally set it as a post's featured image.

This is a governed handoff, not a direct media write path.
For Gutenberg landing pages that need this reviewed asset as the hero media,
compose this recipe with `pattern_page_plan` as described in
[`openclaw-pattern-page-with-visual-asset-recipe.md`](openclaw-pattern-page-with-visual-asset-recipe.md).

## Contract

- Candidate contract: `image_candidate.v1`
- Plan ability: `npcink-toolbox/build-image-candidate-adoption-plan`
- Plan artifact: `image_candidate_adoption_plan`
- Handoff route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- Status route: `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}`
- Final route:
  `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`
- Final write abilities:
  `npcink-abilities-toolkit/upload-media-from-url`,
  `npcink-abilities-toolkit/update-media-details`, and optional
  `npcink-abilities-toolkit/set-post-featured-image`

## Flow

1. Collect image candidates through
   `npcink-toolbox/search-image-source` or another approved direct-read
   source that returns `image_candidate.v1`.
2. Let the operator select one candidate and review license, attribution,
   prompt/model provenance, and warnings.
3. Run `npcink-toolbox/build-image-candidate-adoption-plan` through
   `POST /run-read-ability`.
4. Forward the returned `image_candidate_adoption_plan` to Core through
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`.
5. Poll the proposal.
6. Only after Core approval and commit preflight, call
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`.

## Guardrails

- `core_proxy_execute=false`
- `commit_execution=false`
- `batch_approval=true`
- `cloud_control_plane=false`
- `generic_write_executor=false`
- The selected image candidate must preserve attribution and provenance.
- Stock search, AI image generation, Cloud runtime, and customer connectors
  remain candidate sources only.
- Adapter does not search providers, generate images, import media, set
  featured images, or maintain a media registry before Core approval.
- Cloud image generation, when present, returns only short-lived candidate
  artifacts and runtime evidence.
