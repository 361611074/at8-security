# AT8 Security WordPress.org Release Report

```text
Version:   2.0.3
Plugin:    at8-security
Date:      2026-09-25
Assets:    2026-10-11
Status:    READY
```

**结论**：§69 上架素材（icon / banner / screenshot）已全部就位，代码、许可、安全、边界、
readme、ZIP 全部 PASS。详见 `docs/wordpress-org-compliance-report.md` 第四节。

> **范围说明（2026-10-11）**：本报告最初覆盖 free / Pro / 授权服务器三个产品，因为当时它们
> 同在一个仓库。三者已拆分为独立仓库（`at8-security-pro`、`at8-license-server-v2`），
> 本仓库只保留免费版；报告中关于 Pro 与授权服务器的记述作为**历史记录**保留，
> 其代码与后续变更以各自仓库为准。

---

## WordPress.org

```text
GPL:                PASS
Plugin Check:       PASS   (0 ERROR / 0 CRITICAL；1 条 WARNING，已逐条判断并记录)
Trialware:          PASS
External Services:  PASS
Tracking:           PASS
Remote Code:        PASS
Readme:             PASS
Assets:             PASS   (icon 128/256、banner 772x250/1544x500、screenshot 1-4)
ZIP:                PASS
```

### Plugin Check 的 1 条 WARNING

| 项目 | 内容 |
|---|---|
| 检查项 | `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` |
| 位置 | `at8-security.php:103` |
| 处置 | 保留调用，接受该 WARNING（产品决策，2026-09-25） |

实测证据：在测试站运行内核上读取 `WP_Textdomain_Registry`，`get_paths_for_domain()` 只搜索
`wp-content/languages/plugins`、`wp-content/languages/themes` 和该域**自己注册过的**路径。
插件自己的 `/languages` 之所以被搜索，正是因为 `load_plugin_textdomain()` 注册了它；
该站也没有 zh_CN 语言包。因此移除该调用会让免费版界面退回英文。
该警告是建议性的，WordPress.org 不会因此拒稿。判断依据同时写进了代码注释。

---

## Security

```text
XSS:                    PASS
CSRF:                   PASS
SQL Injection:          PASS
Privilege Escalation:   PASS
REST API:               PASS
AJAX:                   PASS
File Operations:        PASS
Authentication:         PASS
Secrets:                PASS
```

证据见 `docs/security-audit-report.md`。要点：

- 免费版数据库调用全部参数化，未参数化 0；
- 免费版出站请求 **0**；
- 权限拒绝返回 **403**（不是 500）；
- `!important` 0、`eval`/`create_function`/`extract` 0、秘密模式 0。

> 授权服务器侧的密钥存储与 DB 调用记录已随仓库拆分移至 `at8-license-server-v2`。

---

## Architecture

```text
Free / Pro separation:  PASS
Free independent:       PASS
Pro independent:        PASS
License Server:         PASS
```

- 免费版 zip 对 6 条隔离规则扫描 **0 命中**；
- 免费版不探测 Pro 是否存在（无 `class_exists( 'At8\Security\Pro\...' )`、无 `defined( 'AT8SEC_PRO_*' )`）；
- `is_pro()` 在免费版只有 2 处使用，**都在后台文案层**，不构成功能开关；
- 唯一连接点是 19 个公开钩子（`docs/free-extension-api.md`）。

---

## Final

```text
READY FOR WORDPRESS.ORG SUBMISSION
```

素材已补齐（2026-10-11）。剩下只有提交动作：把 `wordpress-org-assets/` 的 8 个文件
放进 SVN 的 `/assets` 目录（该目录不进插件 zip）。

---

## 交付物

### 分发包

| 文件 | 版本 | 条目 | 字节 | MD5 | SHA256 |
|---|---|---|---|---|---|
| `at8-security-2.0.3.zip` | 2.0.3 | 29 | 72,266 | `cdbde9bb055244057a277093fcc707a7` | `d1b5c5eb98d00a23124448f3688ea77637237a85432f53d09cfb192a345117ea` |

