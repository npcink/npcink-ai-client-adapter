# OpenClaw AI Article Writing Pack Recipe

Status: active
Date: 2026-06-03

This recipe gives OpenClaw one high-level entrypoint for broad article requests
such as "write an AI topic article". Adapter exposes the Toolbox writing pack
as a direct-read ability through `/run-read-ability`; Toolbox builds the
context pack; OpenClaw may help prepare a local review candidate from that
pack; Core remains the only proposal, approval, commit-preflight, and audit
truth for any final WordPress write.

For SEO/GEO/AEO suggestions on a known post, title, topic, or draft body, use
`content-discoverability-brief` as the primary entrypoint.
Use `article-writing-pack` only for broad natural-language requests such as
"help me write an article".

This is an OpenClaw article assistant recipe, not an article generator product.
It must stay local, single-article, suggestion-only, and operator-reviewed
until a separate `article_write_plan` is submitted through Core governance.

## Boundary

Layer ownership stays fixed:

- Toolbox owns the operator-filled SEO/AEO/GEO context and the
  `ai_article_writing_pack` planning artifact.
- `npcink-toolbox/*` ability ids are externally registered WordPress ability ids
  discovered through Adapter capabilities; Adapter treats those ids as external
  direct-read abilities and does not own their callbacks or workflow runtime.
- Adapter exposes `POST /run-read-ability` and the machine-readable OpenClaw
  recipe.
- OpenClaw may prepare a draft candidate from the pack, but it must treat that
  output as a local candidate for operator review, not generated WordPress
  content.
- Core decides whether each ability is `direct_read` and owns proposal,
  approval, commit-preflight, and audit truth.
- WordPress Abilities API runs the Toolbox callbacks.

Adapter must not own prompts, provider selection, model execution, drafting
runtime, SEO writes, media writes, publishing, or a second workflow registry.
Reviewed final writes must go through Core proposal governance.

Do not add Cloud article writing, batch article writing, hosted article
drafting, prompt-library ownership, or an Adapter-owned authoring runtime to
this recipe.

## Recipe

1. Discover Adapter state and route guidance:

```text
GET /wp-json/npcink-openclaw-adapter/v1/health
GET /wp-json/npcink-openclaw-adapter/v1/help
GET /wp-json/npcink-openclaw-adapter/v1/capabilities
```

2. Confirm this ability id is present in Core capabilities with
   `governance_mode=direct_read` and
   `execution_surface=wp_abilities_rest`:

```text
npcink-toolbox/build-ai-article-writing-pack
```

3. Build the writing pack through:

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

```json
{
  "ability_id": "npcink-toolbox/build-ai-article-writing-pack",
  "input": {
    "topic": "AI_TOPIC"
  }
}
```

For richer input, use
`POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability`:

```json
{
  "ability_id": "npcink-toolbox/build-ai-article-writing-pack",
  "input": {
    "topic": "AI topic",
    "title": "Suggested title",
    "language": "zh-CN",
    "article_type": "practical_guide",
    "target_word_count": 1200,
    "include_external_search": true,
    "external_search_intent": "writing_context",
    "search_policy": {
      "mode": "auto",
      "requires_external_evidence": true,
      "intent": "writing_context",
      "max_results": 3,
      "recency_days": 30,
      "enhance_with_reader": false
    }
  }
}
```

Cloud owns the search providers, usage metering, result count, failure reason,
and optional Jina Reader enhancement. Adapter only passes the search intent in
the Toolbox ability input; it must not store provider keys or run a local search
provider.

4. Draft from the returned pack only. The returned result must include:

```text
artifact_type=ai_article_writing_pack
write_posture=suggestion_only
direct_wordpress_write=false
provider_execution=none
final_write_path=core_proposal_required
cloud_evidence.web_search when external evidence is available
```

5. After operator review, build a governed write plan:

```json
{
  "ability_id": "npcink-toolbox/build-article-write-plan",
  "input": {
    "draft_title": "Reviewed title",
    "draft_body": "Reviewed body",
    "seo": {
      "seo_title": "Reviewed SEO title",
      "seo_description": "Reviewed SEO description"
    }
  }
}
```

6. If the reviewed plan should become WordPress data, call
   `POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan` and keep using
   Core proposal status, approval, and commit-preflight routes.

7. For a reviewed multi-article draft batch, use
   `npcink-toolbox/build-article-batch-write-plan` and submit only its
   returned plan to `POST /proposals/from-plan`.

8. For reviewed article drafts that also include selected image-source
   candidates, use `npcink-toolbox/build-article-media-batch-write-plan`.
   The plan may include `npcink-abilities-toolkit/create-draft`,
   `npcink-abilities-toolkit/upload-media-from-url`, `npcink-abilities-toolkit/update-media-details`, and
   `npcink-abilities-toolkit/set-post-featured-image` write actions with `$outputs.*`
   dependencies. OpenClaw must still treat this as a Core proposal handoff,
   not permission to upload media or set featured images directly.

## Required Output

OpenClaw should show the operator a draft candidate plus proposal-ready
suggestions, not applied WordPress changes:

```json
{
  "artifact_type": "ai_article_draft_candidate",
  "source_pack_artifact_type": "ai_article_writing_pack",
  "write_posture": "suggestion_only",
  "direct_wordpress_write": false,
  "draft": {
    "title": "",
    "body_markdown": "",
    "answer_summary": "",
    "faq": [],
    "geo_summary": ""
  },
  "proposal_candidates": {
    "seo_title": "",
    "seo_description": "",
    "slug": "",
    "excerpt": ""
  },
  "final_write_path": "core_proposal_required"
}
```

Only include fields allowed by the writing pack's `proposal_allowed_fields`.

## Guardrails

- Do not invent product facts, customer stories, rankings, citations,
  guarantees, or unsupported features.
- Do not present OpenClaw as the article generator; it is only preparing a
  local review candidate from a Toolbox pack.
- Respect `forbidden_claims`, `allowed_claims`, and `brand_voice`.
- Do not treat the writing pack or draft candidate as permission to mutate
  WordPress.
- Do not publish, schedule, upload media, set featured images, write SEO meta,
  or update posts from this recipe.
- Toolbox planning abilities stay read-only. Adapter may allow their plans into
  `/proposals/from-plan`, but approved final execution must be limited to the
  WordPress abilities listed in Adapter's execution profiles.
