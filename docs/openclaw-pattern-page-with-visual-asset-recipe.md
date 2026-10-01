# OpenClaw Pattern Page With Visual Asset Recipe

Status: active.

Use this composed recipe when OpenClaw needs a higher-quality Gutenberg landing
page that uses a reviewed image from stock search, owned media, external media,
or hosted image generation.
If the page also needs reference-site research, run
[`openclaw-pattern-page-research-brief-recipe.md`](openclaw-pattern-page-research-brief-recipe.md)
first and use the reviewed brief to choose the visual brief and page variables.

This is a two-stage governed handoff. OpenClaw may use Cloud-backed image
recommendation first, then hosted AI image generation as a fallback when no
reviewable candidate fits the visual brief. Generated dimensions are advisory:
before the page plan references the asset, the selected candidate must go
through the Cloud media derivative crop/format path and then be adopted into the
local WordPress media library through Core approval.

Adapter does not generate images, select providers, upload media, crop media,
or write page blocks by itself.

## Contract

- Composition artifact:
  `openclaw_recipes.pattern_page_with_visual_asset_plan`
- Candidate contract: `image_candidate.v1`
- Recommended image source ability:
  `npcink-toolbox/search-image-source`
- Hosted generation fallback ability:
  `npcink-toolbox/generate-image`
- Cloud crop recipe:
  `openclaw_recipes.ai_image_ratio_crop_media_adoption`
- Image adoption plan ability:
  `npcink-toolbox/build-image-candidate-adoption-plan`
- Media adoption enhancement ability:
  `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`
- Page pattern plan ability:
  `npcink-abilities-toolkit/build-pattern-page-plan`
- Handoff route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- Final route:
  `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`

Final WordPress writes stay split across Core-approved proposals:

- media stage:
  `npcink-abilities-toolkit/upload-media-from-url`,
  `npcink-abilities-toolkit/optimize-media-asset`,
  `npcink-abilities-toolkit/update-media-details`, and optional
  `npcink-abilities-toolkit/set-post-featured-image`;
- page stage:
  `npcink-abilities-toolkit/create-draft` and
  `npcink-abilities-toolkit/update-post-blocks`.

## Flow

1. Optionally use a reviewed `landing_page_research_brief` to shape the visual
   brief, section plan, comparison angles, and FAQ seeds.
2. Build a visual brief from the page request:
   `hero_title`, `hero_description`, product surface, desired asset type,
   target audience, and `hero_media_alt`.
3. Ask the Cloud-backed image source recommender for source-backed, owned, or
   previously generated `image_candidate.v1` options that match the visual
   brief.
4. If no candidate is good enough, request a hosted AI-generated candidate.
   Keep `hosted_profile`, `model_id`, prompt, source, warnings, and artifact
   expiry visible for review.
5. Let the operator select one candidate and review source URL, license,
   attribution, warnings, prompt, `hosted_profile`, and `model_id` when present.
6. Use `openclaw_recipes.ai_image_ratio_crop_media_adoption` to crop the
   selected candidate to the target slot ratio, normally `16:9`, and convert to
   the preferred delivery format, normally `webp`.
7. Run `npcink-toolbox/build-image-candidate-adoption-plan` or
   `npcink-abilities-toolkit/build-media-adoption-enhancement-plan` against the
   cropped candidate.
8. Forward the returned `image_candidate_adoption_plan` or
   `media_adoption_enhancement_plan` to Core through
   `POST /proposals/from-plan`, then execute only after Core approval and
   commit-preflight.
9. Use the approved local WordPress media URL from the media adoption result as
   `variables.hero_media_url`.
10. Run `npcink-abilities-toolkit/build-pattern-page-plan` with
   `media_strategy=existing_media_url`.
11. Forward the returned `pattern_page_plan` to Core, then execute only after
   Core approval and commit-preflight.
12. Verify block readback and browser visual acceptance using
   [`openclaw-gutenberg-visual-acceptance.md`](openclaw-gutenberg-visual-acceptance.md).

## Media Source Strategy

Preferred order:

1. `cloud_recommended_existing_candidate`
2. `cloud_hosted_ai_generated_candidate`

Fallback policy:
`generate_when_no_reviewable_candidate_matches_the_page_brief`.

Processing requirements:

- `target_slot=hero`
- `target_aspect_ratio=16:9`
- `preferred_format=webp`
- `cloud_derivative_required=true`
- `cloud_derivative_recipe_id=ai_image_ratio_crop_media_adoption`
- `page_plan_must_reference_final_local_wordpress_media_url=true`

## Page Plan Input After Media Adoption

```json
{
  "post_type": "page",
  "status": "draft",
  "title": "WordPress AI",
  "pattern_id": "openai-style-landing",
  "style_preset": "minimal-dark-light",
  "responsive_profile": "landing_standard",
  "visual_density": "balanced",
  "media_strategy": "existing_media_url",
  "variables": {
    "eyebrow": "WordPress AI Plugin",
    "hero_title": "把 AI 工作流带进 WordPress 内容现场",
    "hero_description": "让内容生产、SEO 优化、媒体处理与发布协作在同一个可审计流程中完成。",
    "primary_cta": "查看工作流",
    "secondary_cta": "了解能力",
    "hero_media_url": "https://example.test/wp-content/uploads/2026/06/reviewed-wordpress-ai-visual.jpg",
    "hero_media_alt": "WordPress AI proposal dashboard preview"
  }
}
```

## Guardrails

- `proposal_mode=two_stage`
- `cloud_candidate_selection_allowed=true`
- `hosted_ai_generation_allowed_as_fallback=true`
- `cloud_crop_required_before_page_plan=true`
- `candidate_review_required=true`
- `image_source_attribution_required=true`
- `hosted_generation_candidate_only=true`
- `media_strategy=existing_media_url`
- `page_references_final_local_media_url=true`
- `draft_only=true`
- `publish_allowed=false`
- `core_proxy_execute=false`
- `commit_execution=false`
- `cloud_control_plane=false`
- `generic_write_executor=false`
- `direct_wordpress_write=false`

Do not collapse this into a single direct write unless Core supports reviewed
action output dependencies that can safely pass the approved media URL into the
page plan.
