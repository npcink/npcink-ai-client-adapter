# AI Media Derivative Calling Guide

Status: active guide
Date: 2026-06-03

## Purpose

This guide tells AI callers how to request Cloud-generated media derivatives
without bypassing local WordPress governance.

The media derivative capability is exposed through local WordPress abilities
and Cloud Addon transport. Cloud is a runtime processor only. It does not
publish a second ability registry, approve writes, replace files, or update
attachment metadata. Adapter remains the OpenClaw channel for read abilities,
Core proposals, and approved execution; it does not expose media derivative
run, result, artifact preview, or proposal-payload facade routes.

## Canonical Ability Entry

Discovery ability:

```text
npcink-abilities-toolkit/build-media-derivative-cloud-request
```

This is a read-only WordPress ability. It builds the one-run request contract
for Cloud media derivative processing.

Bulk planning ability:

```text
npcink-abilities-toolkit/build-media-derivative-batch-plan
```

This is also read-only. Use it before natural-language bulk requests such as
"convert April media library images to PNG". It returns bounded candidates,
skipped reasons, and per-candidate single-image request input. It does not call
Cloud, create proposals, approve adoption, or return a WordPress write decision.

Verified local REST discovery:

```text
GET /wp-json/wp-abilities/v1/abilities/npcink-abilities-toolkit/build-media-derivative-cloud-request
```

Expected discovery properties:

- `meta.show_in_rest = true`
- `meta.annotations.readonly = true`
- `meta.magick.risk_level = read`
- `meta.magick.channels = ["abilities_rest"]`
- supported formats: `webp`, `avif`, `jpeg`, `png`, `original`
- optional watermark object with image watermark options

The ability output includes:

- `request_contract_version = media_derivative_cloud_request.v1`
- `cloud_job_payload.job_type = generate_optimized_media_derivative`
- `cloud_job_payload.target_format`
- `cloud_job_payload.max_width`
- `cloud_job_payload.quality`
- optional `cloud_job_payload.watermark`
- `local_adoption` and `risk` evidence

The ability does not upload source bytes, call Cloud, submit a proposal, or
write WordPress.

## Recommended AI Flow

AI callers should use Adapter only for local read abilities and governed
proposal handoff. Cloud derivative transport belongs to Cloud Addon or approved
Cloud tooling:

1. Select or receive a WordPress image `attachment_id`. For bulk requests, first
   call `npcink-abilities-toolkit/build-media-derivative-batch-plan` through
   `/run-read-ability`, review `candidates` and `skipped`, and process only a
   small approved slice.
2. Ask Cloud Addon or approved Cloud tooling to create the derivative run.
   Example Cloud Addon request body:

   ```json
   {
     "input": {
       "attachment_id": 123,
       "preferred_format": "webp",
       "target_max_width": 1600,
       "quality": 82,
       "crop": {
         "type": "aspect_ratio",
         "aspect_ratio": "16:9",
         "position": "center"
       },
       "watermark": {
         "type": "image",
         "position": "bottom_right",
         "opacity": 0.75,
         "scale_percent": 18,
         "margin_px": 24
       }
     }
   }
   ```

3. Poll and read the result through Cloud Addon or Cloud tooling.
4. Show the reviewed same-origin preview URL from the result when present.
5. Build a local plan with `npcink-abilities-toolkit/build-media-adoption-enhancement-plan`
   or `npcink-abilities-toolkit/build-media-optimization-plan` through
   `POST /run-read-ability`. Include the reviewed preview URL, Cloud result
   evidence, derivative artifact details, and reviewed metadata when the user
   intent is full media optimization.

6. For full media optimization, submit the returned plan to:

   ```text
   POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan
   ```

   Core will create one batch proposal containing `npcink-abilities-toolkit/update-media-details`
   and `npcink-abilities-toolkit/adopt-cloud-media-derivative`.
   If the payload includes inline media reference repair preview evidence,
   Adapter keeps that evidence in the derivative preview and passes reviewed
   post/count expectations into the adoption input; it must not add a separate
   `npcink-abilities-toolkit/patch-post-content` action for the same media
   optimization intent.
   If reviewed media details are missing, collect reviewed title/alt/caption/
   description/source metadata first and rebuild the local plan through
   `POST /run-read-ability`;
   do not create a derivative-only Core proposal for the same optimization
   request.
   If Core reports the plan ability is unavailable, treat that as a local
   capability/version guard and ask for the local stack to be updated. Do not
   split the same media optimization user intent into two proposal approvals.

7. Let Core approval, preflight, audit, execution, and rollback govern the
   final WordPress write.

## Direct Ability Flow

Advanced local callers may call the read ability directly:

```http
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
```

Example body:

```json
{
  "ability_id": "npcink-abilities-toolkit/build-media-derivative-cloud-request",
  "input": {
    "attachment_id": 123,
    "preferred_format": "webp",
    "target_max_width": 1600,
    "quality": 82
  },
  "log_context": {
    "external_thread_id": "ai-media-derivative-preview"
  }
}
```

This returns only the local request contract. The caller must still use Cloud
Addon or approved Cloud tooling to dispatch the Cloud job.

For batch planning:

```json
{
  "ability_id": "npcink-abilities-toolkit/build-media-derivative-batch-plan",
  "input": {
    "date_from": "2026-04-01",
    "date_to": "2026-04-30 23:59:59",
    "target_format": "png",
    "exclude_formats": ["png"],
    "max_items": 20
  }
}
```

Then dispatch one Cloud Addon or Cloud-tooling derivative run per reviewed
candidate using that candidate's `cloud_request_input`.

## Adoption Write Ability

Write ability:

```text
npcink-abilities-toolkit/adopt-cloud-media-derivative
```

This ability is intentionally separate from the read ability. It requires local
write governance and derivative artifact evidence:

- `attachment_id`
- `derivative_artifact.artifact_id`
- `derivative_artifact.expires_at`
- `derivative_artifact.mime_type`
- `derivative_artifact.format`
- dimensions, filesize, checksum, and warnings when available

Adoption must be proposed and approved through Core. AI callers should not
execute this write ability directly unless they are inside the Core-approved
execution path.

## Guardrails For AI Callers

Do:

- use Cloud Addon or approved Cloud tooling for media derivative run/result
  transport;
- use `npcink-abilities-toolkit/build-media-derivative-batch-plan` before bulk conversion
  requests;
- treat Cloud artifacts as short-lived previews;
- preserve `run_id`, `artifact_id`, `expires_at`, checksum, dimensions, and
  warnings as proposal evidence;
- submit Core proposals for adoption;
- use reference repair plans for hard-coded URLs after adoption.

Do not:

- call Cloud directly from third-party AI clients unless the environment has an
  explicit approved Cloud tool for that purpose;
- store Cloud artifact ids as media registry truth;
- expose Cloud download URLs publicly;
- decide WordPress writes from Cloud responses;
- update attachment metadata from Adapter or Cloud;
- adopt expired artifacts;
- bypass Core proposal approval.

## Related Routes

```text
POST /wp-json/npcink-openclaw-adapter/v1/run-read-ability
POST /wp-json/npcink-openclaw-adapter/v1/proposals/from-plan
POST /wp-json/npcink-openclaw-adapter/v1/proposals
POST /wp-json/npcink-openclaw-adapter/v1/proposals/{proposal_id}/execute
```

## Related Documents

- `docs/openclaw-media-derivative-cloud-recipe.md`
- `docs/openclaw-adapter-contract.md`
- `docs/cloud-connector-boundary.md`
