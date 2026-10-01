# OpenClaw Article Batch Draft Plan Recipe

Status: active
Date: 2026-06-04

This recipe lets OpenClaw hand a reviewed 2-5 article draft batch to Core as
one governed batch proposal. It does not make Adapter the article generator,
approval store, workflow runtime, queue, Cloud import path, or generic write
executor.

## Boundary

Layer ownership stays fixed:

- Toolbox builds `article_batch_write_plan` artifacts.
- Adapter exposes the OpenClaw channel recipe and forwards the reviewed plan.
- Core validates the batch plan, owns proposal status, approval, preflight, and
  audit.
- Abilities executes each final `npcink-abilities-toolkit/create-draft` callback only after
  Core approval and commit preflight.

Adapter must not publish, schedule, approve standalone, or execute arbitrary
writes for this recipe. Every batch action must remain draft-only and must use
the Adapter execution profile for `npcink-abilities-toolkit/create-draft`.

## Recipe

1. Discover the playbook with `GET /help` and read
   `openclaw_recipes.article_batch_draft_plan`.
2. Build the planning artifact:

```json
{
  "ability_id": "npcink-toolbox/build-article-batch-write-plan",
  "input": {
    "topic": "Local AI plugins",
    "article_count": 3
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
  "plan_ability_id": "npcink-toolbox/build-article-batch-write-plan",
  "plan": {
    "artifact_type": "article_batch_write_plan",
    "version": 1,
    "proposal_mode": "batch",
    "batch_approval": true,
    "requires_approval": true,
    "commit_execution": false,
    "dry_run": true,
    "articles": [
      {
        "article_goal_brief": {
          "topic": "Local AI plugins",
          "title": "Draft title one"
        },
        "research_evidence_pack": {
          "sources": []
        },
        "article_outline": {
          "title": "Draft title one",
          "sections": []
        },
        "article_draft_candidate": {
          "content_markdown": "Reviewed draft body one."
        },
        "discoverability_pack": {
          "excerpt": "Reviewed draft excerpt one."
        },
        "article_risk_report": {
          "risk_level": "medium",
          "blocked_claims": [],
          "ready_for_proposal": true
        }
      },
      {
        "article_goal_brief": {
          "topic": "Local AI plugins",
          "title": "Draft title two"
        },
        "research_evidence_pack": {
          "sources": []
        },
        "article_outline": {
          "title": "Draft title two",
          "sections": []
        },
        "article_draft_candidate": {
          "content_markdown": "Reviewed draft body two."
        },
        "discoverability_pack": {
          "excerpt": "Reviewed draft excerpt two."
        },
        "article_risk_report": {
          "risk_level": "medium",
          "blocked_claims": [],
          "ready_for_proposal": true
        }
      }
    ],
    "write_actions": [
      {
        "action_id": "create-article-draft-1",
        "target_ability_id": "npcink-abilities-toolkit/create-draft",
        "input": {
          "status": "draft",
          "title": "Draft title one",
          "content": "Reviewed draft body one.",
          "content_format": "plain",
          "dry_run": true,
          "commit": false
        },
        "requires_approval": true,
        "commit_execution": false,
        "proposal_ready": true
      },
      {
        "action_id": "create-article-draft-2",
        "target_ability_id": "npcink-abilities-toolkit/create-draft",
        "input": {
          "status": "draft",
          "title": "Draft title two",
          "content": "Reviewed draft body two.",
          "content_format": "plain",
          "dry_run": true,
          "commit": false
        },
        "requires_approval": true,
        "commit_execution": false,
        "proposal_ready": true
      }
    ],
    "action_count": 2
  },
  "plan_input": {
    "topic": "Local AI plugins",
    "article_count": 3
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

## Guardrails

- `artifact_type=article_batch_write_plan`
- `proposal_mode=batch`
- `batch_approval=true`
- `target_ability_id=npcink-abilities-toolkit/create-draft`
- `status=draft`
- `core_proxy_execute=false`
- `commit_execution=false`
- `publish_allowed=false`
- `atomicity=non_atomic`
- `partial_success_possible=true`

If any action is malformed, non-supported, not proposal-ready, still needs
input, or requests `commit_execution=true`, Adapter must fail closed before
executing any action.
