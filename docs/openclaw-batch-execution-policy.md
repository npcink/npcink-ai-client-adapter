# OpenClaw Batch Execution Policy

Status: accepted
Date: 2026-05-31

## Context

OpenClaw can create or receive plan-shaped proposals whose `input` contains
multiple `write_actions[]`. The previous Adapter execution contract only
accepted a top-level `input.post_id`, so OpenClaw could reach Core
commit-preflight and still have no Adapter-owned way to perform the final
supported WordPress write loop.

This policy extends the Adapter execution input contract without changing the
governance boundary.

## Decision

Adapter `approve-and-execute` and approved proposal execution accept either:

```json
{
  "post_id": 123
}
```

or:

```json
{
  "write_actions": [
    {
      "action_id": "trash-post-123",
      "target_ability_id": "npcink-abilities-toolkit/trash-post",
      "input": {
        "post_id": 123,
        "dry_run": true,
        "commit": false
      },
      "requires_approval": true,
      "commit_execution": false,
      "proposal_ready": true
    }
  ]
}
```

V1 supports only the Adapter execution supported profiles, currently:

- `target_ability_id=npcink-abilities-toolkit/trash-post`
- `target_ability_id=npcink-abilities-toolkit/create-draft`
- `target_ability_id=npcink-abilities-toolkit/update-post`
- `target_ability_id=npcink-abilities-toolkit/patch-post-content`
- `target_ability_id=npcink-abilities-toolkit/update-post-blocks`
- `target_ability_id=npcink-abilities-toolkit/update-template-blocks`
- `target_ability_id=npcink-abilities-toolkit/upsert-template-blocks`
- `target_ability_id=npcink-abilities-toolkit/update-template-part-blocks`
- `target_ability_id=npcink-abilities-toolkit/patch-setting-value`
- `target_ability_id=npcink-abilities-toolkit/set-post-seo-meta`
- `target_ability_id=npcink-abilities-toolkit/set-post-slug`
- `target_ability_id=npcink-abilities-toolkit/set-post-terms`
- `target_ability_id=npcink-abilities-toolkit/delete-term`
- `target_ability_id=npcink-abilities-toolkit/update-media-details`
- `target_ability_id=npcink-abilities-toolkit/upload-media-from-url`
- `target_ability_id=npcink-abilities-toolkit/set-post-featured-image`
- `target_ability_id=npcink-abilities-toolkit/optimize-media-asset`
- `target_ability_id=npcink-abilities-toolkit/replace-media-file`
- `target_ability_id=npcink-abilities-toolkit/restore-media-backup`
- `target_ability_id=npcink-abilities-toolkit/adopt-cloud-media-derivative`
- `target_ability_id=npcink-abilities-toolkit/rename-media-file`
- `target_ability_id=npcink-abilities-toolkit/delete-media-permanently`
- `target_ability_id=npcink-abilities-toolkit/reply-comment`
- `target_ability_id=npcink-abilities-toolkit/trash-comment`
- `target_ability_id=npcink-abilities-toolkit/approve-comment`

Adapter calls Core approval when needed, then calls Core commit-preflight once
for the proposal. Adapter requires Core approval commit authorization,
`commit_execution=false`, a non-blocked proposal item, and a correlation id.
Only after that does Adapter execute the normalized write actions through
WordPress Abilities API.

## Execution Profile Registry

Adapter keeps final write execution opt-in through local execution profiles.
Capability discovery is not enough to execute a write ability.

Each profile entry is the implementation checklist for one executable ability:

- `ability_id` is included in the derived execution supported profiles;
- required scalar input checks are declared in the profile;
- enum input checks such as media `source_type` are declared in the profile;
- special Adapter-owned guards are declared by profile flags;
- dispatch behavior such as rebuilding post input is declared in the profile;
- result handling such as `post_id` backfill is declared in the profile;
- smoke tests cover success and Adapter-owned rejection behavior.

Adding a new executable write ability means adding or updating exactly one
profile entry plus the matching docs and smoke coverage. Abilities that are
discoverable through Core or WordPress Abilities API but have no Adapter
execution profile must fail closed.

An execution profile may also declare a site-owned readiness condition.
`patch-setting-value` uses this only to consume Toolkit's public host target
allowlist; it does not make execution profiles dynamically extensible. Adapter
may accept a structurally valid proposal for review, but execute and
approve-and-execute fail closed before approval/preflight when the concrete
target is not allowlisted. Capability discovery and profile membership alone
therefore do not claim that a setting target is executable.

