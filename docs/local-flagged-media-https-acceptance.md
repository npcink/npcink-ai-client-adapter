# Local flagged-media HTTPS acceptance

This command uses the configured signed CLI over the real HTTP transport for
Adapter proposal intake and execution. A separate WP-CLI administrator test
context creates disposable attachments, supplies Toolkit's dry-run plan,
approves through Core and reads back WordPress state. Automated administrator
approval is not proof that an operator has tested the browser approval UI.

## Prerequisites

- A signed CLI profile targeting the same site as `WP_PATH`. The command checks
  the HTTP connection manifest against WordPress `home_url()` before creating
  attachments.
- Toolkit must register `delete-attachment`,
  `build-flagged-media-removal-plan` and `restore-media-backup` in the real HTTP
  Core catalog. Missing owner dependencies fail the command without creating
  fixtures; this is not a skipped or successful acceptance.
- Adapter must accept the plan builder through its existing `/proposals/from-plan`
  bridge. Core must accept a single-delete batch with branch evidence.
- The CLI profile's Core connection needs proposal create/read and commit
  preflight scopes and available quota. Respect Retry-After on HTTP 429;
  acceptance does not alter limits or clear counters.

## Run

Use the verified site's WordPress path and Local.app database socket:

```bash
WP_PATH='/path/to/local/wordpress' \
WP_CLI_MYSQL_SOCKET='/path/to/local/mysql/mysqld.sock' \
MAA_ADAPTER_ACCEPTANCE_PROFILE=local \
MAA_ADAPTER_ACCEPTANCE_INSECURE_LOCAL_TLS=1 \
MAA_ADAPTER_FIXTURE_ALLOW_COMMIT=1 \
composer accept:local-ai-client-flagged-media
```

The TLS option is for a local self-signed site. Omit it on a site with trusted
TLS. `WP_CLI` and `WP_CLI_PHP` may select the verified WP-CLI binary and PHP
runtime. Every WP-CLI invocation has an explicit site path.

To include this gate in the complete local development acceptance command,
set `MAA_ADAPTER_FIXTURE_FLAGGED_MEDIA=1` together with the commit opt-in and run
`composer accept:local-dev`. The ordinary aggregate does not request attachment
deletion. An explicitly requested flagged-media gate fails when prerequisites
are missing instead of skipping that leg.

## Assertions and cleanup

Both `governed_with_backup` and `illegal_content_no_backup` use newly created
run-owned attachments. The command verifies manual-pending proposals, refusal
of signed client self-approval and execution before approval, unchanged fixture
files on refusal, Core preflight/result records, successful deletion, duplicate
execution refusal and execution record readback. The governed branch verifies
retained backup bytes and restores through a second approved proposal; the
no-backup branch verifies no retained manifest or corresponding backup folder.

Cleanup removes only this run's attachment files, original/restored attachment
records and backup manifests. Core proposals and audit evidence remain recorded,
matching the existing real-client draft fixture. Existing site attachments,
credentials, app keys and quota configuration are not fixture cleanup targets.

Cleanup runs on success, failure and ordinary interruption. A cleanup failure
returns nonzero and preserves the state file path reported in the error. After
resolving the underlying failure, run the helper with that retained state:

```bash
MAA_FLAGGED_FIXTURE_STATE='/reported/temp/path/state.json' \
MAA_FLAGGED_FIXTURE_ACTION=cleanup \
php -d mysqli.default_socket='/path/to/local/mysql/mysqld.sock' \
/path/to/wp --path='/path/to/local/wordpress' \
eval-file tests/flagged-media-https-fixture.php
```

Run the recovery from the repository checkout. Verify cleanup succeeds before
removing the temporary state directory. Forced process termination can require
this explicit recovery; it must not be described as a clean acceptance pass.

`composer test:flagged-media-https-fixture` verifies opt-in refusal, site
mismatch, missing execution evidence and cleanup failure propagation without
using WordPress or real media. It is included in `composer test:all`. A green
behavior harness is separate from the real HTTPS acceptance gate above.
