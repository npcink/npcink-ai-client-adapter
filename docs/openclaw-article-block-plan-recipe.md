# OpenClaw Article Block Plan Recipe

Status: accepted

This recipe lets OpenClaw request a reviewed Gutenberg article block plan
without making Adapter an article generator, renderer, or generic write
executor.

## Boundary

- Entrypoint ability: `npcink-abilities-toolkit/build-article-block-plan`
- Artifact type: `article_block_plan`
- Handoff route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`
- Final route: `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`
- Final write abilities: `npcink-abilities-toolkit/create-draft` and
  `npcink-abilities-toolkit/update-post-blocks`

The Toolkit owns article block rendering, editorial template bounds, responsive
quality metadata, and Gutenberg block construction. Adapter only forwards the
read-only plan to Core and executes supported write actions after Core
approval and commit-preflight.

## Supported Input

```json
{
  "post_type": "post",
  "status": "draft",
  "title": "Gutenberg Article Draft",
  "article_template": "comparison-review",
  "responsive_profile": "article_standard",
  "media_strategy": "existing_media_url",
  "variables": {
    "dek": "用 Gutenberg 原生模块组织文章，让编辑、审查和移动端阅读都更稳定。",
    "intro": "文章计划应该和页面 Pattern 分开处理，重点放在语义结构和可编辑性。",
    "hero_media_url": "https://example.test/wp-content/uploads/2026/06/article-hero.jpg",
    "hero_media_attachment_id": 9053,
    "hero_media_alt": "Article hero preview",
    "takeaways": [],
    "sections": [],
    "comparisons": [],
    "faq": []
  }
}
```

`hero_media_url` must be an existing reviewed WordPress media URL supplied by
OpenClaw or another caller. When the reviewed media library attachment is
known, also pass `hero_media_attachment_id` so Toolkit can bind
`core/image.attrs.id` and emit the matching `wp-image-{id}` class. Adapter does not fetch, upload, generate, or select media for this recipe.

## Flow

1. Run `npcink-abilities-toolkit/build-article-block-plan` through Adapter read
   execution.
2. Confirm the returned `editorial_quality.pattern_version` is `1.0` or newer,
   `editorial_quality.uses_native_blocks=true`, and
   `responsive_quality.uses_mobile_stack=true`.
3. Submit the returned `article_block_plan` to
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan`.
4. Poll `GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}`.
5. Execute only after approval with
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute`.
6. Read the created post through
   `GET /wp-json/npcink-openclaw-adapter/v1/post-blocks?post_id={post_id}`.

## Responsive Verification

After execution, verify:

- the post status is `draft`;
- block readback contains `core/columns` with `isStackedOnMobile=true` when
  comparison content is supplied;
- when `hero_media_url` is supplied, block readback contains `core/image`;
- when `hero_media_attachment_id` is supplied, `core/image.attrs.id` equals the
  reviewed attachment id;
- rendered image markup contains the matching `wp-image-{id}` class;
- media references use a local WordPress media URL, not a temporary Cloud
  derivative preview URL;
- FAQ output contains `core/details`;
- the front-end post has no horizontal overflow at 1440px, 768px, and 390px;
- Gutenberg editor opens without invalid block recovery prompts.

`GET /help` exposes these browser checks as
`openclaw_recipes.article_block_plan.visual_acceptance`. For local browser QA,
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
- `article_renderer_owner=npcink-abilities-toolkit`
- `allowed_article_templates=["editorial-longform","how-to-guide","comparison-review"]`
- `allowed_responsive_profiles=["article_standard"]`
- `allowed_media_strategies=["none","existing_media_url"]`
- `custom_css_allowed=false`
- `direct_wordpress_write=false`

Adapter must not accept arbitrary CSS, arbitrary article templates, article
generation runtime, or block rendering logic in this recipe.
