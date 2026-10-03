# AGENTS.md — Npcink AI Client Adapter

Product name: Npcink AI Client Adapter. The REST namespace keeps
`npcink-openclaw-adapter/v1` for OpenClaw-compatible client compatibility.

Cross-project platform coordination starts from
`/Users/muze/gitee/npcink-workflow-toolbox/docs/platform/README.md`. This
repository remains the thin channel adapter owner; do not expand platform,
Core governance, Toolkit ability, Cloud transport, product-surface, or release
coordination rules here beyond Adapter-owned channel contracts.

## Product Boundary

Npcink AI Client Adapter is the thin OpenClaw-compatible channel layer.

It owns:

- OpenClaw-facing REST routes;
- routing read ability execution to WordPress Abilities API;
- routing proposal and commit-preflight requests to Npcink Governance Core;
- explicit post-Core execution profile policy for allowlisted approved writes;
- small health and diagnostics responses for adapter readiness.

It does not own:

- WordPress ability definitions or callbacks;
- Core proposal storage, approval, or audit truth;
- generic final write authority outside the explicit post-Core execution
  profile allowlist;
- workflow runtime, queues, MCP runtime, or Agent Gateway catalogs;
- provider credentials, model routing, prompts, or product UX.

`npcink-workflow-toolbox` is the workflow surface owner. Adapter may reference
its registered WordPress ability ids, but Adapter must not register recipes,
queues, MCP catalogs, prompt registries, or workflow runtime state.
Adapter code must treat all `npcink-toolbox/*` and
`npcink-workflow-toolbox/*` ids as external ability ids only; referencing them
does not transfer callback, recipe, workflow, prompt, or runtime ownership into
Adapter, and Adapter does not own their callbacks.

## Development Rules

- Start AI-assisted work with `git status --short --branch` and a compact
  change envelope: target repositories, focused module, intended change,
  explicit non-goals, public contracts touched, expected files, files or areas
  that must not change, required gates, cross-repo matrix requirement, and
  rollback plan.
- Keep this plugin thin. If a feature needs ability definitions, change
  `npcink-abilities-toolkit`.
- If a feature needs approval state, change `npcink-governance-core`.
- If a feature needs a new final write, add it only as an explicit execution
  profile after Core approval and commit-preflight; do not add a generic final
  write executor.
- If a feature needs workflow runtime or long-running task orchestration, write
  a boundary note before implementing it here.
- Do not add provider/model/prompt execution routes to Adapter. AI Request Logs
  correlation is metadata-only context forwarding, not model routing, prompt
  management, product UX, or production workload execution.
- If a feature needs Cloud runtime or Cloud monitoring, call the standalone
  `npcink-cloud-addon` public PHP seam. Do not add Adapter-owned Cloud
  settings, signing clients, `/cloud/*` routes, or Cloud execution truth.
  Do not add Adapter-owned Cloud connector routes.
- Use WordPress REST authentication and capability checks.
- For WordPress plugin development, smoke tests, Plugin Check, and plugin
  activation/status checks, prefer local WP-CLI when a WordPress site is
  involved. This machine has global WP-CLI at `/opt/homebrew/bin/wp`; before
  using it, run `command -v wp` and `wp --info` to confirm the active binary
  and runtime.
- Never assume the current working directory is the WordPress root. Pass an
  explicit `WP_PATH` or `--path=<wordpress-root>` for every WP-CLI command.
  The common Local.app development root is
  `/Users/muze/Local Sites/npcink/app/public`, but verify it for the task.
- If a Local.app site has `DB_HOST=localhost` and WP-CLI cannot connect to the
  database, find the matching Local run socket, usually under
  `$HOME/Library/Application Support/Local/run/*/mysql/mysqld.sock`, and inject
  it with `WP_CLI_MYSQL_SOCKET` or the equivalent PHP
  `mysqli.default_socket` setting.
- Run `composer test:all` before committing, plus `composer analyse:php` and
  `composer lint:standards`. The two static analysis gates stay advisory in CI
  (`continue-on-error`) until the promotion noted in
  `docs/archive/2026-10-03-quality-gates-and-controller-split-closeout.md`;
  treat their findings as required fixes anyway, and keep `phpstan.neon`
  ignore entries narrow and commented.
- After adding or changing translatable strings, regenerate the POT with
  `composer i18n:pot`, merge it into every `languages/*.po` (`msgmerge`),
  complete the translations, and recompile each `.mo` (`msgfmt`).
  `composer check:i18n` gates POT/PO coverage, rejects fuzzy entries, and
  verifies `.mo` freshness; it also runs as part of
  `composer release:verify`.
- Run the advisory AI review gate before publishing, and treat findings as a
  second opinion (fix real defects or record why they are acceptable):
  `ocr review --from origin/master --to HEAD`. Follows AI Code Review Standard
  v1 in `npcink-workflow-toolbox` `docs/platform/ai-code-review-standard-v1.md`;
  the CI workflow posting the same review on pull requests is advisory and
  never a required check.
- For multi-repo milestones, run the central matrix from
  `/Users/muze/gitee/npcink-workflow-toolbox` instead of copying the script into Adapter:
  `composer quality:matrix` for status and `composer quality:matrix:run` before
  cross-repo closeout.
- Publish a completed clean topic branch with
  `composer pr:publish -- --title "<title>" --body-file <path>`. Start the body
  from `.github/pull_request_template.md`; do not replace it with ad hoc
  `gh pr create --body` text that omits `Scope`, `Boundary`, `Verification`, or
  `Risk`.
- The publisher checks the current `origin/master` baseline, creates the PR,
  and requests protected squash auto-merge. It never bypasses required checks
  and never deletes local or remote branches, because this repository commonly
  uses multiple worktrees.
- The cross-repository contract is
  `/Users/muze/gitee/npcink-workflow-toolbox/docs/platform/pr-publishing-standard-v1.md`.
- Before staging, inspect `git status --short --branch` and `git diff --stat`.
  Stage only files changed for the current task. Do not use `git add -A` in a
  mixed worktree.
- Do not run `git reset --hard`, `git checkout -- .`, or equivalent destructive
  cleanup unless the user explicitly asks for that exact operation.
- Before committing, verify `git diff --cached --stat` and
  `git diff --cached --name-only`; after committing, verify
  `git show --name-status --stat HEAD`.
- If unexpected files entered a commit, use `git reset --mixed HEAD~1` and
  recommit the correct scope. This preserves the working tree while fixing the
  commit boundary.
- For Plugin Check / PCP, use `composer plugin-check:release` so checks target
  the release/package surface defined by `.distignore`; do not delete
  development-only files such as `tests/` or `AGENTS.md` from the source tree
  just to satisfy a full-worktree scan.
- Use `composer package:release` to build the release zip from that same
  `.distignore` boundary.

## Current Integration Contract

Read operations:

```text
OpenClaw -> npcink-openclaw-adapter -> WordPress Abilities API
```

Governed write operations:

```text
OpenClaw -> npcink-openclaw-adapter -> npcink-governance-core proposal/preflight
```

The adapter must preserve Core's current boundary:

- `core_proxy_execute=false`
- `commit_execution=false`
