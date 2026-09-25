# WordPress.org 合规报告

规范 §47 / §93 / §98 / §99 / §101 第 6 项（WordPress.org 风险清单）。

**审计对象**：`at8-security` 2.0.3（上架包）
**审计日期**：2026-09-25
**测试站**：`wordpress.xmm.fan`，WordPress 7.1.2 / PHP 8.3.33 / zh_CN

---

## 结论

```text
FREE_WORDPRESS_ORG_READY = NO

原因：§69 上架素材中，icon 与 banner 尚未制作（品牌决策，需人工确认方案）。
      截图已完成；代码、许可、安全、边界、readme、ZIP 全部 PASS。
```

素材是可补的独立工作项，不影响代码质量；在补齐前不满足 §98 的 `Assets` 条件，
因此**不得提交**。

---

## 一、代码与许可

| 项 | 结果 | 依据 |
|---|---|---|
| GPL 声明 | **PASS** | 插件头 `License: GPLv2 or later` + `License URI`；readme 同值 |
| 无 Pro 代码 | **PASS** | 构建脚本扫描 6 条规则，0 命中 |
| 无 Trialware | **PASS** | 免费功能不以 `is_pro()` 为开关，逐处核对 |
| 无功能锁定 | **PASS** | 免费版功能不依赖授权状态 |
| 无隐藏后门 | **PASS** | 只有 19 个公开钩子 |
| 无远程可执行代码 | **PASS** | 无远程加载的 JS/PHP，无 `eval` / `create_function` / `extract` |
| 无未授权追踪 | **PASS** | 0 出站请求 |
| 外部服务已声明 | **PASS** | 免费版无外部服务，readme 已写明 |
| 第三方依赖 | **PASS** | 0 个第三方依赖（见 `THIRD-PARTY-LICENSES.md`） |
| 秘密扫描 | **PASS** | 构建脚本 `check_secrets` 三个包均 0 命中 |

---

## 二、WordPress Plugin Check

在测试站对**发行 zip**（不是源码目录）实测：

```text
== at8-security-2.0.3.zip ==
   ERROR     0
   WARNING   1
   CRITICAL  0
   codes: PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
```

**判定：PASS（1 条 WARNING，已逐条判断，未忽略）**

规范 §47 要求「逐条判断，不能简单忽略」，以下为判断记录。

### 唯一 WARNING 的处置

| 项目 | 内容 |
|---|---|
| 检查项 | `DiscouragedFunctions.load_plugin_textdomainFound` |
| 位置 | `at8-security.php:83` |
| 官方说明 | 「插件托管在 WordPress.org 时不再需要手动调用，WordPress 会自动加载翻译」 |
| 处置 | **保留调用，接受该 WARNING**（产品决策，2026-09-25） |

**为什么保留** —— 实测证据（在测试站的运行内核上读取）：

1. `_load_textdomain_just_in_time()` 先问 `WP_Textdomain_Registry::has()`；
2. `has()` 对**未注册**的域返回 `true`（`empty( $this->all[ $domain ] )`），所以流程会继续；
3. `get()` 退到 `get_path_from_lang_dir()`，而它只搜索 `get_paths_for_domain()` 给的位置：

```php
$locations = array( WP_LANG_DIR . '/plugins', WP_LANG_DIR . '/themes' );
if ( isset( $this->custom_paths[ $domain ] ) ) { $locations[] = $this->custom_paths[ $domain ]; }
```

4. 实测该站解析结果：

```text
at8-security paths: .../wp-content/languages/plugins, .../wp-content/languages/themes,
                    .../wp-content/plugins/at8-security/languages
```

**插件自己的 `/languages` 之所以在列表里，只是因为 `load_plugin_textdomain()` 把它注册进了
`custom_paths`。** 该站也没有 zh_CN 语言包（`MO_GLOBAL: no`）。

因此移除该调用**不是**消掉一条警告，而是让免费版界面在「语言包尚未生成」的站点上退回英文 ——
而这正是 2026-09-21 那次修复要解决的问题（用户反馈后台显示英文）。

**该警告是建议性的**，WordPress.org 不会因此拒稿。判断依据同时写进了代码注释
（`at8-security.php` 该调用上方），避免后来者「顺手删掉」。

