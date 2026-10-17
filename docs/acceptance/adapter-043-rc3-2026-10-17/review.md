# Adapter 0.4.3 RC3 — 候选清单、复验与发布方案

状态：工程复验通过，等待操作者审阅及正式发布确认。内部候选 ID 为
`adapter-0.4.3-rc3-2026-10-17`；它不是已创建或预留的 Git tag。

## 本次审阅对象

建议正式发布范围仅为 **Npcink AI Client Adapter 0.4.3**，源码固定在
`d3b114f73029836277f42d04c292f661b255a332`，拟用 tag `v0.4.3`。其他六仓是本轮兼容性基线，
不因列入本清单而获得发布或部署授权。后续审阅文档提交不自动替换这个候选 SHA。

机器清单：[candidate.json](candidate.json)，SHA-256：
`38abcf4d0a7ed60c220bc75083c67ffe05539e83388d9a5f452b44965360faf8`。
完整验收结果、时间、证据及日志摘要：[verification.json](verification.json)。

| 组件 | 冻结提交 | 声明版本 | 相对 10 月 16 日已发布快照的变化 |
| --- | --- | --- | --- |
| Adapter | `d3b114f73029` | 0.4.3 | 仅文档，发布包字节不变 |
| Core | `289ce777eff1` | 0.3.0 | 仅文档，测试包字节不变 |
| Toolkit | `b09ad3008a02` | 0.7.0 | 消费 Cloud anchor evidence |
| Toolbox | `a2ad8981e7d3` | 0.6.0 | 推荐反馈、Apply 检查、Lane 3 夹具与 ALT 卡片修复 |
| Cloud Addon | `3e650d1bc74d` | 0.4.1 | 推荐元数据传输与 reconciliation 修复 |
| Eval Lab | `5362655d9f27` | — | 无变化 |
| AI Cloud | `6fddd93d03b7` | — | 推荐 metadata、前端错误、运维诊断、usage 优化及测试/CI |

所有候选源码来自本次读取的远端已合并主线，不包含其他会话的未提交或未合并工作。
选择时使用独立检出目录；Core 的 M4 runner 要求 `.git` 目录，因此采用本地共享对象
clone，而非 `.git` 文件形式的 linked worktree。每个目录均 detached 于清单 SHA，
独立安装锁定依赖。共享仓库的分支和源文件未被切换或重置。
公开历史 stack tag 和固定 RC2 manifest 保持不变；完整逐提交差异在结果 JSON 中。

## 复验结果

| 验收 | 结果与证据范围 |
| --- | --- |
| 默认中央矩阵 | 7/7，exit 0；报告时间 `2026-10-17T06:00:34+00:00`，全部默认路径、dirty 0 |
| 冻结候选中央矩阵 | 7/7，exit 0；报告时间 `2026-10-17T06:01:20+00:00`，七个显式独立目录、dirty 0 |
| 现有跨仓 release acceptance | exit 0，耗时 256.22 秒，零 `[skip]`；Core/Adapter/Toolkit source、WP、Plugin Check、包及签名草稿夹具通过 |
| Core M4 实包安装 | WordPress 7.0 / PHP 8.0 与 8.5 各 1,614 条断言，精确 Core/Toolkit SHA 与包哈希验证通过 |
| Toolkit M4 | WordPress 6.9.4 / PHP 8.0 与 WordPress 7.0 / PHP 8.5 各 478 条断言通过 |
| Adapter 源码与 CLI | PHPStan、PHPCS、签名 health/help/manifest/catalog、CLI 测试通过 |
| 非数字 limit | 签名 HTTPS `/proposals?limit=abc`、`/read-requests?limit=abc` 均为 400 / `rest_invalid_param` |
| 真实 Provider 日志 | Ollama `qwen2.5:1.5b` 成功，持久化日志与 proposal、correlation、Adapter request 及嵌套 Core ID 匹配 |
| 附件 HTTPS 夹具 | 审批前拒绝、两个删除分支、保留备份恢复、重复拒绝、执行记录/readback 与素材清理通过 |
| 恢复与清理 | 原 symlink、激活列表、Provider registry 恢复；临时 marker/loader 与 loopback tunnel 清理通过 |

本地站点为 `https://magick-ai.local/`，WordPress 7.1.3；WP-CLI 使用 PHP 8.4.21。
初次绑定检查发现 FPM 使用旧目录缓存，在业务夹具开始前停止并恢复；清理 OPcache 后
重新证明 HTTP/FPM 实际加载五个候选 bootstrap，验收结束再次验证候选，恢复后验证原目录。
测试断言未放宽，产品代码未修改。

