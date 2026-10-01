# OpenClaw Article Draft Plan Recipe

Status: active
Date: 2026-06-02

This recipe lets OpenClaw use the Toolbox article planning artifact without
becoming the article generator, proposal store, approval surface, or final write
owner.

It is a local article assistant handoff. The recipe accepts one reviewed draft
and creates one governed draft proposal; it is not a bulk writing flow, Cloud
writing import, or autonomous article generation surface.

## Boundary

Layer ownership stays fixed:

- Toolbox builds `article_write_plan` artifacts.
- Adapter exposes the OpenClaw channel recipe and forwards the reviewed plan.
- Core validates the plan, owns proposal status, approval, preflight, and audit.
- Abilities executes the final `npcink-abilities-toolkit/create-draft` callback only after
  Core approval and commit preflight.
- Cloud Addon is not part of this local control loop.

Adapter must not publish, schedule, approve standalone, or execute arbitrary writes for this recipe.
Adapter must also not add a prompt library, authoring runtime, batch article
queue, hosted drafting path, or Cloud article import for this recipe.

## Recipe

1. Discover the playbook with `GET /help` and read
   `openclaw_recipes.article_draft_plan`.
2. Build the planning artifact:

```json
{
  "ability_id": "npcink-toolbox/build-article-write-plan",
  "input": {
    "title": "Reviewed draft title",
    "content_markdown": "Reviewed draft body.",
    "focus_keyword": "optional keyword"
  }
}
```

Send that payload to:

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

3. Forward the returned plan to Core through Adapter:

```json
{
  "plan_ability_id": "npcink-toolbox/build-article-write-plan",
  "plan": {
    "artifact_type": "article_write_plan",
    "version": 1,
    "requires_approval": true,
    "commit_execution": false,
    "dry_run": true
  },
  "plan_input": {
    "title": "Reviewed draft title"
  },
  "caller": {
    "external_thread_id": "OPENCLAW_THREAD"
  }
}
```

Send that payload to:

```text
POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan
```

4. Poll proposal status through Adapter:

```text
GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}
```

5. Execute only after Core approval and commit preflight:

```text
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute
```

## Required Plan Shape

Core accepts only `artifact_type=article_write_plan` plans that include:

- `article_goal_brief`
- `research_evidence_pack`
- `article_outline`
- `article_draft_candidate`
- `discoverability_pack`
- `article_risk_report`
- exactly one `write_actions[]` item targeting `npcink-abilities-toolkit/create-draft`

The final action must stay draft-only with `dry_run=true` and `commit=false`
before Core approval. Plans with `risk_level=high`, non-empty
`blocked_claims`, publish status, or more than one write action fail closed.