> 备选方案（未采用，已评估）：改用 WP 6.6+ 的 `lang_dir_for_domain` 过滤器可做到 0 WARNING，
> 但需把 `Requires at least` 从 6.4 提到 6.6；或免费版改为只依赖 translate.wordpress.org 语言包，
> 但语言包审核通过前 zh_CN 用户会看到英文。

### Pro / license-server 的 Plugin Check

```text
== at8-security-pro-2.0.3.zip ==
   ERROR 4  WARNING 3  CRITICAL 0
   codes: load_plugin_textdomainFound, OffloadedContent,
          plugin_updater_detected, update_modification_detected
```

**两者都不提交 wp.org**，因此这些不是上架风险：

| 检查项 | 为何存在 | 是否问题 |
|---|---|---|
| `plugin_updater_detected` | Pro 走自建授权服务器更新（Guideline 8 只约束**目录内**插件） | 否，设计如此 |
| `update_modification_detected` | 同上 | 否 |
| `OffloadedContent` | Turnstile 是托管校验控件，且可关闭、已在 readme 声明 | 否 |
| `load_plugin_textdomainFound` | 自建分发，永远不会有语言包，内置翻译是唯一翻译 | 否 |

---

## 三、Readme

| 项 | 要求 | 实测 | 结果 |
|---|---|---|---|
| Contributors | wp.org 账号 | `x361611074` | PASS |
| Tags | ≤ 5 个、全小写 | `security, firewall, login, captcha, spam`（5 个，全小写） | PASS |
| Requires at least | — | `6.4` | PASS |
| Tested up to | — | `7.1` | PASS |
| Requires PHP | ≥ 7.0 且与插件头同值 | `7.4`（插件头 `Requires PHP: 7.4`） | PASS |
| Stable tag | 与版本一致 | `2.0.3` | PASS |
| License / License URI | GPLv2+ | `GPLv2 or later` + GNU URL | PASS |
| 段落完整 | Description / Installation / FAQ / Changelog | 全部存在 | PASS |
| Privacy 段 | — | 存在，声明不外连 | PASS |
| External Services 段 | 有出站才需要 | 免费版无出站，仍写明「None」 | PASS |
| Changelog 对应本版 | — | `= 2.0.3 =` 存在 | PASS |
| `Plugin URI` ≠ `Author URI` | 校验器禁止同值 | 插件头未设 `Plugin URI` | PASS |
| 插件名纯 ASCII | slug 由它生成，永久不可改 | `At8 Security` | PASS |

**Readme 与素材的一致性**：readme 的 `== Screenshots ==` 声明了 4 张截图，
但对应图片尚未制作 —— 见下节，属于未完成项。

---

## 四、上架素材（§69）—— 部分完成

| 素材 | 要求 | 现状 |
|---|---|---|
| `icon-128x128.png` / `icon-256x256.png` | 插件图标 | **未制作**（品牌决策） |
| `banner-772x250.png` / `banner-1544x500.png` | 插件横幅 | **未制作**（品牌决策） |
| `screenshot-1..4.png` | 与 readme 的 Screenshots 顺序对应 | **已制作**（`wordpress-org-assets/`） |

### 截图 —— 已完成

在测试站上实拍，英文界面（截图期间临时把站点语言切到 en_US，截完已切回 zh_CN），
宽 1280px，与 readme 的 4 条说明一一对应：

| 文件 | 对应标签页 | 尺寸 |
|---|---|---|
| `screenshot-1.png` | Hardening | 1280 × 1021 |
| `screenshot-2.png` | Firewall | 1280 × 1663 |
| `screenshot-3.png` | Headers & CSP | 1280 × 960 |
| `screenshot-4.png` | Tools | 1280 × 1318 |

readme 的 `== Screenshots ==` 已同步为：

1. Hardening settings.
2. Firewall rules and rate limiting.
3. Security headers and Content Security Policy.
4. Tools: weak-password check and the audit log.

第 1 条由 "Hardening and login settings." 改为 "Hardening settings."：登录设置在本插件里是
**独立的 Login 标签页**，而截图 1 拍的是 Hardening 标签页，原措辞与图不符。

**截图里的审计日志是样本数据。** 测试站的日志是在 zh_CN 期间累积的，Detail 列含中文句子，
且长达 40 行（会把截图拉到 2700px 以上）。截图前用 `at8ls-tests/seed-audit.php`
重置为一组英文样本行，地址取自 RFC 5737 文档网段。**样本行不是真实事件记录。**