本轮批准使用独立管理员测试上下文；它不是新增的人工浏览器点击证据。
此前人工批准记录仍仅证明其历史运行。Core proposal/audit 证据保留，只有临时素材被清理。
这次结果也不构成真实用户观察或 Cloud transport 日志关联验收。
七个独立验证目录在核对 SHA 和工作区干净后已移除，临时 SDK 已删除；M4 上本轮
WordPress 测试容器无残留。原仓库、其他会话的 checkout 和已有 tunnel 未被清理。

## 可审阅安装包和回滚包

- [Adapter 0.4.3 候选 ZIP](../../../build/adapter-043-rc3-2026-10-17/artifacts/npcink-ai-client-adapter-0.4.3.zip)：176,899 bytes。
  SHA-256：`4356f90721d56b7484d42ad4a73b1ed0500295f21580ce75a656b5f9cba68fbc`。现有双构建可重复性检查通过；它与此前 0.4.3
  候选包相同，后续变化仅在 `.distignore` 排除的文档中。包内无 docs/tests/vendor/build。
- [官方 0.4.2 离线回滚包](../../../build/adapter-043-rc3-2026-10-17/artifacts/npcink-ai-client-adapter-rollback-0.4.2.zip)：
  175,297 bytes，SHA-256 `727e7dbee11469313e1a50ce7c9cbd7ed4ca42b7ae3f4ec571502948996ebc3f`。从官方版本下载地址取得，
  包内 header 与 Stable tag 均核对为 0.4.2；本轮未将其安装到站点。
- [发布说明草稿](../../../build/adapter-043-rc3-2026-10-17/release-notes-draft.md)直接取自已验收的
  `readme.txt` 0.4.3 changelog。完整本机原始日志在
  `build/adapter-043-rc3-2026-10-17/logs/`，其哈希保存在结果 JSON 中。

## 正式发布方案，待确认后执行

1. 操作者确认发布渠道：Adapter Git tag、GitHub Release、WordPress.org SVN。
   生产部署、npm CLI、其他插件和 Cloud 镜像不随此确认自动发布。
2. 发布前核对 `candidate.json` 校验和、候选源码可从 origin 获取、`v0.4.3` 仍不存在、
   ZIP 哈希及目标站点依赖。若需变更代码或依赖组合，建立新候选并重验；不默换当前清单。
3. 在精确 Adapter 提交创建并推送新的 `v0.4.3`，不移动任何历史 tag；GitHub Release
   使用审阅的发布说明和同一 ZIP。各步骤均保留可核对的外部结果。
4. 在独立 SVN 工作副本准备同一 ZIP 的解包内容，检查 trunk、`tags/0.4.3`、版本和
   package diff 后再提交。复查 WordPress.org 远端版本、下载包解包内容与更新可见性。
5. 如另行获准部署，先备份目标站点并核对依赖，再安装候选，验证 health、签名读取和治理
   拒绝路径。后续真实写入只针对专用可恢复夹具或操作者明确授权的对象。

回滚时暂停新写入请求，恢复已准备的官方 0.4.2 包并复查 health、签名读取和 limit 校验。
本轮相对 0.4.2 没有新增 Adapter 激活迁移；回滚不重置数据库，也不删除 Core 审批/审计。
已完成提案不能作为回滚后的重放任务。生产站备份及恢复确认须在实际部署时针对目标站完成。

## 审阅时需要明确的限制

- Core `v0.3.0` 和 Toolkit `0.7.0` 的公开历史 tag 指向较早提交。本清单中的同版本源码
  是兼容性验证基线，不是重发旧版本。若目标是整套公开发布，需要另定 Core/Toolkit 新版本，
  再做相应发布门禁；当前仅准备 Adapter 0.4.3。
- Cloud 精确 `6fddd93d03b7` 的五项必需 source checks
  已通过。独立 [Python CVE Upstream Watch](https://github.com/npcink/npcink-ai-cloud/actions/runs/39006725612)
  仍失败，输出 `candidate_changed`、`fixed_image_claimed=false`；本轮没有证明 Cloud 镜像
  已修复或可生产发布，也未操作其镜像/服务。该 owner 项不被源码矩阵的通过结果覆盖。
- 人工用户效果、配对收件箱/测试连接按钮、密钥轮换和异步任务边界仍是独立后续范围。
  本次没有新增这些功能，也没有改变 `core_proxy_execute=false`、`commit_execution=false`。
- 本轮文档/JSON 审查以其 PR 的实际结果为准；确定性门禁不等于已完成模型内容审查。

本阶段交付是候选、复验证据和待审批发布方案。任何正式发布均等待操作者确认。
