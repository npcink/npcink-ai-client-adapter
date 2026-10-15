# M4 蒲公英验收与命名 Stack RC 准备记录 — 2026-10-15

状态：M4 本地自动化验收通过；默认中央质量矩阵通过；命名 Stack RC 已形成可审阅候选，但尚未创建 tag、GitHub Release、WordPress.org 发布或生产部署。

## 范围与边界

本记录只收敛 Adapter 侧的验收证据、跨仓矩阵结果和候选版本清单。Adapter
仍是 OpenClaw 兼容的薄通道层，不新增 Ability callback、Core 审批真相、Cloud
运行时、Provider 凭据或通用最终写入执行器。`core_proxy_execute=false` 和
`commit_execution=false` 继续保持。

M4 运行在本地开发站 `https://magick-ai.local/`，不代表真实用户验收，也不代表
生产 Cloud 传输链路已验收。人工后台批准与自动管理员上下文是两类证据；本记录只
引用已明确完成的自动化验收和此前已完成的人工附件批准事实。

## M4 蒲公英自动化验收

通过蒲公英地址 `172.16.3.35` 使用已验证 SSH 身份连接 M4。远端 Docker Server
为 29.7.2，Ollama 为 0.32.5，模型为 `qwen2.5:1.5b`。Ollama 仍只监听 M4 的
`127.0.0.1:11434`；验收只建立临时 SSH loopback 隧道，不开放远端服务端口。

| 验收项 | 结果 |
| --- | --- |
| Core WordPress 7.0 / PHP 8.0 | 1,614 条断言通过，真实 release ZIP 安装 |
| Core WordPress 7.0 / PHP 8.5 | 1,614 条断言通过，真实 release ZIP 安装 |
| Toolkit WordPress 6.9.4 / PHP 8.0 | 478 条断言通过 |
| Toolkit WordPress 7.0 / PHP 8.5 | 478 条断言通过 |
| `composer acceptance:cross-repo-release` | 通过，无 skip 参数 |
| `/proposals?limit=abc` | HTTP 400，`rest_invalid_param` |
| `/read-requests?limit=abc` | HTTP 400，`rest_invalid_param` |
| Ollama Provider-log 关联 | 通过；Provider、model、proposal、correlation、Adapter request 及嵌套 Core 字段均匹配 |
| 签名 HTTPS 附件删除 | 两个删除分支、备份恢复、重复执行拒绝、readback、清理均通过 |
| Adapter PHPStan / PHPCS | 通过；仅有既存 `INPUT_*` 重复定义 warning，无错误 |

详细的本地 JSON、Docker smoke 证据和日志仍在忽略目录 `build/` 中：

- `build/m4-pgy-acceptance-2026-10-15.json`
- `build/m4-wordpress-smoke-2026-10-15.json`
- `build/m4-toolkit-smoke-2026-10-15.json`

这类文件用于本机复核，不进入发布包。验收结束后临时 SDK、MU loader、缓存、
SSH 隧道、测试容器/卷、草稿、附件和备份均已清理；Provider registry 已恢复为
仅 `npcink-cloud`。

首轮 Provider-log 关联失败是验收夹具的识别错误：临时 loader 按“主机+端口”
匹配，而 AI 插件按解析后的 hostname 匹配。修正夹具后严格关联通过，业务代码和
日志字段没有被修改。这个经验要求后续验收区分“测试夹具发现逻辑”与“产品日志
关联逻辑”，不能用放宽断言掩盖夹具错误。

## 默认中央质量矩阵

运行命令：

```bash
cd /Users/muze/gitee/npcink-workflow-toolbox
composer quality:matrix:run -- --fail-on-dirty
```

最终一轮报告生成于 `2026-10-15T09:20:31+00:00`，没有请求 M4 runtime gate，结果为
7/7 通过：

