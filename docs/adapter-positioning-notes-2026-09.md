# Adapter Positioning Notes - 2026-09

Status: 2026-09-30, product positioning reference and phased roadmap from the
2026-09 positioning review.

This document consolidates the positioning review into durable guidance for
Adapter-owned channel decisions. It does not change any REST contract, does
not authorize MCP runtime in Adapter, and does not move platform rules into
this repository. Platform-level items are flagged for coordination from
`npcink-workflow-toolbox` `docs/platform/README.md`.

## Positioning statement

Adapter is not the connector between AI clients and WordPress. Connection is
being standardized by WordPress itself (Abilities API, official MCP Adapter).
Adapter is the governed channel: the approval gate, execution allowlist,
sensitive-read authorization, and audit correlation layer between an AI
client and WordPress writes.

External narrative, one line: other approaches give the AI client your admin
credentials; the Npcink channel gives it your approval.

## Problem solved (versus WordPress core REST)

| | WordPress core REST | Adapter channel |
| --- | --- | --- |
| AI wants to write | Immediate effect under the credential | Proposal to Core, human approval, commit-preflight, then allowlisted execution |
| Write scope | Everything the credential allows | Explicit execution profiles, fail closed on undeclared shapes |
| Sensitive reads | Full response bodies | Redaction-bounded; `core_read_authorization_required` reads need a Core grant |
| Accountability | Standard REST logging | Governance audit correlation plus AI Request Logs context |
| Boundary | None | Enforced with key-pair pairing (see [`threat-model.md`](threat-model.md)) |

If a use case does not need the approval gate, WordPress core REST or an
official-surface integration is the right answer and Adapter adds no value.
The gate is the product.

## Ecosystem snapshot (2026-09)

- Official: the [WordPress MCP Adapter](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter)
  (announced 2026-02) exposes Abilities as MCP tools. Authentication rides
  WordPress identities such as Application Passwords; there is no approval
  primitive, and published security guidance leans on permission callbacks,
  dedicated limited users, and preferring read-only abilities.
- Community MCP plugins, for example
  [NIBWP](https://en-ca.wordpress.org/plugins/nibwp/),
  [AcrossAI MCP Manager](https://wordpress.org/plugins/acrossai-mcp-manager/),
  and [AutoWP MCP](https://github.com/Njengah/autowpmcp): direct CRUD over
  `wp/v2` or abilities; approval, where present at all, is a client-side
  confirmation dialog.
- In-dashboard assistants, for example [AgentWP / WPAI](https://wpai.co):
  AI acts directly inside a wp-admin session.
- Workflow automation plugins with human-approval nodes, for example
  [AI Workflow Automation](https://en-gb.wordpress.org/plugins/ai-workflow-automation/):
  approval exists, but only inside that plugin's own internal workflows, not
  for external AI clients.

Finding: as of 2026-09, no surveyed solution provides a server-side
proposal, human approval, preflight, allowlisted execution, and audit loop
for external AI clients. That gap is the reason this stack exists. The same
snapshot shows the connection half of the market is already commoditized by
the official adapter, so Adapter must not compete on connection features.

## Strengths to keep and compound

1. Server-side governance truth in Core: approval state survives client
   switches and cannot be rephrased away.
2. Enforced key-pair boundary: unique among surveyed options; the client
   holds no credential that WordPress core accepts.
3. Sensitive-read authorization plus redaction.
4. Audit correlation between Core governance audit and AI Request Logs.
5. Built on the official Abilities API direction (WordPress 7.0 floor).

## Weaknesses to manage honestly

1. Connection-layer value is commoditized; only the governance half is
   defensible.
2. Complexity: Core plus Toolkit plus Adapter plus CLI, versus one-minute
   single-plugin installs elsewhere.
3. Arrival time from install to first approved action is high.
4. REST and OpenClaw-first, while mainstream client traffic is moving to
   MCP; governance currently protects only clients that enter this channel.
5. Application Password mode is conventional, not enforced; see
   [`threat-model.md`](threat-model.md).

## Product principles

1. Sell the gate, not the door. Docs, admin UI, and README lead with the
   approval loop, not with client onboarding convenience.
2. Never add an approval-free write path. No fast lane, no convenience
   bypass, no "trusted client" mode. A feature request that weakens the gate
   is out of scope regardless of demand.
3. Keep enforcement honesty visible. Enforced versus conventional
   authentication classes stay distinguishable in UI copy, docs, and health
   surfaces; Phase 2 adds `client_policy.boundary_enforcement`.
4. Do not compete with official layers. Connection and discovery are
   commodity; Adapter value lives in the governance layer above them.
5. Complexity is a budget. Track time-to-first-approved-action; every added
   step in onboarding or operation needs an explicit justification.
6. Fail closed, allowlist only. Existing engineering rule, restated because
   it is also the product moat.
7. Stay thin. Ability definitions belong to Toolkit, approval and audit
   truth to Core, runtime and MCP to Toolbox, Cloud to the addon. Boundary
   discipline is the moat, not overhead.

## Phased roadmap (from the 2026-09 review)

| Phase | Item | Home | Status |
| --- | --- | --- | --- |
| 1 | `docs/threat-model.md`; this document; README links | Adapter | Done 2026-09-30 |
| 2 | `client_policy.boundary_enforcement` (`enforced` / `conventional`) on `/health`, `/help`, `/connection/manifest`; admin UI labels the Application Password fallback a conventional boundary; README leads with the governed-channel story; `CLIENT_POLICY_VERSION=2` with contract tests | Adapter code, own topic branch | Done 2026-09-30 |
| 3 | MCP surface for governed actions (expose proposal/read/execute as MCP tools with Core as truth) | `npcink-workflow-toolbox` (platform decision; never Adapter) | Requires platform decision |
| 3 | Suite onboarding wizard; target time-to-first-approved-action under 10 minutes | Suite distribution / Core admin surface | Requires platform decision |
| 3 | Audit timeline flagship view: what the AI did, who approved it, what was blocked | `npcink-governance-core` admin | Requires platform decision |
| 4 | Upstream conversation: propose an approval or consent hook for the official WordPress MCP Adapter / Abilities | External, ongoing | Not started |
| 4 | Target-market focus: agencies, production sites, unattended nightly flows | Positioning only | Continuous |

## Explicit non-goals of this document

- No REST contract changes and no new routes.
- No MCP runtime, queue, or workflow state in Adapter.
- No weakening of boundary rules in `AGENTS.md`; where this document
  restates a rule, `AGENTS.md` and the platform documents remain
  authoritative.

## Sources

- [From Abilities to AI Agents: Introducing the WordPress MCP Adapter](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter)
- [NIBWP - WordPress MCP Server](https://en-ca.wordpress.org/plugins/nibwp/)
- [AcrossAI MCP Manager](https://wordpress.org/plugins/acrossai-mcp-manager/)
- [AutoWP MCP](https://github.com/Njengah/autowpmcp)
- [AI Workflow Automation](https://en-gb.wordpress.org/plugins/ai-workflow-automation/)
- [WPAI / AgentWP ecosystem](https://wpai.co)
