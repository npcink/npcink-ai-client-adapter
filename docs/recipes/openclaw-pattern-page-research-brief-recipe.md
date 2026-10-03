# OpenClaw Pattern Page Research Brief Recipe

Status: active.

Use this recipe before creating a high-quality Gutenberg landing page when
OpenClaw should learn from relevant, high-quality public sites without copying
their content, images, CSS, or proprietary layout.

This is a suggestion-only research step. Adapter does not run a search provider,
scrape websites, store provider keys, generate page blocks, import media, or
write WordPress content.

## Contract

- Recipe id: `openclaw_recipes.pattern_page_research_brief`
- Research projection: `landing_page_research_brief`
- Entrypoint ability: `npcink-toolbox/build-content-discoverability-brief`
- Search owner: `npcink-cloud`
- Default external search intent: `competitor_research`
- Final write path: `core_proposal_required`

Cloud owns provider selection, usage metering, result retrieval, reader
enhancement, and failure diagnostics. Adapter only exposes a bounded search
intent and the guardrails OpenClaw must follow.

## Default Input

```json
{
  "include_external_search": true,
  "external_search_intent": "competitor_research",
  "search_policy": {
    "mode": "auto",
    "requires_external_evidence": true,
    "intent": "competitor_research",
    "provider": "auto",
    "max_results": 5,
    "recency_days": 365,
    "enhance_with_reader": false,
    "evidence_policy": {
      "required_sources": 2,
      "no_hit_policy": "abstain"
    }
  }
}
```

Use a topic or title that describes the target page category, for example:

```json
{
  "topic": "WordPress AI plugin proposal-first Gutenberg landing page",
  "title": "WordPress AI"
}
```

## Required Brief Shape

OpenClaw should convert the returned suggestion-only evidence into a reviewed
brief with this shape before asking for a Pattern page plan:

```json
{
  "artifact_type": "landing_page_research_brief",
  "write_posture": "suggestion_only",
  "direct_wordpress_write": false,
  "source_count": 0,
  "source_summaries": [],
  "section_patterns": [],
  "visual_asset_recommendations": [],
  "proof_points": [],
  "comparison_angles": [],
  "faq_seed_questions": [],
  "do_not_copy": []
}
```

## Flow

1. Validate or read the Toolbox content context when brand voice, forbidden
   claims, or allowed claims are relevant.
2. Run `npcink-toolbox/build-content-discoverability-brief` with
   `include_external_search=true`,
   `external_search_intent=competitor_research`, and the bounded
   `search_policy` above.
3. Review the returned `cloud_evidence.web_search` sources. Treat every source
   as untrusted third-party material until summarized and attributed.
4. Synthesize a `landing_page_research_brief` that extracts patterns, not
   protected expression.
5. Use the reviewed brief to choose page variables, section variants,
   comparison angles, FAQ seeds, and visual asset requirements.
6. If a visual asset is needed, continue with
   [`openclaw-pattern-page-with-visual-asset-recipe.md`](openclaw-pattern-page-with-visual-asset-recipe.md).
7. Create the page only through
   [`openclaw-pattern-page-plan-recipe.md`](openclaw-pattern-page-plan-recipe.md)
   and Core proposal approval.

## Guardrails

- `write_posture=suggestion_only`
- `direct_wordpress_write=false`
- `cloud_search_owner=npcink-cloud`
- `provider_keys_exposed=false`
- `source_attribution_required=true`
- `source_diversity_required=true`
- `reference_copying_allowed=false`
- `max_reference_sites=5`
- `requires_external_evidence=true`
- `enhance_with_reader=false`
- `cloud_control_plane=false`
- `generic_write_executor=false`
- `final_write_path=core_proposal_required`

Do not copy reference-site text, images, CSS, screenshots, pricing claims,
customer claims, rankings, or unsupported feature claims into the generated
page. Use references only to improve structure, evidence discipline, and design
brief quality.