| 仓库 | 当前根分支 | 工作树 | 门禁 |
| --- | --- | ---: | --- |
| `npcink-abilities-toolkit` | `master` | clean | `composer test:all` passed |
| `npcink-governance-core` | `codex/docs-week-2026-10-13-followup` | clean | `composer test:all` passed |
| `npcink-ai-client-adapter` | `codex/m4-pgy-stack-rc-docs-2026-10-15` | clean | `composer test:all` passed |
| `npcink-workflow-toolbox` | `codex/internal-link-recommendation-followup` | clean | `composer test:all` passed |
| `npcink-cloud-addon` | `codex/041-maintenance-closeout-20261015` | clean | `composer test:all` passed |
| `npcink-eval-lab` | `main` | clean | Provider offline gate and self-check passed |
| `npcink-ai-cloud` | `codex/provider-refresh-advisory` | clean | exact-SHA GitHub CI passed |

Cloud 矩阵只判定当前精确 SHA 的五项必需 check-run：`backend`、`frontend`、
`Secret scan`、`Analyze (python)`、`Analyze (javascript-typescript)`。它不等于
“所有 Cloud workflow 全绿”：合并后的 master 仍有 Python CVE Upstream Watch
失败记录，另有 OCR advisory 失败记录，需作为 Cloud owner 的独立后续事项。

## 命名 Stack RC 候选

候选名称：

```text
stack-rc-2026-10-15-core-0.3.0-adapter-0.4.3-toolkit-0.7.0
```

候选清单只作为审阅基线，不移动历史 tag：

| 组件 | 版本 | 当前验收/矩阵 revision |
| --- | --- | --- |
| Governance Core | 0.3.0 | `48411354cdf25ba2970e6ef9cedf89715261ab2a` |
| AI Client Adapter | 0.4.3 | `c96e85f4f689bb8e68bf183c2a852a5cad7aa1a5` |
| Abilities Toolkit | 0.7.0 | `921d1dc7cb5661fef10ca8f83b781690c98034cb` |
| Workflow Toolbox | — | `90eb43768a1fc32b98795393c0a21c8df9021a7a` |
| Cloud Addon | 0.4.1 | `014fde9f44620320a8940b0e1f4e998cc04d3f00` |
| Eval Lab | — | `5362655d9f27f9db9a3bcc8a7717e75ac1d9659f` |
| AI Cloud | — | `fca129f87ad792e869a4ced5a9f2df4ff93eaceb` |

该清单仍是 `review_only_pending_coherent_release_refs`：Core 和 Toolbox 当前
根分支是干净的 topic 分支，不能把它们自动改写成 master，也不能把一次矩阵通过
误写成所有组件已经形成同一发布提交。真正创建并推送命名 RC tag 前，需要先确认
组件 revision 是否就是本次发布意图，并取得最终发布批准。

## Worktree 与发布收尾边界

根 worktree 在矩阵运行时均干净。只读审查显示 Adapter、Toolkit、Core 各有 1
个 worktree；Toolbox 有 13 个，Cloud Addon 有 4 个，AI Cloud 有 7 个，其中多项
由其他会话锁定或持有。它们属于并行任务上下文，不能在本会话中 reset、切换、归档
或删除。因而本记录只声称“本轮使用的根 worktree clean”，不声称“所有本地和云端
worktree 已清理”。

## 下一步与完成条件

1. 由各仓库 owner 确认 Core、Toolbox 及 Cloud 的候选 revision，形成单一可复现
   的 stack manifest；必要时重新跑同一矩阵。
2. 单独排查 Cloud 的 OCR advisory 与 Python CVE Upstream Watch 失败；在结论
   明确前，不写“所有 Cloud workflow 全绿”。
3. 若需要真实用户/编辑器验收，单独记录人工路径，不把自动管理员上下文或本地
   M4 夹具结果替代人工证据。
4. 完成上述复核后，再由操作者批准创建命名 stack RC tag；正式发布、生产部署和
   WordPress.org SVN 操作另行取得最终批准。

## 回滚

本次变更仅新增/更新文档，回滚为文档 revert。M4 临时资源已清理，不需要数据库
迁移或服务回退；历史 Core/Toolkit tag 保持不变。
