# Execution Profiles Contract

Status: accepted Adapter execution profile contract.

Adapter execution profiles are the only Adapter-owned final-write allowlist.
They are not ability definitions. Ability definitions and callbacks remain in
`npcink-abilities-toolkit`; approval, audit, and commit-preflight truth remain
in `npcink-governance-core`.

The final Toolkit-side commit gate that authorizes the upstream WordPress
write is documented host-agnostically in the
[Host Approval Contract](https://github.com/npcink/npcink-abilities-toolkit/blob/master/docs/host-approval-contract.md)
(`npcink-abilities-toolkit`, `docs/host-approval-contract.md`). Adapter is the
first implementer of that contract's runtime channel; third-party hosts may
implement the toolkit-prefixed
`npcink_abilities_toolkit_write_commit_allowed` filter instead.

## Profile Rule

Adapter may execute a write only when all of these are true:

- Core has an approved proposal.
- Core commit-preflight authorizes the proposal item.
- The caller supplies explicit commit intent.
- The proposal item targets an ability id in Adapter's execution profile
  registry.
- The execution input passes the profile's supported field, required field,
  enum, size, and safety validation.
- Any profile-declared site readiness condition passes for the concrete target.

The registry is implemented in
[`../../includes/Rest/Execution_Profile_Registry.php`](../../includes/Rest/Execution_Profile_Registry.php).

## Placement And Extension Rules

Keep the execution profile registry in Adapter. It is Adapter's post-Core
execution policy, not a Toolkit ability definition and not Core approval truth.

Do not migrate this registry to:

- `npcink-abilities-toolkit`, because Toolkit owns ability definitions,
  schemas, callbacks, permissions, and dry-run previews;
- `npcink-governance-core`, because Core owns proposal storage, approval,
  commit-preflight, and audit truth while keeping `core_proxy_execute=false`
  and `commit_execution=false`;
- a separate plugin, unless multiple channel adapters must share the same
  post-Core execution policy and a future ADR accepts that extra dependency.

Do not expose dynamic extension points for execution profiles. The registry
must not use WordPress filters, actions, options, database rows, remote
configuration, wildcards, category matches, or ability-id patterns to add final
write authority. Adding or changing a profile requires a code change, contract
update, static test update, and WordPress smoke coverage.

## Profile Admission Checklist

Every new profile must stay narrow enough to prove it is not a generic final
write executor:

- Bind exactly one Toolkit ability id.
- Use a literal `npcink-abilities-toolkit/<ability>` id; no wildcards, regexes,
  category executors, or arbitrary ability ids.
- Require Core approval and Core commit-preflight evidence for the same
  proposal item.
- Require explicit commit intent; dry-run or preview-only inputs must not
  execute.
- Validate provider-declared `implementation_posture` when Core capability
  discovery exposes it; only host-governed, dry-run-first, metadata-only posture
  may reach final execution.
- Declare `supported_input_fields` and reject undeclared write fields.
- Validate required WordPress object ids, text fields, enum fields, array
  shapes, and size limits in Adapter before forwarding execution.
- Normalize only the profile-owned `dry_run=false` and `commit=true` execution
  controls.
- Preserve idempotency and site/client binding checks from Core handoff data.
- Add dedicated static and smoke coverage for the exact ability id and
  expected input shape.

## Machine-Readable Fingerprints

`GET /health`, `GET /help`, and `GET /connection/manifest` expose:

```text
execution_profile_registry_version
execution_profile_registry_hash
supported_execute_ability_ids_hash
max_execution_actions
core_proxy_execute=false
commit_execution=false
```

Clients can compare these fields against acceptance-tested builds. They do not
authorize execution by themselves.

## Conditional Site Readiness

`npcink-abilities-toolkit/patch-setting-value` remains an explicit Adapter
execution profile, but it is conditionally executable. Protocol support and
site readiness are separate facts:

- `supported_execute_ability_ids` means Adapter knows the bounded input and
  post-Core dispatch shape;
- `execution_profile_readiness` reports whether the required host policy hook
  is configured, without returning setting names;
- final execution checks the concrete `target_type` and `target_name` against
  `npcink_abilities_toolkit_patchable_setting_targets` before approval or Core
  commit-preflight is consumed;
- an unallowlisted target returns
  `npcink_openclaw_adapter_setting_target_not_ready` and executes no write;
- Toolkit remains the final owner of sensitive-target rejection even when a
  host accidentally includes a sensitive name in its allowlist.

Adapter does not store or extend the allowlist. The WordPress host owns the
site-specific values; Core still owns proposal, approval, preflight, and audit
truth; Toolkit still owns the ability callback and hard security blocks.

## Block Theme Template Updates

There is no separate Adapter profile named
`block-theme-template.update_draft`. The current contract uses explicit Toolkit
ability ids:

```text
npcink-abilities-toolkit/update-template-blocks
npcink-abilities-toolkit/upsert-template-blocks
npcink-abilities-toolkit/update-template-part-blocks
```

For product discussions, `block-theme-template.update_draft` may be treated as a
human-facing alias for those three ability-id profiles after Core approval and
commit-preflight. Do not add a generic template write executor for that alias.

## Source Documents

- [`../openclaw-adapter-contract.md`](../openclaw-adapter-contract.md)
- [`../openclaw-block-theme-site-builder-recipe.md`](../openclaw-block-theme-site-builder-recipe.md)
- [`../openclaw-batch-execution-policy.md`](../openclaw-batch-execution-policy.md)
