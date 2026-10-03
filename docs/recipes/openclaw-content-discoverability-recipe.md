# OpenClaw Content Discoverability Recipe

Status: active
Date: 2026-06-03

This recipe lets OpenClaw use Toolbox SEO, AEO, and GEO context without
turning Adapter into a prompt owner, proposal store, approval surface, or final
write executor.

The primary SEO/GEO/AEO entrypoint is `content-discoverability-brief`, backed by
`npcink-toolbox/build-content-discoverability-brief`.
Use `article-writing-pack` only for broad natural-language requests such as
"help me write an article".

For those broad article requests, use
`openclaw_recipes.ai_article_draft_with_discoverability` and
`POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability` with
`npcink-toolbox/build-ai-article-writing-pack`.

## Boundary

Layer ownership stays fixed:

- Toolbox owns the operator-filled content context and the
  `content_discoverability_brief` planning artifact.
- `npcink-toolbox/*` ability ids are externally registered WordPress ability ids
  discovered through Adapter capabilities; Adapter treats those ids as external
  direct-read abilities and does not own their callbacks or workflow runtime.
- Adapter exposes `POST /run-read-ability` and a machine-readable OpenClaw
  recipe.
- Core decides whether each ability is `direct_read` and owns proposal,
  approval, commit-preflight, and audit truth.
- WordPress Abilities API runs the Toolbox callbacks.

Adapter must not write SEO meta, slugs, excerpts, schema, media, terms, posts,
or publishing status for this recipe. Reviewed final writes must go through
Core proposal governance.

## Recipe

1. Discover Adapter state and route guidance:

```text
GET /wp-json/npcink-openclaw-adapter/v1/health
GET /wp-json/npcink-openclaw-adapter/v1/help
GET /wp-json/npcink-openclaw-adapter/v1/capabilities
```

2. Confirm these ability ids are present in Core capabilities with
   `governance_mode=direct_read` and
   `execution_surface=wp_abilities_rest`:

```text
npcink-toolbox/validate-content-discoverability-context
npcink-toolbox/get-content-discoverability-context
npcink-toolbox/build-content-discoverability-brief
```

3. Validate the operator-filled Toolbox context through:

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

```json
{
  "ability_id": "npcink-toolbox/validate-content-discoverability-context",
  "input": {}
}
```

If the result status is `needs_attention`, stop and ask the operator to update
Toolbox Content Context.

4. Read the context through:

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

```json
{
  "ability_id": "npcink-toolbox/get-content-discoverability-context",
  "input": {}
}
```

5. Build one brief through:

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

```json
{
  "ability_id": "npcink-toolbox/build-content-discoverability-brief",
  "input": {
    "post_id": "POST_ID"
  }
}
```

For supplied context instead of a post, use
`POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability`:

```json
{
  "ability_id": "npcink-toolbox/build-content-discoverability-brief",
  "input": {
    "topic": "Topic",
    "title": "Title",
    "content_markdown": "Draft body",
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

Cloud owns the search providers, result retrieval, reader enhancement, usage
metering, and failure diagnostics. Adapter only passes the external-evidence
intent through the Toolbox ability input and must not expose Tavily, Bocha, Jina
Reader, Apify, or provider-key configuration.

## Required Output

OpenClaw should return only proposal-ready suggestions grounded in the brief:

```json
{
  "post_id": 0,
  "context_status": "ready",
  "write_posture": "suggestion_only",
  "direct_wordpress_write": false,
  "suggestions": {
    "seo_title": "",
    "seo_description": "",
    "slug": "",
    "excerpt": "",
    "faq": [],
    "answer_summary": "",
    "geo_summary": ""
  },
  "final_write_path": "core_proposal_required"
}
```

Only include fields present in `proposal_allowed_fields`.

## Guardrails

- Do not invent product facts, customer stories, rankings, citations,
  guarantees, or unsupported features.
- Respect `forbidden_claims`, `allowed_claims`, and `brand_voice`.
- Do not treat the brief as permission to mutate WordPress.
- Do not call a local search provider; let Toolbox/Cloud attach
  `cloud_evidence.web_search` when external evidence is required.
- Do not call `POST /proposals` until a human has reviewed the suggestions and
  selected a real final write ability.
- Do not add these Toolbox abilities to Adapter's approved final execution
  profile registry; they are direct-read suggestion abilities.
