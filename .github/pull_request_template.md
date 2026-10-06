## Scope

- [ ] This change is limited to the stated Adapter module.
- [ ] Public REST, recipe, handoff, execution profile, or lifecycle docs were updated if changed.
- [ ] No unrelated generated files, local environment files, or cross-repo worktree changes are included.

## Adapter Boundary

- [ ] Adapter remains the thin OpenClaw channel layer.
- [ ] This does not add WordPress ability definitions or callbacks.
- [ ] This does not add Core proposal storage, approval, preflight, or audit truth.
- [ ] This does not add provider credential storage, model routing, prompt management, product UX, workflow runtime, queues, MCP runtime, or Agent Gateway catalogs.
- [ ] Any final write remains allowlisted, post-Core, and commit-preflight gated.

## Verification

- [ ] `composer validate --no-check-publish`
- [ ] `composer test:all`
- [ ] `composer check:wporg`
- [ ] `composer smoke:wp` if the change touches activation, REST routing, Core integration, or WordPress runtime behavior.

## Risk

- Residual risk:
- Rollback plan:

## AI Review Triage

Filled in after OpenCodeReview delivers its review on this pull request;
`composer pr:publish` verifies this section before requesting auto-merge.
One line per finding id from the delivered review:

- [ ] <finding-id> fix: <what changed>
- [ ] <finding-id> accept: <one-line reason>

## Release Impact

- [ ] No release impact.
- [ ] Requires package/release verification.

## Notes

Summarize the behavior change, boundary decision, and known follow-up.
