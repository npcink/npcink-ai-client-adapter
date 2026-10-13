# M4 Ollama provider-log acceptance — 2026-10-13

Scope: Adapter approved-write request-log context. Core remains governance-only;
provider calls and persisted logs remain in the existing SDK and WordPress AI
plugin. No provider routing or credentials were added to Adapter.

## Environment and reproducibility

- LocalWP `magick-ai.local`: WordPress 7.1.1, AI plugin 1.4.0, Core 0.3.0,
  Toolkit 0.6.0, Adapter 0.4.3.
- M4 Ollama 0.32.5: SSH reachable, direct LAN port timed out. A temporary
  loopback-only SSH tunnel forwarded local port 11439 to remote port 11434.
- Existing model: `qwen2.5:1.5b`; no models pulled or services restarted.
- WordPress.org AI Provider for Ollama 1.3.0 SDK classes were loaded temporarily,
  with `OLLAMA_HOST=http://127.0.0.1:11439` and the provider's documented empty-key
  local authentication. The plugin was not persistently installed/activated.
  Download ZIP SHA-256:
  `8c483f337b07c5dcb537444c09485208e3fa745430807a8f2905513fd5109e2c`.
- An acceptance-only MU loader registered the provider while the marker fixture
  was installed, allowed that loopback host/port, and used the AI plugin's public
  provider-detection filter to label the actual endpoint as `ollama`.
  It did not generate or alter log rows or correlation metadata.

Run the existing `composer accept:local-ai-client-fixture` with commit/TLS opt-in,
`MAA_ADAPTER_PROVIDER_LOG_PROVIDER=ollama`,
`MAA_ADAPTER_PROVIDER_LOG_MODEL=qwen2.5:1.5b`, and the configured LocalWP PHP/WP-CLI
socket overrides. It invokes the SDK during marker-scoped draft creation inside
the approved execution callback; it is development acceptance, not a real-user
workload or human browser approval test.

## Failure reproduced and fixed

The unpatched signed fixture made a real successful SDK request but correctly
failed the strict persisted-log assertion: write dispatch installed runtime
approval context without installing the existing AI request log context filter.
The read path already installed the filter. The fix wraps the write dispatch
with that same scoped lifecycle, projecting only operation identifiers and
rebuilding the nested Core proposal/correlation fields; runtime authorization
is excluded.

The WordPress regression probe fails on the unpatched controller and passes on
the fixed controller. It checks success, upstream error, and exception paths:
identifiers are visible inside dispatch, private runtime authority stays usable
but absent from logs, existing log data is retained, and contexts are restored
when dispatch exits. It makes no provider calls and writes no content/log rows.

## Strict positive evidence

The patched signed fixture passed every assertion, including self-approval and
missing-intent refusal, administrator approval through Core, final execution,
persisted provider-log matching, duplicate-execution refusal, stored status
readback, and draft cleanup (post 282138).

| Evidence | Persisted value |
| --- | --- |
| AI request log ID | `03cbd3a3-8d40-4322-bb7d-5e49356b615b` |
| Provider / model | `ollama` / `qwen2.5:1.5b` |
| Status / operation | `success` / `ollama:completions` |
| Core proposal ID | `dd5602cc-d2e7-4835-994c-e65693b181d3` |
| Core preflight correlation ID | `417b9cd6-ef54-486a-9445-39f349bb3113` |
| Adapter request ID | `7f0deb93-7cf4-46de-a92c-56a57baf57b3` |

Both top-level and nested `npcink_governance_core` proposal/correlation fields
matched the signed execution. The row came from the AI plugin's real SDK logging
transporter and was read through its log manager. The existing checker was not
relaxed. Its negative fixtures remain mandatory.

## Limits and cleanup

Default WordPress smoke passed the new context regression and its existing
fail-closed segment; the harness still skips the grant-dependent remainder.
This does not claim a full seven-repository release matrix, real-user evidence,
Cloud-scene SDK logging support, or operator browser approval acceptance.

Temporary provider/controller loader, marker fixture loader, test draft, and the
host-specific Ollama discovery transient were removed; the SSH tunnel was closed.
The normal provider registry returned to `npcink-cloud` only. Actual request logs
and Core audit/execution evidence were retained. No plugin symlinks or persistent
site/provider settings were changed.