（2026-10-04 重建：免费版补齐 WAF 输入净化。2026-10-11 仓库拆分后重新构建，
哈希与拆分前**逐字节一致**，证明拆分未触碰免费版代码。）

构建报告：`dist/BUILD-REPORT.json`（`passed: true`，0 problem）。

> Pro 与 license-server 的分发包由各自仓库构建，本仓库不再产出。

### 文档

| 文件 | 内容 |
|---|---|
| `docs/free-pro-boundary.md` | Free/Pro 边界契约 |
| `docs/free-pro-boundary-report.md` | 边界验证报告 |
| `docs/free-extension-api.md` | 19 个钩子的完整契约 |
| `docs/security.md` | 安全做法清单 |
| `docs/security-audit-report.md` | 安全审计报告 |
| `docs/privacy.md` | 隐私与外部服务声明 |
| `docs/external-service-report.md` | 外部服务清单与审计 |
| `docs/release.md` | 发布流程与 Checklist |
| `docs/testing.md` | 测试脚本与覆盖清单 |
| `docs/wordpress-org.md` | 上架流程 |
| `docs/wordpress-org-compliance-report.md` | 上架合规报告 |
| `docs/third-party-license-report.md` | 第三方依赖审计报告 |
| `docs/release-report.md` | 本文件 |
| `THIRD-PARTY-LICENSES.md` | 第三方依赖登记表 |

> 原先还列有 `architecture.md` / `api.md` / `database.md` / `license-api.md`——
> 那四份是授权服务器的实现文档，已随仓库拆分移至 `at8-license-server-v2`。

---

## 测试记录（2026-09-25）

| 套件 | 结果 | 覆盖面 |
|---|---|---|
| `at8ls-deploy.py` | **38/38** | 授权链路：签发 / 激活 / 校验 / 解绑 / 席位 / 过期 / 吊销 / 停用 / 宽限期 / 限流 |
| `at8ls-spec.py` | **97/97** | 规范符合性：带数据迁移 / 审计 / sha256 / token 期限 / 双命名空间 / 卸载策略 / 语言包 |
| `at8ls-publish.py` | **73/73** | 发布链路：上传 / 状态 / 签名下载 / token 过期 / 哈希校验 / 撤回 / 更新下发 |
| `at8sec-attack.py` | **30/30** | 对抗：越权 / 缺 nonce / 注入 / 超大 key / 无 key / 限流 / 密钥存储 |
| `at8sec-lint-zips.py` | **63 文件全通过** | 用目标解释器 PHP 8.3.33 对发行 zip 逐文件 lint |
| `at8sec-build-zips.py` | **3/3 PASS** | §65 六项 zip 审计：结构 / 禁用文件 / 禁用字符串 / 密钥 / 许可 / 语法 |
| `at8sec-plugin-check.py` | 见上 | 官方 Plugin Check（装在测试站对发行 zip 跑） |

### 环境

| 项目 | 值 |
|---|---|
| 测试站 | `wordpress.xmm.fan` |
| WordPress | 7.1.2 |
| PHP | 8.3.33 |
| 站点语言 | zh_CN |
| 本地 PHP | 7.3.4（低于插件下限 7.4，故语法检查一律在服务器执行） |

### 本轮修复的一处测试脆弱点

首次串联运行时 `at8ls-publish.py` 出现 51/55：一次 `Api::update()` 瞬时失败触发了
`Updater` 的**一小时退避**（`FAILURE_SECONDS = 3600`），随后的更新检查读到退避标志，
表现为「服务端没有提供任何版本」，进而拿不到签名 URL，下载相关检查提前中止。

**这是客户端按设计退避，不是产品缺陷**（重跑 72/72 通过）。但测试套件原本只在**套件开始时**
清一次缓存，于是单次抖动会级联成 4 项失败 + 约 17 项未执行。

已加固：在测量更新提供**之前**清一次缓存，并新增一条断言
`the client starts from a clean cache, with no back-off flag set`。
这样同样的情形会直接指出「退避标志未清」，而不是显示为莫名其妙的空提供。套件因此由 72 项变为 73 项。

### 测试站残留核查（跑完全部套件之后）

