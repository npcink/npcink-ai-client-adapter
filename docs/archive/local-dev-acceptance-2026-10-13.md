# Local Development Acceptance — 2026-10-13

## Scope and environment

Adapter acceptance scripts and Toolkit REST registration were verified on
magick-ai.local using the signed `local` CLI profile and the Local.app WordPress
root. No credentials or connection identifiers are included in this note.

## Evidence

- `composer accept:local-dev` passed without skipped WordPress smoke checks:
  deterministic tests, PHPStan, coding standards, translations, WordPress.org
  guard, full WordPress fixtures, fresh release package installation and restore,
  signed HTTPS acceptance and the non-destructive proposal fixture.
- Both `GET /proposals?limit=abc` and `GET /read-requests?limit=abc` returned
  HTTP 400 / `rest_invalid_param`, identifying `limit`. The source fix is already
  part of Adapter 0.4.2; this patch adds no new release version or historical tag.
- The behavior harness exercises database and installation failures for both
  directory and symlink plugin originals, gate failure propagation, current-source
  package build ordering, and refusal to accept a skipped smoke.
- Signed health/help/manifest passed dependency and contract checks, including
  `core_proxy_execute=false`, `commit_execution=false`, and client self-approval
  forbidden. Core discovery exposed 209 abilities, including 165 Toolkit abilities.
- The signed fixture created/read a governed proposal, rejected client
  self-approval and refused execution without commit intent. A subsequent commit-enabled signed fixture attempt was blocked by the real
  Core app rate limit while reading proposal status (HTTP 429), before approval
  or draft creation. Successful signed final execution is not claimed; final
  draft writes are covered by the administrator WordPress fixture suite.

After merging Adapter master (including the new delete-attachment execution
profile), the frozen registry and execute-id hashes were refreshed in the
WordPress contract snapshot. This caught main-branch drift before closeout.

## Findings corrected during acceptance

Toolkit used REST detection at init, before WordPress establishes REST context.
Its separate patch retries built-in package registration on `rest_api_init`;
this is a lifecycle defect, not evidence of stale FPM cache.

The full smoke now asserts that legacy permanent media deletion without branch
evidence is refused by current Core and leaves the attachment intact. It does
not bypass or fabricate deletion evidence. Successful execution of the legacy
media-delete alias with complete current evidence remains separate follow-up.

WordPress fixtures isolate the configured Core app token only inside the
WP-CLI process, and test app-token behavior with their own fixture token. This
avoids exhausting the real client's quota on repeated smoke runs. Signed HTTPS
acceptance continues to use the real connection. Temporary read-grant fixtures
are registered for cleanup.

## Closeout

The central gate-running matrix initially exposed an Adapter text assertion
updated by this patch and an unrelated Toolbox documentation assertion failure
(`governed backup manifest`) in another active working tree. Cross-repository
closeout must not be called complete with any failed repository gate. The next
run passed Adapter, Toolkit, Core and Toolbox; Cloud Addon failed its PR-body
fixture check in a separate active working tree.

Revert this focused acceptance patch to roll back. The installer restores the
original plugin and activation status; no plugin schema migration is introduced.
