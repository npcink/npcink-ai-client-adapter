# 工作审视报告 — Adapter 会话收尾，2026-10-17

状态：功能修复与本地验收已完成；本记录及 M4 文档进入同一收尾 PR。
全项目待办、生产发布和跨仓 Git 清理尚未全部完成。关闭旧会话只表示交接完成。

## 原定目标

核查会话「检查项目待办并给出建议」
（`01a132ca-2e6b-7e70-895e-0f640af91c76`）从首次待办盘点到最后中断的历史，
分别判断问题修复、提交合并、分支/worktree 清理和经验文档落地。

变更范围仅为 Adapter 的验收入口、10 月 15 日历史记录的 SHA 更正和本报告。
不改运行时代码、公共 REST、Core 审批/预检、Toolkit callback 或 Cloud 传输。
不移动历史 tag、采用新 RC revision、部署或改动其他仓库。提交门禁为
`composer test:all`、`composer analyse:php`、`composer lint:standards`、
Composer metadata、WordPress.org guard 和 diff 检查；跨仓结果引用中央实际运行。
回滚为文档 revert。历史精确 source ref 应继续保留。

## 完成情况

- [x] Adapter #102–#118 已由 GitHub PR 状态证实合并，包含 `limit=abc` 的
  HTTP 400 修复、附件删除/恢复通道、Provider 日志上下文修复、本地自动验收、
  依赖更新和可重复发布包。Toolkit REST 注册和删除能力的归属及验收见此前记录。
- [x] 本地真实 Provider 日志关联、人工后台批准、签名 HTTPS 删除/恢复、
  重复执行拒绝、包安装恢复及 M4 Docker 验收已有历史证据；本次未重跑实时站点。
- [x] 0.4.2 曾发布至 WordPress.org；GitHub 最新 Release 仍为 v0.3.2，
  本次远端查询未发现 Adapter `v0.4.3` tag。
- [x] Core 已发布命名 `stack-rc-2026-10-16-core-0.3.0-adapter-0.4.3-toolkit-0.7.0`
  快照，tag object 为 `ffb5e5d6d7b68fa952288524a181ff688a7698ee`。
  [Core 发布说明](../../../npcink-governance-core/docs/release-candidate-notes-2026-10-16.md)
  是权威记录；不能再沿用 10 月 15 日“尚未创建 tag”的当前状态结论。
- [x] Adapter 精确文档提交 `eb73e503a8c102acd6f87b69f406ee4b94aac85f`
  已推送至 `origin/codex/m4-pgy-stack-rc-docs-2026-10-15`。本轮核查时尚无 PR，
  因此从该提交创建独立收尾分支，保留原远端分支不变并通过标准 publisher 合并文档。
- [ ] Adapter 0.4.3 正式发布、生产部署和真实用户连续四周台账未完成。
- [ ] 最早建议中的异步任务/202 边界、配对收件箱、测试连接按钮及密钥到期/轮换
  决策仍为后续项。Quickstart 仍含固定本地站点示例，集成入口整理也不能全标完成。
  这些建议不构成本次自动扩展运行时代码的范围。
- [ ] Cloud transport 场景的同等 Provider 日志证据仍须其 owner 验证；本地
  Ollama 日志通过不替代该证据。Cloud Python CVE Upstream Watch 最新检查失败，
  OCR 交付例外也不能写为“AI review 全绿”。

## 当前矩阵与 Git 清理事实

从 Toolbox 默认根运行 `composer quality:matrix:run -- --fail-on-dirty --json`
的报告时间为 **2026-10-17 13:36:20 Asia/Shanghai**。六行通过；AI Cloud
`needs_validation`（exit 78），根目录有三处 owner-held 修改，整体 exit 1。
报告的 `failures=2` 同时计入 dirty 和 gate 状态，并非两个仓库失败。
原始报告在本机 `/tmp/adapter-closeout-matrix-2026-10-17.json`。
本次没有路径覆盖，也没有请求 `--cloud-m4`；旧 7/7 只证明各自历史快照。

