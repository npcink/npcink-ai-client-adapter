# WordPress.org 上架文案草稿 - 中文

## 插件名称

Npcink AI Client Adapter

## 简短描述

通过薄 REST 适配器，将 OpenClaw 等本地 AI 客户端连接到受治理的 WordPress 能力。

## 标签建议

ai, governance, automation, rest-api, abilities

## 插件介绍

Npcink AI Client Adapter 通过一个聚焦的 REST 适配器，将 OpenClaw 兼容工具等本地
AI 客户端连接到 WordPress 站点。

它适用于使用 Npcink Governance Core 管理可审查 AI 操作、并使用 Npcink
Abilities Toolkit 等 WordPress Abilities API provider 提供具体 WordPress 能力的站点。
Adapter 为客户端提供一个稳定的 WordPress REST 入口，用于检查就绪状态、读取治理指引、
运行已批准的读取能力、创建受治理的 proposal，并且只在 Core approval 和
commit-preflight 之后执行受支持的写入。

默认连接方式使用安全密钥配对，因此 AI 客户端不需要接收 WordPress Application
Password。对于已经有专用 secret 字段或凭据库的客户端，仍然保留 WordPress
Application Password 作为备用连接方式。

Adapter 有意保持很薄。它不定义能力、不保存 approval truth、不运行 workflow queue、
不充当 MCP server、不管理模型 provider、不保存 prompt，也不执行任意最终写入。
治理仍然属于 Npcink Governance Core。能力定义和 callback 仍然属于 Npcink
Abilities Toolkit 或其他 WordPress Abilities API provider。

Adapter 在 health、help 和 connection manifest 中暴露机器可读的 `client_policy`，
让兼容客户端理解明确的 route、output、sensitive-read 和 write-flow 边界。本地 CLI
也会从输出中隐藏 profile path、key id、签名 header、token、password 和 secret。

当前受治理执行只覆盖 Core approval 之后的明确 Adapter profile，包括
`npcink-abilities-toolkit/trash-post`、`npcink-abilities-toolkit/create-draft`、
`npcink-abilities-toolkit/update-post`、`npcink-abilities-toolkit/update-post-blocks`、
`npcink-abilities-toolkit/set-post-terms`、`npcink-abilities-toolkit/reply-comment`
和 `npcink-abilities-toolkit/approve-comment`。

## 核心功能

- 通过一个 Adapter REST namespace，将 OpenClaw 兼容及类似本地 AI 客户端连接到
  WordPress。
- 在客户端开始工作前检查 Adapter 就绪状态和依赖状态。
- 暴露机器可读的 `client_policy`，让客户端理解 route、read、write 和敏感数据边界。
- 通过 WordPress Abilities API 路由已批准的 direct-read 请求。
- 将受治理的写入请求转发到 Npcink Governance Core proposal 和 commit-preflight 端点。
- 在 Core approval 之后，为明确 allowlist 的 execution profile 提供人工批准后的
  执行路径；统一的 approve-and-execute 操作仅限 WordPress 管理员会话使用。
- 优先使用本地客户端安全密钥配对，并在合适场景下保留 Application Password 备用方式。
- 保持 channel、governance、ability、cloud 和 model-provider 职责分离。

## 适合谁使用

- 需要把 OpenClaw 兼容或类似本地 AI 客户端连接到 Npcink 站点的 WordPress 管理员。
- 需要一个经过认证的 WordPress REST 入口的 AI 客户端环境。
- 使用 Core 作为治理层、使用 Abilities API provider 作为执行合约层的托管环境。
- 希望清晰分离 channel、governance、ability、cloud 和 model-provider layers 的开发者。

## 环境要求

- WordPress 7.0 或更高版本。
- PHP 8.0 或更高版本。
- Npcink Abilities Toolkit 用于能力定义和 callback。
- Npcink Governance Core 用于受治理的 proposal、approval、commit-preflight 和 audit。

## 常见问题

### Npcink AI Client Adapter 是做什么的？

它为 OpenClaw 兼容及类似本地 AI 客户端提供一个聚焦的 WordPress REST 适配层，
用于连接 Npcink Governance Core 和 WordPress Abilities API provider。

### Adapter 会审批 proposal 吗？

Adapter 提供一个 `approve-and-execute` 操作，用于受支持的 execution
profile，但该操作仅限 WordPress 管理员会话使用。签名 AI 客户端需要等待
人工在 Core 后台批准后再执行。proposal storage、approval、commit-preflight
和 audit 仍然由 Npcink Governance Core 负责。

### Adapter 会执行任意 ability 吗？

不会。Adapter 只会在 Core approval 和 commit-preflight 之后，执行明确受支持的
execution profile。它不是通用 ability executor，也不会绕过 Core governance。

### 是否需要其他 Npcink 插件？

需要。Npcink Abilities Toolkit 提供 Adapter 使用的能力定义和 callback。Npcink
Governance Core 负责受治理的 proposal、approval、commit-preflight 和 audit。

### 哪些 AI 客户端可以连接？

Adapter 面向 OpenClaw 兼容本地客户端和类似工具。这些客户端需要能够调用经过认证的
WordPress REST endpoint，并读取 Adapter 的 `client_policy`。

### Adapter 会把 WordPress 密码发送给 AI 客户端吗？

推荐的安全密钥配对路径不需要把 WordPress Application Password 交给客户端。
Adapter 只保存已批准的公钥。Application Password 路径仍然作为备用方式保留，
适用于有专用 secret 字段或凭据库的客户端。

### Adapter 会运行 AI 模型或保存 prompt 吗？

不会。Adapter 是 channel layer。它不提供模型路由、prompt 管理、provider 凭据、
workflow queue 或 hosted AI execution。

### 如果依赖插件缺失会怎样？

Adapter 的 health 和 help route 可以报告依赖状态。需要 Npcink Governance Core
或 WordPress Abilities API provider 的 route 会 fail closed，并返回结构化的
missing dependency error。

## 截图说明

1. Adapter 连接页总览，包括站点就绪状态、有效设备数量，以及推荐的安全密钥配对流程。
2. 有效密钥配对设备，包括设备标识、上次使用时间、状态标签、撤销操作和已撤销设备摘要。
3. WordPress Application Password 备用连接流程，适用于使用专用 secret 字段或凭据库的客户端。

## 系列插件边界

在 Npcink 系列插件中：

- Npcink Abilities Toolkit 负责能力定义和 ability callback。
- Npcink Governance Core 负责治理、审批、preflight、audit。
- Npcink AI Client Adapter 负责 AI 客户端通道适配。
- Npcink Cloud Addon 负责链接云端服务。

这个分层让 Adapter 专注于连接和路由，让 Core 保持治理真相来源，让 Abilities
保持能力合约层。
