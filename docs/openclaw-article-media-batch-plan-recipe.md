# OpenClaw Article Media Batch Plan Recipe

Status: active
Date: 2026-06-04

This recipe lets OpenClaw hand reviewed article drafts plus reviewed
image-source candidates to Core as one governed batch proposal. It does not
make Adapter the image search provider, media importer, approval store,
workflow runtime, queue, Cloud import path, or generic write executor.

## Boundary

Layer ownership stays fixed:

- Toolbox searches image-source providers and builds
  `article_media_batch_write_plan` artifacts.
- Adapter exposes the OpenClaw channel recipe and forwards the reviewed plan.
- Core validates the batch plan, owns proposal status, approval, preflight, and
  audit.
- Abilities executes each final draft/media callback only after Core approval
  and commit preflight.

Adapter must not publish, schedule, approve standalone, choose images without
review evidence, or execute arbitrary writes for this recipe.

## Recipe

1. Search image-source candidates with `npcink-toolbox/search-image-source`.
2. Preserve the selected candidate's source URL, attribution, provider, and
   Unsplash `download_location` when present.
3. Build the media batch planning artifact:

```json
{
  "ability_id": "npcink-toolbox/build-article-media-batch-write-plan",
  "input": {
    "topic": "Local AI plugins",
    "articles": [
      {
        "title": "Draft title",
        "content_markdown": "Reviewed draft body.",
        "file_name": "local-ai-plugins-hero-20260604153000-a1b2c3d4.jpg",
        "image_candidate": {
          "provider": "unsplash",
          "regular_url": "https://images.example.test/photo.jpg",
          "source_url": "https://unsplash.com/photos/example",
          "photographer": "Example Photographer",
          "attribution": "Photo by Example Photographer on Unsplash.",
          "download_location": "https://api.unsplash.com/photos/example/download"
        }
      }
    ]
  }
}
```

Send that payload to:

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

4. Forward the returned plan to Core through Adapter:

```json
{
  "plan_ability_id": "npcink-toolbox/build-article-media-batch-write-plan",
  "plan": {
    "artifact_type": "article_media_batch_write_plan",
    "proposal_mode": "batch",
    "batch_approval": true,
    "requires_approval": true,
    "commit_execution": false,
    "dry_run": true,
    "articles": [],
    "media_workflow": [],
    "write_actions": []
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

5. Poll proposal status through Adapter:

```text
GET /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}
```

6. Execute only after Core approval and commit preflight:

```text
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute
```

## Guardrails

- `artifact_type=article_media_batch_write_plan`
- `proposal_mode=batch`
- `batch_approval=true`
- target abilities are limited to explicit Adapter execution profiles:
  `npcink-abilities-toolkit/create-draft`, `npcink-abilities-toolkit/upload-media-from-url`,
  `npcink-abilities-toolkit/update-media-details`, and
  `npcink-abilities-toolkit/set-post-featured-image`
- image source attribution is preserved
- optional `file_name` values are treated as reviewed customer media names and
  are forwarded only through the governed `npcink-abilities-toolkit/upload-media-from-url`
  action
- `core_proxy_execute=false`
- `commit_execution=false`
- `publish_allowed=false`
- `atomicity=non_atomic`
- `partial_success_possible=true`

If any action is malformed, non-supported, not proposal-ready, still needs
input, or requests `commit_execution=true`, Adapter must fail closed before
executing any action.