For profiled abilities, Adapter validates proposal input at `POST /proposals`
before forwarding to Core. This validation rejects fields outside the profile
input schema and invalid enum values, then reuses the same profile checks again
for profiled `plan.write_actions[]` during `POST /proposals/from-plan` before
forwarding the plan to Core, and again at execution time for older or
externally-created proposals. Plan action schema failures return
`npcink_openclaw_adapter_plan_action_input_invalid` with `blocked_items[]` carrying
the action index, action id, target ability id, field, and reused
single-proposal block code. Plan action input may contain exact
`$outputs.<prior_action_id>.<field>` references for fields such as `post_id` or
`comment_id`; Adapter validates that they point to earlier actions, then
resolves and revalidates them during approved batch execution. Embedded output
tokens such as `prefix-$outputs.create.post_id` are invalid and fail closed.
Plan action ids must be unique before Adapter forwards the plan to Core.

## Batch Rules

- Maximum batch size is 200 actions.
- Partial success is not a normal success mode.
- If any action is malformed, non-supported, not proposal-ready, has
  unresolved `requires_input`, or has `commit_execution=true`, Adapter fails
  closed before executing any action.
- If Core preflight blocks the proposal, Adapter executes no actions.
- If Adapter has already completed execution for the proposal, Adapter returns
  `npcink_openclaw_adapter_execution_already_completed` with the stored
  `execution_record` and executes no actions.
- If an execution error occurs after prior actions have executed, Adapter stops
  and returns the upstream error with `executed_results` for inspection.
- Terms, comments, media delete, and arbitrary write abilities outside the
  Adapter execution supported profiles are not executable in this V1 policy.
- An action input may use an exact `$outputs.<prior_action_id>.<field>`
  reference to a previous action result in the same batch. Adapter resolves
  those references immediately before executing the action, then revalidates
  the resolved input against the target execution profile.
- Output references cannot point forward, cannot cross proposal boundaries, and
  cannot be embedded into larger strings.

## Response Contract

Batch execution responses include:

- `execution_mode=batch_write_actions`
- `selected_count`
- `submitted_count`
- `executed_count`
- `failed_count`
- `blocked_count`
- `partial_success`
- `retryable`
- `operator_next_action`
- `core_preflight_evidence`
- `batch_review_feedback` when Core supplied `preview.batch_review_summary`
- `results[]`
- per-action `action_id`
- per-action `action_index`
- per-action `target_ability_id`
- per-action `execution_profile`
- per-action `idempotency_key`
- per-action `post_id`
- per-action `post_status_before`
- per-action `post_status_after`

Single-post execution keeps the existing response fields and additionally
returns `execution_mode=single_post`, `post_ids`, `executed_count`,
`failed_count`, `selected_count`, `submitted_count`, `retryable`,
`operator_next_action`, `core_preflight_evidence`, and `results[]`.

If an execution error happens after Core preflight has been consumed, Adapter
stores a failed execution record with `partial_success=true` when at least one
prior action executed. That record includes the failed action id/index, failed
execution profile, failed idempotency key, selected/submitted/executed/failed
counts, and `operator_next_action=review_partial_failure_and_create_revised_proposal`.
The original proposal id must not be retried as if it were a fresh batch.

Adapter also exposes `batch_review_feedback` on successful
`POST /proposals/from-plan` and `POST /proposals/{proposal_id}/commit-preflight`
responses when Core provides the batch review summary. The object is display
and recovery guidance only: it can show `operator_next_action`,
`blocked_count`, `needs_input_count`, `retryable`, and `target_ability_ids`,
but it is not a queue record, retry lease, scheduler, or write authorization.

## Non-Goals

This policy does not:

- add a generic write executor;
- expand the Adapter execution supported profiles without a dedicated execution profile
  and implementation;
- make Core execute final WordPress mutations;
- turn Adapter into an MCP runtime or workflow runtime;
- make Adapter store approval state;
- add generic proposal approval proxying.

## Next Changes

Each additional executable write ability requires a separate ADR or execution
policy update that defines ability id, Adapter execution profile, input schema,
idempotency, failure handling, rollback or compensation behavior, log fields,
Core preflight conditions, and smoke coverage.
