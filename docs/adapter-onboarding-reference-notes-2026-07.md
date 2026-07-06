# Adapter Onboarding Reference Notes - 2026-07

Status: benchmark notes for future Adapter client-onboarding decisions.

Date: 2026-07-06.

This note is the AI Client Adapter pass in the cross-project reference plugin
benchmark. It compares Adapter's external-client connection and feedback
surface against mature WordPress webhook and automation onboarding patterns.
It is not an implementation plan, route proposal, dependency decision, or
request to add workflow runtime behavior.

## Question

Which mature WordPress integration patterns should
`npcink-ai-client-adapter` learn from before changing connection manifest,
external client onboarding, payload preview, failure feedback, or developer
reference material?

## Short Answer

Adapter should learn connection and troubleshooting ergonomics from WP Webhooks,
Uncanny Automator, and AutomatorWP:

- WP Webhooks proves that external integration surfaces need explicit
  endpoints, authentication posture, test/send actions, delivery diagnostics,
  and payload clarity.
- Uncanny Automator and AutomatorWP prove that trigger/action language,
  readable integration cards, recipe step status, and run/error feedback help
  operators understand external automation.

Adapter should not copy their product boundaries. Adapter is a thin AI-client
channel that calls Core and WordPress Abilities API. It is not a generic
workflow builder, trigger/action marketplace, retry queue, approval store, or
final write authority.

## Reference Sources

