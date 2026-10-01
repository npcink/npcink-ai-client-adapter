# OpenClaw AI Image Ratio Crop Media Adoption Recipe

Status: active composition recipe.

Use this recipe when OpenClaw wants to use an AI-generated visual in a
Gutenberg page slot whose dimensions matter, such as a `16:9` hero image, a
`1:1` feature tile, or a `4:5` card image.

Cloud image recommendation should run before generation when a page brief can
match an existing, source-backed, owned, or previously generated candidate. AI
generation is the fallback when recommendation does not produce a reviewable
fit. AI image generation dimensions are advisory. If the page slot needs a
specific ratio, request a generated candidate, review it, crop it through Cloud
Addon or Cloud media derivative tooling, then adopt the cropped preview through a Core
media adoption proposal before the page references it.

Adapter does not generate images, choose providers, store Cloud artifact truth,
import media, crop images locally, or patch page content by itself.

## Contract

- Recipe id: `openclaw_recipes.ai_image_ratio_crop_media_adoption`
- Candidate contract: `image_candidate.v1`
- Optional candidate source ability: `npcink-toolbox/search-image-source`
- Optional hosted generation source: `npcink-toolbox/generate-image`
- Crop/runtime owner: `npcink-cloud-addon` or Cloud tooling, not Adapter routes
- Required handoff input: reviewed cropped preview URL with artifact provenance
- Adoption plan ability:
  `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`
- Handoff route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- Final route:
  `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`

Final WordPress writes remain Core-approved media and exact page-reference
actions:

- `npcink-abilities-toolkit/upload-media-from-url`
- `npcink-abilities-toolkit/optimize-media-asset`
- `npcink-abilities-toolkit/patch-post-content`

## Flow

1. Pick the target page slot before generation:
   - hero: `16:9`
   - square card or avatar-like tile: `1:1`
   - editorial card: `4:5`
   - wide product interface mock: `21:9` only when the page design needs it
2. Collect one or more `image_candidate.v1` options from the Cloud-backed
   image source recommender or another approved candidate source.
3. If recommendation produces no reviewable fit, use hosted AI image generation
   as a candidate source with provenance, prompt, `hosted_profile`, `model_id`,
   source artifact details, and artifact expiry when available.
4. Review the selected candidate. Reject images with unreadable UI text,
   unwanted logos, visible watermarking, license uncertainty, broken hands or
   faces when relevant, misleading product screenshots, or off-brand imagery.
5. If the selected candidate is already a local attachment, use Cloud Addon or
   approved Cloud tooling to request a bounded crop from `attachment_id`.
6. If the selected candidate is a same-site short-TTL Cloud artifact, use Cloud
   Addon or approved Cloud tooling to request a bounded crop from
   `source_artifact`.
7. If the selected candidate is only a remote URL, first adopt or import it
   through a Core-governed media adoption proposal, then crop the resulting
   local attachment. Do not ask Adapter to crop arbitrary remote URLs.
8. Poll and read the derivative result through Cloud Addon or Cloud tooling.
   Verify that the returned dimensions match the target aspect ratio and that
   warnings, if present, are acceptable.
9. Use the signed cropped preview URL immediately as the selected `url` in
   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`. If this
   replaces an image already referenced by a page, include `old_url` for exact
   patching.
10. Forward the returned `media_adoption_enhancement_plan` to Core through
   `POST /proposals/from-plan`, then execute only after Core approval and
   commit-preflight.
11. Verify the final local media URL with HTTP 200, expected content type,
    expected dimensions, page content readback, and Gutenberg block validity.

## Source Selection Policy

- `preferred_source=cloud_recommended_existing_candidate`
- `fallback_source=cloud_hosted_ai_generated_candidate`
- `fallback_condition=no_reviewable_candidate_matches_page_brief`
- `generated_artifact_must_be_cropped_before_adoption=true`
- `final_page_reference_must_be_local_wordpress_media_url=true`

## Example Crop Input

```json
{
  "attachment_id": 7774,
  "preferred_format": "webp",
  "quality": 84,
  "crop": {
    "type": "aspect_ratio",
    "aspect_ratio": "16:9",
    "position": "center"
  }
}
```

## Example Adoption Plan Input

Use the cropped preview URL only as a temporary source for the next governed
adoption proposal. The final page should reference the local optimized media URL
created by the proposal, not the preview URL.

```json
{
  "url": "https://example.test/cloud-addon-preview/artifact-id?preview_sig=...",
  "old_url": "https://example.test/wp-content/uploads/2026/06/old-hero.webp",
  "post_id": 7424,
  "title": "WordPress AI governed workflow hero",
  "alt_text": "WordPress AI proposal workflow dashboard hero",
  "preferred_format": "webp",
  "quality": 84
}
```

## Guardrails

- `target_aspect_ratio_required=true`
- `ai_generation_dimensions_are_advisory=true`
- `cloud_recommendation_precedes_generation=true`
- `cloud_crop_required_for_generated_images=true`
- `candidate_review_required=true`
- `reject_text_or_logo_artifacts=true`
- `signed_preview_is_temporary=true`
- `preview_url_must_be_adopted_before_expiry=true`
- `cloud_runtime_owner=npcink-cloud-addon`
- `adapter_media_derivative_routes=false`
- `proposal_mode=batch`
- `core_preflight_required=true`
- `core_proxy_execute=false`
- `commit_execution=false`
- `cloud_control_plane=false`
- `adapter_artifact_registry=false`
- `generic_write_executor=false`
- `direct_wordpress_write=false`

Use this recipe with
[`openclaw-pattern-page-with-visual-asset-recipe.md`](openclaw-pattern-page-with-visual-asset-recipe.md)
when a generated and cropped visual should become the hero media for a new
Gutenberg landing page.