**判定：截图 PASS；icon / banner FAIL（未制作）。**

**注意**：icon 与 banner 属于**品牌素材**，按规范 §92 不得由 Agent 擅自设计。

---

## 五、ZIP 包

| 项 | 要求 | 实测 | 结果 |
|---|---|---|---|
| 顶层单目录 | 第一段路径必须是插件 slug | `at8-security/...` | PASS |
| 条目用正斜杠 | 反斜杠在 Linux 解压出畸形文件名 | 正斜杠 | PASS |
| 无 `.backups` / `dist` / `tests` | — | 无 | PASS |
| 无开发文件 | `AGENTS.md` / `.gitignore` 等 | 无 | PASS |
| 体积 | ≤ 10MB | 71,861 字节（29 文件） | PASS |
| PHP 语法 | 目标 PHP 能解析 | 服务器 PHP 8.3.33，55 个文件全部通过 | PASS |
| 文件名与内容一致 | 版本从插件头读取 | `at8-security-2.0.3.zip` ↔ `Version: 2.0.3` | PASS |

---

## 六、WordPress.org 风险清单（§101 第 6 项）

按规范 §93 的风险等级排列。

| 风险 | 等级 | 判定 | 依据 |
|---|---|---|---|
| Free/Pro 代码边界 | ★★★★★ | **PASS** | 6 条规则扫描 0 命中；免费版不探测 Pro |
| Trialware / Feature Lock | ★★★★★ | **PASS** | 免费功能不以 `is_pro()` 为开关 |
| 外部 API | ★★★★★ | **PASS** | 免费版 0 出站 |
| 自动数据发送 | ★★★★★ | **PASS** | 无遥测、无统计、无账号 |
| 远程可执行代码 | ★★★★ | **PASS** | 无远程 JS/PHP；无 `eval` 类函数 |
| 未声明外部服务 | ★★★★ | **PASS** | readme + privacy.md 一致 |
| readme 规范性 | ★★★ | **PASS** | 见第三节 |
| 上架素材 | ★★★ | **部分完成** | 截图已制作；icon / banner 待定（见第四节） |
| ZIP 结构 | ★★★ | **PASS** | 见第五节 |
| 数据库迁移破坏数据 | ★★ | **PASS** | 只 ADD COLUMN，不 DROP；带数据实测 |
| 卸载残留 / 误删 | ★★ | **PASS** | 卸载清本站数据；license-server 默认保留商业记录 |
| 多站点 | ★★ | **PASS** | 逐站建表、逐站清理 |

---

## 七、其余验收项（§98）

| 项 | 结果 | 依据 |
|---|---|---|
| Security | PASS | `docs/security-audit-report.md` |
| Free/Pro separation | PASS | `docs/free-pro-boundary-report.md` |
| PHP compatibility | PASS | 服务器 PHP 8.3.33 全量 lint 通过（55 文件） |
| WordPress compatibility | PASS | `Requires at least: 6.4` / `Tested up to: 7.1`，测试站 7.1.2 实测 |
| Uninstall | PASS | 卸载删本站设置、审计表、cron、`.htaccess` 块 |
| Multisite | PASS | 逐站建表、逐站清理 |
| Secrets scan | PASS | 构建脚本 0 命中 |

---

## 八、待办

| # | 事项 | 阻塞上架 | 责任 |
|---|---|---|---|
| 1 | 制作 `screenshot-1..4.png`（对应 readme 的 4 条说明） | ~~是~~ | **已完成**（见第四节） |
| 2 | 制作 `icon-128x128.png` / `icon-256x256.png` | **是** | 品牌决策，需人工确认方案 |
| 3 | 制作 `banner-772x250.png` / `banner-1544x500.png` | **是** | 品牌决策，需人工确认方案 |
| 4 | 素材放入 SVN `/assets` 目录（**不进插件 zip**） | 是 | 提交时 |
| 5 | readme 的 `== Upgrade Notice ==` 补 2.0.2 / 2.0.3 条目 | 否 | **已完成**（免费版补 2.0.2/2.0.3；Pro 一并补上该段） |

补齐 2–4 后重跑本报告，`FREE_WORDPRESS_ORG_READY` 方可置 `YES`。