| Reference | Relevant pattern | Adapter learning |
| --- | --- | --- |
| [WP Webhooks](https://wordpress.org/plugins/wp-webhooks/) | Webhook triggers/actions, authentication, request/response mapping, testing, and delivery debugging. | Make external route, auth, payload, and failure expectations clear for AI clients. |
| [Uncanny Automator](https://wordpress.org/plugins/uncanny-automator/) | No-code recipe language using triggers and actions across integrations. | Borrow operator-facing step/status language, not recipe ownership. |
| [AutomatorWP](https://wordpress.org/plugins/automatorwp/) | Trigger/action automation across plugins with logs and integration-oriented setup. | Borrow integration onboarding and run feedback patterns, not workflow runtime state. |
| [WordPress Application Passwords](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) | Standard WordPress REST authentication option for external clients. | Keep Application Password guidance clear while preserving signed key-pair pairing for higher-security local clients. |

## Current Adapter Baseline

Current Adapter docs and contracts already preserve the main boundary:

- `README.md` positions Adapter as a thin AI client channel.
- `docs/external-ai-client-contract.md` defines startup sequence, reads,
  governed writes, Cloud boundaries, correlation context, and failure rules.
- `docs/openclaw-quickstart.md` explains Application Password and signed
  key-pair device pairing flows.
- `docs/keypair-device-pairing-contract.md` defines Ed25519 pairing, polling,
  scopes, and signed request verification.
- `docs/admin-developer-reference.md` keeps verbose route/catalog diagnostics
  out of the default WordPress admin connection page.
- Adapter `/health`, `/help`, `/connection/manifest`, and `/capabilities`
  provide readiness and discovery without replacing Core or WordPress
  Abilities API.

These notes therefore look for onboarding and feedback clarity improvements
only. They do not ask Adapter to expand runtime ownership.

## Similar Capability Matrix

| Adapter surface | Similar mature pattern | Borrow | Do not borrow |
| --- | --- | --- | --- |
| Connection manifest | Webhook endpoint setup and integration cards | Keep base URL, route list, auth method, dependency readiness, and capability fingerprints easy to copy and verify. | Do not turn manifest into a recipe catalog, Agent Gateway catalog, or Cloud connector registry. |
| Application Password flow | Standard WordPress REST external authentication | Keep secret-handling language explicit: show once, paste only into secret fields, never into prompt/log/proposal payloads. | Do not store raw passwords or build a separate credential vault in Adapter. |
| Signed key-pair pairing | Device pairing and webhook signing mental model | Keep verification URL, user code, key fingerprint, scopes, and signed request wrapper clear. | Do not store private keys or accept unsigned fallback writes. |
| Health/help routes | Webhook testing and one-click diagnostics | Provide a fast readiness check that explains missing Core, Toolkit, or auth dependencies. | Do not make health route a broad site diagnostics collector or Cloud status console. |
| Payload preview | Webhook request/response mapping | Document expected request body, allowed `log_context`, blocked fields, and error feedback. | Do not create a generic payload transformer or workflow expression engine. |
| Operator feedback | Automation run/error feedback | Preserve `data.operator_feedback` and explain revised-proposal next actions. | Do not auto-retry blocked proposals or downgrade into direct writes. |
| Correlation IDs | Delivery/run logs | Keep `proposal_id`, `correlation_id`, `adapter_request_id`, and external thread ids visible for support. | Do not treat correlation context as approval, execution token, or provider log truth. |
| Execution path | Automation action execution | Keep explicit post-Core execution profiles and per-action status for allowlisted abilities. | Do not add generic trigger/action runtime, queues, leases, or arbitrary write execution. |

## Borrow

Future Adapter onboarding work should borrow these patterns:

- Clear setup sequence: health, help, connection manifest, capabilities, then
  read/proposal calls.
- Readiness cards: show Core, Toolkit, WordPress Abilities API, auth, and
  execution-profile readiness separately.
- Copyable non-secret manifest: endpoint URLs, route names, and placeholders
  are useful; secrets must stay out of copied handoff text.
- Testable connection: provide one safe path to prove signed or Application
  Password auth before a client creates proposals.
- Payload discipline: examples should separate ability input,
  `log_context`, proposal payload, and execution route parameters.
- Failure guidance: when a route returns `operator_feedback`, tell clients to
  create a revised proposal instead of retrying execution.
- Support handles: proposal id, display/status route, correlation id, and
  adapter request id should be easy to find for debugging.

## Do Not Borrow

Do not copy these automation-product surfaces into Adapter:

- visual recipe builders;
- generic trigger/action marketplaces;
- workflow runtime state;
- run queues, retries, leases, dead-letter handling, or schedulers;
- external app catalogs;
- provider/model/prompt routes;
- Cloud connector settings or Cloud signing clients;
- Core approval state or audit truth;
- generic approve/reject proxy;
- arbitrary final write execution.

If a future idea needs those surfaces, route it to Toolkit, Core, Workflow
Toolbox, Cloud Addon, Cloud, or a separate runtime owner before implementation.

## Candidate Improvements

These are candidate notes only. They should not be implemented until a scoped
Adapter onboarding issue is opened.

### P1 - Preserve

Keep these existing Adapter choices:

- `/health`, `/help`, `/connection/manifest`, and `/capabilities` as the
  startup sequence.
- Application Password and signed key-pair pairing as distinct connection
  options.
- `data.operator_feedback` as the user-facing blocked-state handoff.
- Proposal status lookup instead of an Adapter-owned approval console.
- Core approval, preflight, and audit truth.
- Explicit execution profiles rather than generic final write execution.
- Developer reference docs outside the default admin connection page.

### P1 - Clarify

Potential future documentation or admin-surface improvements:

- Add a compact "connection readiness" checklist that mirrors mature webhook
  setup pages: auth, Core dependency, Toolkit dependency, Abilities API,
  manifest, and capabilities.
- Make copied client handoff text label every secret placeholder clearly and
  avoid any raw Application Password or signature material.
- Add one safe "test signed request" or "test Application Password request"
  doc section that stops before proposal creation.
- In examples, visually separate `ability_input` from `log_context` so clients
  do not forward reserved correlation fields into Toolkit ability input.
- In failure docs, group errors into: auth failure, dependency missing, Core
  governance blocked, unsupported execution profile, and client must revise
  proposal.

### P2 - Investigate Later

Only after a current Adapter admin screenshot and local client smoke:

- Whether `/connection/manifest` should include a more copyable readiness
  summary for local clients.
- Whether the admin page needs a clearer last-tested status without storing
  provider/runtime logs.
- Whether developer examples should include one minimal WP Webhooks-style
  request/response transcript for support.

These are not current implementation items.

## Suggested Next Artifact

The next artifact should be an Adapter UX/design issue or screenshot note, not
code:

```text
Title: Compare Adapter connection onboarding against webhook and automator plugin patterns

Scope:
- Review current Adapter admin connection page and developer reference.
- Compare against WP Webhooks endpoint/testing/debugging language.
- Compare against Uncanny Automator and AutomatorWP integration onboarding.
- Propose no more than three presentation-only improvements.

Non-goals:
- No REST route changes.
- No auth changes.
- No execution profile changes.
- No workflow runtime.
- No queue/retry state.
- No Core approval proxy.
- No Cloud connector ownership.
```

## Decision Gate

Before any Adapter implementation derived from this note, answer yes to all of
these:

1. Does it make external AI client connection safer or clearer?
2. Does it preserve Adapter as a thin channel layer?
3. Does it continue to route reads through WordPress Abilities API and writes
   through Core proposal/preflight plus explicit Adapter execution profiles?
4. Does it avoid workflow runtime, queues, provider/model/prompt ownership, and
   Cloud connector ownership?
5. Can it be verified with `composer test:all`, and only with smoke/acceptance
   gates if WordPress runtime behavior actually changes?

If any answer is no, do not implement it inside Adapter.