| 仓库 | 本次盘点事实 | 处置 |
| --- | --- | --- |
| Adapter | 一个干净主 worktree；无 stash；远端 master 与精确源码保全分支 | 合并收尾文档；保留远端精确引用，之后仅清理本次临时 topic |
| Core | 一个干净主 worktree；当前 receipt 分支领先 origin/master 两笔文档提交 | 由 Core owner 处理文档发布和保留分支 |
| Toolkit | 一个干净 master worktree；远端仅 master；本地仍有两个 topic | owner 核实 patch/PR 与用途后处置 |
| Toolbox | 一个干净 master worktree；保留 topic 和一个 stash | owner 处置，stash 不因旧问题修复而自动可删 |
| Cloud Addon | 一个干净主 worktree；本地 master 落后已知 origin/master 两笔 | owner 同步和决定 revision，不代其切换 |
| Eval Lab | 一个干净 main worktree；保留多个历史 topic | 归属另核，不宣称已全部合并或可删 |
| AI Cloud | 两个 worktree；主目录三处修改，另一个 detached 干净 | 保留活动改动及辅助 checkout |

跨仓盘点是共享目录的时间快照；其他会话可以继续推进。历史源保全要求见
[Core 保全记录](../../../npcink-governance-core/docs/history/adapter-preservation-and-core-docs-publication-2026-10-17.md)。
原 `eb73e50` 不能因 squash 后得到新 SHA 就失去远端可获取入口。

## 发现的问题

| 严重程度 | 问题与根本原因 | 改进 |
| --- | --- | --- |
| 必须改正 | `2026-10-15-m4-pgy-stack-rc-readiness.md` 表格仍引用 amend 前 `c96e85f`，提交后未同步精确身份 | 更正为最终 `eb73e50`；保留原 snapshot 和 source ref |
| 应当改正 | M4 最后文档只有提交/远端保全，尚无 PR；执行中断后未完成发布检查 | 本轮通过标准 protected PR 补交，合并与验收分别报告 |
| 应当改正 | 历史总结只看主目录 clean 或旧矩阵，易遗漏未实现建议和保留资源 | 回读完整历史，独立列出产品后续、stash、源码 pin、矩阵及发布事实 |

## 做得好的地方

- 功能修复、真实人工批准、自动管理员上下文、Provider 日志和生产发布已有独立证据。
- 夹具清理保留 Core 审计；其他 owner 的源文件、stash、分支与 worktree 得到保全。
- 队列、Provider、审批和 callback 仍归各自 owner；Adapter 保持薄通道边界。

## 下次重点关注与经验入口

继续任务时先读本报告，重新读取当前 Git/矩阵状态。真实用户计划仍以
[Core 台账](../../../npcink-governance-core/docs/real-usage-validation.md)为准，不能填入合成测试。
异步执行先作 owner 边界决策，连接 UX 和密钥生命周期分别确定范围后再实施。
后续发布候选重新绑定各组件 revision，不把共享 master 的新结果写回固定 manifest。

可复用验收规则已写入 [本地客户端 smoke 入口](../acceptance/local-ai-client-smoke.md)。
历史原因和方法见 [本地验收经验](2026-10-13-local-acceptance-session-checkpoint.md)、
[实际验收与发布准备](2026-10-13-local-acceptance-release-readiness.md)、
[AI review 经验](../ai-review-loop-lessons-2026-10-06.md)。共享规范仍从
[平台索引](../../../npcink-workflow-toolbox/docs/platform/README.md)进入，未复制到 Adapter。

本轮文档检查通过：`composer test:all`、`composer analyse:php`（仅既存
`INPUT_*` 重复定义 warning）、`composer lint:standards`、
`composer validate --no-check-publish`、`composer check:wporg`、本地链接及
`git diff --check`。文档不进入发布包；本轮未新增运行时 smoke 或生产发布证据。
