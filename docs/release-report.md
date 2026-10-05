# AT8 Security WordPress.org Release Report

```text
Version:   2.0.3
Plugin:    at8-security
Date:      2026-09-25
Status:    NOT READY
```

**未通过项**：§69 上架素材中的 icon 与 banner 尚未制作（品牌决策，需人工确认方案）。
截图已完成，见 `wordpress-org-assets/`。代码、许可、安全、边界、readme、ZIP 全部 PASS。
详见 `docs/wordpress-org-compliance-report.md` 第四节。

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
Assets:             PARTIAL (截图已制作；icon / banner 未制作)
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

- `license-server` 数据库调用 **30/30 参数化**（12 处 `prepare()` + 18 处 `insert/update/delete` API），未参数化 0；
- 免费版出站请求 **0**；
- 权限拒绝返回 **403**（不是 500）；
- 授权密钥库内**不存明文**（HMAC-SHA256 + `wp_salt('auth')`），后台只显示掩码，完整密钥仅创建时展示一次；
- `!important` 0、`eval`/`create_function`/`extract` 0、秘密模式 0。

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
NOT READY FOR WORDPRESS.ORG SUBMISSION
```

补齐 §69 素材后重跑本报告即可转 READY。

---

## 交付物

### 分发包

| 文件 | 版本 | 条目 | 字节 | MD5 | SHA256 |
|---|---|---|---|---|---|
| `at8-security-2.0.3.zip` | 2.0.3 | 29 | 72,266 | `cdbde9bb055244057a277093fcc707a7` | `d1b5c5eb98d00a23124448f3688ea77637237a85432f53d09cfb192a345117ea` |
| `at8-security-pro-2.0.3.zip` | 2.0.3 | 31 | 89,945 | `2e1cc32a311195ee8f1e015e7331a72a` | `93382b6a9ff86819ad46835325538ba0babe1892d04dffa3dd4adde07a56dac8` |
| `at8-license-server-1.2.0.zip` | 1.2.0 | 18 | 54,013 | `788c9fd90ee3c95886db65acf887b259` | `85437766fce0f5b8a3dd79667d5a3d1fc69efa01c940326ef1d3117c062458ed` |

（2026-10-04 重建：免费版补齐 WAF 输入净化，Pro 修掉激活致命错误、SDK 输入净化与
扫描器处置；条目数因此由 23 增至 31——Pro 的 `sdk/` 8 个 PHP 文件已计入。
license-server 本轮未改动，哈希与上轮一致。）

构建报告：`dist/BUILD-REPORT.json`（三个包 `passed: true`，0 problem）。

### 文档

| 文件 | 内容 |
|---|---|
| `docs/architecture.md` | 三层架构与端到端链路 |
| `docs/free-pro-boundary.md` | Free/Pro 边界契约 |
| `docs/free-pro-boundary-report.md` | 边界验证报告 |
| `docs/free-extension-api.md` | 19 个钩子的完整契约 |
| `docs/license-api.md` | 授权生命周期与数据流 |
| `docs/api.md` | REST 报文、状态码、限流 |
| `docs/security.md` | 安全做法清单 |
| `docs/security-audit-report.md` | 安全审计报告 |
| `docs/privacy.md` | 隐私与外部服务声明 |
| `docs/external-service-report.md` | 外部服务清单与审计 |
| `docs/database.md` | 表结构与迁移规则 |
| `docs/release.md` | 发布流程与 Checklist |
| `docs/testing.md` | 测试脚本与覆盖清单 |
| `docs/wordpress-org.md` | 上架流程 |
| `docs/wordpress-org-compliance-report.md` | 上架合规报告 |
| `docs/third-party-license-report.md` | 第三方依赖审计报告 |
| `docs/release-report.md` | 本文件 |
| `THIRD-PARTY-LICENSES.md` | 第三方依赖登记表 |

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
| 1 | ~~制作 `screenshot-1..4.png`~~ | **已完成**，见 `wordpress-org-assets/` 与本轮补记 |
| 2 | 制作 `icon-128x128.png` / `icon-256x256.png` | **品牌素材**，按规范 §92 需人工确定，不由 Agent 擅自设计 |
| 3 | 制作 `banner-772x250.png` / `banner-1544x500.png` | 同上 |
| 4 | 素材放入 SVN `/assets` | **不进插件 zip**，WordPress.org 从 SVN 读取 |

### 本轮补上的非阻塞项

`== Upgrade Notice ==` 已补齐：免费版原本只有 2.0.0 / 2.0.1，现补 2.0.2 / 2.0.3；
Pro 原本完全没有该段，一并补上。因为改动的是 readme，免费版与 Pro 的 zip 内容变了，
上表的 MD5 / SHA256 已按重建结果更新（license-server 未改动，哈希不变）。

免费版 Plugin Check 复测：**0 ERROR / 0 CRITICAL / 1 WARNING**，与本轮之前一致。

### 2026-10-04 修复

| 问题 | 严重度 | 修复 |
|---|---|---|
| Pro 激活即 fatal：`Class "At8\Security\Pro\AT8_License_SDK" not found` | **致命** | `sdk/` 下的类是**全局**类（不声明 namespace），而 `includes/class-license.php`、`includes/class-updater.php` 在 `namespace At8\Security\Pro` 内，未加前导反斜杠时被解析成 `At8\Security\Pro\AT8_License_SDK`。4 处调用点补 `\`，与同目录引用 `\WP_Error` 的写法一致 |
| `at8sec_pro_api_base` 过滤器失效 | **高** | 该钩子被 `docs/license-api.md`、`docs/external-service-report.md` 写成公开契约（测试站/镜像靠它换授权服务器地址），但新 SDK 内部一个 `apply_filters` 都不挂 → 钩子空挂，客户端永远打 `www.at8.fun`。改由调用方套上：`server_url => untrailingslashit( apply_filters( 'at8sec_pro_api_base', 'https://www.at8.fun' ) )`；无回调时行为不变 |
| 免费版 `class-waf.php:113` 读裸 `$_SERVER['REQUEST_URI']` | 低（仅子串匹配） | 改为 `sanitize_text_field( wp_unslash( … ) )` |
| Pro SDK 授权码输入未净化 | 低 | `sdk/includes/class-adminpage.php:74` 补 `sanitize_text_field()` |
| SDK 扫描器提示 1 ERROR / 5 WARNING | 无（误报/死代码） | 逐条加 `phpcs:ignore` + 理由；`sdk/` 目录现零发现 |

#### SDK 与授权服务器的三处契约断裂（都导致 Pro 永远激活不了）

SDK 不是照本文档的 API 写的，而是照另一套（不存在的）接口写的。三处错位叠加，
任何一次激活都会失败；`git log -S "/license/activate"` 证实**服务器端从未有过 `/license/` 路由**：

| # | SDK 原来做的 | 服务器 / `docs/api.md` 实际约定 | 症状 |
|---|---|---|---|
| 1 | `POST /license/activate`、`/license/deactivate`、`/license/verify`；更新器调 `/update/check` | `POST /activate`、`/validate`、`/deactivate`、`/update`；且**没有** `verify` 路由 | HTTP 404 |
| 2 | 密钥只放 `X-AT8-Key` 签名头 | 服务器**完全不读** `X-AT8-*`，密钥走 body 的 `license`（`/activate`、`/validate`、`/update` 必填） | HTTP 400 `rest_missing_callback_param` |
| 3 | 以 `$res['data']['success']` 判成功 | 服务器返回 `valid` + `status`，**从不返回** `success` | HTTP 200 也被当成失败 |
| 4 | 读 `$d['lifetime']` / `license_type` 判断套餐 | 服务器只给 `plan` => `lifetime` \| `yearly` | 终身授权一律显示成订阅 |
| 5 | 更新检查要求 `$res['data']['update_available']` | 服务器不返回该字段（只有 `valid` + `version`） | 有新版本也永远不被提供 |

修复：路径改对（`verify` → `/validate`）；`AT8SDK_Client::post()` 在 `$key` 非空时把
`license` 并入 body；四处成功判定由 `success` 改 `valid`；`lifetime` /
`license_type` 回落到 `plan`；更新检查去掉 `update_available`（`version` 非空本身
已足以判断，代码后面也确实这样校验）。

验证（`at8ls-tests/probe-activate.php`，在 `http_response` 上抓原始报文）：
`HTTP 200 {"valid":true,"status":"active","plan":"lifetime"}` → `ACTIVATE_RESULT: OK`。

> ⚠️ **另三个产品有同样的病**：测试站上的 `at8-article-pro`、`at8-site-accelerator-pro`、
> `at8-smtp-pro` 各自内嵌了同一份 SDK 副本，同样是 `/license/activate` + 只放头里 +
> 判 `success`。它们不在本仓库，需要各自同步修复。
>
> ⚠️ **类名冲突**：四份 SDK 都用 `class AT8_License_SDK` 配 `class_exists` 守卫，
> 同站并存时**谁先加载谁生效**——一个产品会用到另一个产品的 SDK 副本。因此本轮验证时
> 临时停用了那三个插件，跑完已还原。根治需要给 SDK 的类名加产品前缀。

验证：测试站停用→激活 Pro，`debug.log` **为空**（无 fatal）；63 个 PHP 文件在 PHP 8.3.33
下全部解析通过；三个 zip §65 审计 0 problem。

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
