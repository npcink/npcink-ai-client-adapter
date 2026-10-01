# Npcink AI Client Adapter Positioning

## English

Npcink AI Client Adapter is the thin AI client channel plugin for WordPress. It gives
OpenClaw-compatible and similar AI clients one WordPress REST namespace for reading Npcink Governance Core capability
guidance, running approved direct-read abilities through the WordPress
Abilities API, creating Core proposals, and executing one allowlisted write
after a human approves the proposal in the Core admin (a unified
approve-and-execute action remains available to WordPress administrator
sessions).

Npcink AI Client Adapter is part of the Npcink plugin family:

- `npcink-abilities-toolkit` - ability definitions and ability callbacks.
- `npcink-governance-core` - governance, approval, preflight, and audit.
- `npcink-ai-client-adapter` - AI client channel adaptation that calls Core and the
  Abilities API.
- `npcink-cloud-addon` - cloud service connection.

Adapter does not define abilities, store approval truth, run workflow queues,
act as an MCP server, expose generic approve/reject proxying, store provider
credentials, or execute arbitrary final write mutations.

## Chinese

Npcink AI Client Adapter 是面向 WordPress 的薄 AI 客户端通道插件。它为 OpenClaw 兼容及类似 AI 客户端
提供一个 WordPress REST namespace，用于读取 Npcink Governance Core 的 capability
guidance、通过 WordPress Abilities API 运行已批准的 direct-read abilities、
创建 Core proposals，并在人工于 Core 后台批准后执行 allowlist 内的写入（管理员
会话也可使用统一的 approve-and-execute 操作）。

Npcink AI Client Adapter 是 Npcink 系列插件的一部分：

- `npcink-abilities-toolkit` - 能力定义和 ability callback。
- `npcink-governance-core` - 治理、审批、preflight、audit。
- `npcink-ai-client-adapter` - AI 客户端通道适配，调用 Core 和 Abilities API。
- `npcink-cloud-addon` - 链接云端服务。

Adapter 不定义能力、不保存 approval truth、不运行 workflow queues、不充当 MCP
server、不暴露通用 approve/reject proxy、不保存 provider credentials，也不执行任意
最终写入 mutation。