| 项目 | 结果 |
|---|---|
| 测试密钥 | **0** |
| 站点绑定 | **0** |
| 限流计数 transient | **0** |
| 一次性密钥 transient | **0** |
| 已发布版本 | **0** |
| 磁盘上的安装包 zip | **0** |
| `debug.log` | **不存在** |

**一次性密钥 transient 为 0** 是这里最值得看的一条：它证明签发后那份明文密钥确实被读取即删，
没有以任何形式留在选项表里。

**已知的正常残留**：跑 `at8sec-plugin-check.py` 时，激活 Pro 会触发一次授权 API 调用、
加载页面会触发免费版的限流与 404 计数，因此在**插件体检之后**会留下少量 10 分钟内自过期
的 transient。这是体检动作的副作用，不是测试套件没清干净；已提供
`at8ls-tests/tidy-site.php` 一键清掉（含 WordPress 自身的过期 transient 回收）。

---

## 待办（阻塞上架）

| # | 事项 | 说明 |
|---|---|---|
| 1 | ~~制作 `screenshot-1..4.png`~~ | **已完成** |
| 2 | ~~制作 `icon-128x128.png` / `icon-256x256.png`~~ | **已完成**（2026-10-11，作者提供 512×512 主图） |
| 3 | ~~制作 `banner-772x250.png` / `banner-1544x500.png`~~ | **已完成**（2026-10-11，作者提供源图） |
| 4 | 素材放入 SVN `/assets` | **不进插件 zip**，WordPress.org 从 SVN 读取 |

**素材已补齐，无剩余阻塞项。** 第 4 项是提交动作，不是待办内容。

### 本轮补上的非阻塞项

`== Upgrade Notice ==` 已补齐：免费版原本只有 2.0.0 / 2.0.1，现补 2.0.2 / 2.0.3。
因为改动的是 readme，免费版的 zip 内容变了，上表的 MD5 / SHA256 已按重建结果更新。

免费版 Plugin Check 复测：**0 ERROR / 0 CRITICAL / 1 WARNING**，与本轮之前一致。

### 2026-10-04 修复（免费版部分）

| 问题 | 严重度 | 修复 |
|---|---|---|
| 免费版 `class-waf.php:113` 读裸 `$_SERVER['REQUEST_URI']` | 低（仅子串匹配） | 改为 `sanitize_text_field( wp_unslash( … ) )` |

> **Pro 侧的修复已移出本报告。** 2026-10-04 那一轮同时修了 Pro 的激活致命错误
> （命名空间内未加前导反斜杠，`AT8_License_SDK` 被解析成 `At8\Security\Pro\AT8_License_SDK`）、
> `at8sec_pro_api_base` 钩子空挂、SDK 授权码未净化，以及 **SDK 与授权服务器的五处契约断裂**
> （路由名、密钥位置、成功标志、套餐字段、更新可用标志）。这些都属于 `at8-security-pro`，
> 完整记录见该仓库。免费版本轮只动了上面那一处。

验证：免费版 23 个 PHP 文件在 PHP 8.3.33 下全部解析通过；`at8-security` zip §65 审计 0 problem。

**打包注意**：发行必须用仓库根目录的 `at8sec-build-zips.py`（含 §65 六项审计，
`SKIP_FILES` 已排除 `AGENTS.md`）。工作缓冲区里那份简化副本会把 `AGENTS.md` 打进 zip，
Plugin Check 随即报 `unexpected_markdown_file`。

### 本轮补上的 §69 截图

`wordpress-org-assets/screenshot-1..4.png` 已制作（1280px 宽，英文界面），与 readme 的
`== Screenshots ==` 四条说明一一对应。截图期间把测试站语言临时切到 en_US，截完已切回
zh_CN 并验证（`at8sec-locale.py`）。

readme 第 1 条说明由 "Hardening and login settings." 改为 "Hardening settings."，
因为登录设置在本插件里是独立的 Login 标签页，截图 1 拍的是 Hardening 标签页。

**截图中的审计日志为样本数据**（`at8ls-tests/seed-audit.php` 生成，地址取自 RFC 5737
文档网段），不是真实事件记录。理由与做法见
`docs/wordpress-org-compliance-report.md` 第四节。
