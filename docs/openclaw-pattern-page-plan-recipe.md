# OpenClaw Pattern Page Plan Recipe

Status: accepted

This recipe lets OpenClaw request a reviewed Gutenberg page pattern plan without
making Adapter a page renderer or generic write executor.

## Boundary

- Entrypoint ability: `npcink-abilities-toolkit/build-pattern-page-plan`
- Artifact type: `pattern_page_plan`
- Handoff route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- Final route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`
- Final write abilities: `npcink-abilities-toolkit/create-draft` and
  `npcink-abilities-toolkit/update-post-blocks`

The Toolkit owns pattern registry, variable validation, class whitelisting, and
Gutenberg block rendering. Adapter only forwards the read-only plan to Core and
executes supported write actions after Core approval and commit-preflight.

## Supported Input

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
    "hero_media_url": "https://example.test/wp-content/uploads/2026/06/wordpress-ai-dashboard.jpg",
    "hero_media_alt": "WordPress AI dashboard preview",
    "features": []
  }
}
```

`hero_media_url` must be an existing reviewed WordPress media URL supplied by
OpenClaw or another caller. Adapter does not fetch, upload, generate, or select media for this recipe.
When OpenClaw needs a stock, external, owned, or hosted-generated image for this
page, run
[`openclaw-pattern-page-with-visual-asset-recipe.md`](openclaw-pattern-page-with-visual-asset-recipe.md)
first so the selected image candidate is reviewed and imported before this page
plan receives `variables.hero_media_url`.
When OpenClaw needs to learn from relevant public landing pages first, run
[`openclaw-pattern-page-research-brief-recipe.md`](openclaw-pattern-page-research-brief-recipe.md)
and use the reviewed `landing_page_research_brief` only as suggestion-only
evidence for variables, section choices, visual asset requirements, and FAQ
seeds.
For repeated or visually important page generation, also follow
[`openclaw-gutenberg-design-system.md`](openclaw-gutenberg-design-system.md):
select a recipe variant, vary section primitives, use a reviewed media role,
and require design-quality signals before proposal creation instead of
reusing one monolithic template.

## Flow

1. Run `npcink-abilities-toolkit/build-pattern-page-plan` through Adapter read
   execution.
2. Confirm the returned `design_quality.pattern_version` is `2.0` or newer and
   `responsive_quality.uses_mobile_stack=true`.
3. For modern, visual, or repeated page requests, confirm
   `design_quality.design_system=gutenberg_native_v1`,
   `design_quality.section_shape_variety >= 4`,
   `design_quality.template_similarity_score <= 0.75`, and a non-empty
   `design_quality.variant_reason`.
4. Submit the returned `pattern_page_plan` to
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`.
5. Poll `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}`.
6. Execute only after approval with
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`.
7. Read the created page through
   `GET /wp-json/npcink-openclaw-adapter/v1/post-blocks?post_id={post_id}`.

## Responsive Verification

After execution, verify:

- the page status is `draft`;
- block readback contains `core/columns` with `isStackedOnMobile=true`;
- when `hero_media_url` is supplied, block readback contains
  `core/media-text`;
- FAQ output contains `core/details`;
- the front-end page has no horizontal overflow at 1440px, 768px, and 390px;
- buttons wrap instead of overflowing on mobile;
- Gutenberg editor opens without invalid block recovery prompts.
- modern landing pages show section shape variety instead of repeating the
  same hero-card-grid-CTA composition.

`GET /help` exposes these browser checks as
`openclaw_recipes.pattern_page_plan.visual_acceptance`. For local browser QA,
the smoke suite can export retained fixture URLs and viewport targets:

```bash
MAA_ADAPTER_VISUAL_ACCEPTANCE_OUT=/tmp/openclaw-gutenberg-visual-acceptance.json \
MAA_ADAPTER_KEEP_VISUAL_ACCEPTANCE_FIXTURES=1 \
composer smoke:wp
```

See
[`openclaw-gutenberg-visual-acceptance.md`](openclaw-gutenberg-visual-acceptance.md)
for the shared visual acceptance contract.

## Guardrails

- `proposal_mode=batch`
- `batch_approval=true`
- `draft_only=true`
- `publish_allowed=false`
- `core_proxy_execute=false`
- `commit_execution=false`
- `pattern_renderer_owner=npcink-abilities-toolkit`
- `allowed_pattern_ids=["openai-style-landing"]`
- `allowed_style_presets=["minimal-dark-light"]`
- `allowed_responsive_profiles=["landing_standard"]`
- `allowed_media_strategies=["mock_or_existing_media","existing_media_url"]`
- `design_system_contract=docs/openclaw-gutenberg-design-system.md`
- `direct_wordpress_write=false`

Adapter must not accept arbitrary CSS, arbitrary pattern ids, or page rendering
logic in this recipe.
