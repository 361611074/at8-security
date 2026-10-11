# CHANGELOG · at8-security（免费版）

记录**免费版 `at8-security`** 的版本变更。详细英文条目以 `free/at8-security/readme.txt` 为准。

> **范围**：本仓库只承载免费版。商业附加组件与授权服务器的变更记录在各自仓库——
> `at8-security-pro`、`at8-license-server-v2`——本文件不再记录它们。
> 2026-10-11 之前的历史条目里出现的 Pro / 授权服务器内容，仅作为当时的仓库状态留档。

---

## 2026-10-11 · 仓库拆分：Pro 与授权服务器移出本仓库

此前本仓库是一个 monorepo，免费版、Pro 附加组件、授权服务器三套代码混在同一棵历史里：
容易误改，也容易把商业代码带进要上架 wp.org 的免费版。

现在本仓库**只保留免费版**：

| 动作 | 内容 |
|---|---|
| 移除 | `pro/at8-security-pro/`（32 文件）→ https://github.com/361611074/at8-security-pro |
| 移除 | `license-server/at8-license-server/`（20 文件）→ https://github.com/361611074/at8-license-server-v2 |
| 移除 | 授权服务器专属文档：`docs/api.md`、`docs/database.md`、`docs/license-api.md`、`docs/architecture.md` |
| 改造 | `at8sec-build-zips.py` 只构建免费版；`README.md`、`AGENTS.md` 改为免费版专用 |
| 新增 | `AGENTS.md` 第 23 条：不得把 Pro / 授权服务器的代码或配置放进本仓库 |

**免费版本身零改动**：代码、readme、语言包、上架素材均未变，构建产物与拆分前一致。

---

## 2026-09-25 · free 2.0.3 —— 上架交付物补齐

### 上架交付物

- 新增 `THIRD-PARTY-LICENSES.md`（登记表；当前 0 个第三方依赖）。
- 新增 `docs/free-pro-boundary.md`、`docs/free-extension-api.md`（19 个钩子逐一记录
  Purpose / Parameters / Return / Version）。
- 新增六份审计报告：`wordpress-org-compliance-report.md`、`security-audit-report.md`、
  `free-pro-boundary-report.md`、`third-party-license-report.md`、`external-service-report.md`、
  `release-report.md`。

### 构建

- 构建脚本移到仓库根 `at8sec-build-zips.py`，**路径由脚本自身位置推导**（旧版把作者机器的
  `E:\...` 写死）；版本从插件头读取；构建后对 zip 跑规范 §65 六项审计（结构 / 禁用文件 /
  禁用字符串 / 密钥 / 许可 / 语法），**审计失败即非零退出**。
- 禁用字符串规则写成精确模式，避免朴素的「搜 pro」命中免费版自己合法的升级面板。
- `AGENTS.md` 等开发文件不再进入发行包。

### 测试

- `at8ls-spec.py` 97/97、`at8sec-attack.py` 30/30；发行 zip 用目标 PHP 8.3.33 逐文件 lint 通过（55 文件）。
- 新增 `at8sec-lint-zips.py`、`at8sec-plugin-check.py`、`at8sec-push.py`。

### 仍未完成（阻塞上架）

WordPress.org 上架素材：`screenshot-1..4.png`、`icon-128x128/256x256.png`、
`banner-772x250/1544x500.png`。`FREE_WORDPRESS_ORG_READY = NO`，其余项全部 PASS。
见 `docs/release-report.md`。

> 同期发布的 `at8-license-server` 1.2.0（密钥改为 HMAC-SHA256 落库）变更记录见
> `at8-license-server-v2` 仓库。

---

## 2026-09-21 · free 2.0.2 —— 中文语言包

用户反馈：后台界面显示英文，希望跟随 WordPress「设置 → 常规 → 站点语言」自动切换。

根因：界面文案都是英文原文，`languages/` 目录为空，且主文件从未调用 `load_plugin_textdomain()`——翻译文件即使存在也不会加载。

修复内容：

1. **补上文本域加载**：主文件加一处 `load_plugin_textdomain()`，挂 `init`（WP 6.7 起挂 `plugins_loaded` 会触发 doing_it_wrong）。
2. **内置简体中文翻译**：`at8-security-zh_CN.po/.mo`，157 条，覆盖设置页、通知、登录表单提示、邮件标题、REST 错误等全部用户可见文案。
3. **顺带修复**：请求防火墙新增 CLI 豁免——WP-CLI 及裸 PHP 脚本加载 wp-load（如自定义 cron 任务）此前会被误拦（无 REMOTE_ADDR 触发 deny），现在直接跳过；对真实 Web 请求的拦截不变（XSS 探针实测仍 403）。

验证：测试站（zh_CN）：`wp eval` 文本域返回中文；后台设置页实测渲染中文（保存更改 / 登录保护 / 自定义登录路径 / 基础加固）；CLI wp-load 通过；WAF 探针 403；首页 200。

生效规则（WordPress 原生机制，无需任何配置）：

- 站点语言为简体中文 → 后台显示中文；
- 站点语言为英文 → 显示英文；
- 用户在「资料 → 语言」单独选择时，优先按用户个人设置。

> 同批次的 Pro 2.0.2 与 license-server 1.0.1 变更见各自仓库。

## 2026-09-21 · free 2.0.1 —— 自定义后台登录地址修复

用户反馈：开启「自定义后台登录地址」后，打开自定义地址显示 404。

排查结论（真实用户流程复现 + 服务器证据）：

- 保存表单本身正常；404 的根因是**路径匹配过于严格 + 无反馈**：
  - 保存时会把输入规范化（大写转小写、剔除字母数字连字符以外的字符，如点号、空格、斜杠、中文），但页面从不显示最终生效的地址；
  - 访问时按原始大小写逐字比对——填 `Admin-Login` 存成 `admin-login`，再按原样访问就 404；
  - 输入被清空或撞上保留词（admin / login 等）时功能被静默关闭，唯一提示是 60 秒的后台 transient，极易错过。

修复内容：

1. **宽容匹配**：入口按「URL 解码 + 忽略大小写」与保存的路径比对，任何大小写和编码形式都能打开。
2. **保存即确认**：保存后立刻提示实际生效的完整登录地址；输入被调整时（如 `my.login` → `mylogin`）明确告知新地址；输入无效（中文 / 保留词 / 与现有文章冲突）时说明原因并保持原状。
3. **常驻地址显示**：开关打开时，设置页直接显示当前登录地址，随时可查、可收藏。

验证矩阵（全部通过）：干净路径 / 大小写不一致 / 点号被剔除 / 中文被拒 / 保留词 admin 被拒，匿名访问均返回登录表单，`wp-login.php` 按设计返回 404。

> 同批次的 Pro 2.0.1（同步 hook 兼容，无功能变更）见 `at8-security-pro` 仓库。

---

## 2026-09-20 · 2.0.0 架构重写

- 按 WordPress.org Guideline 5/8 拆分产品线：free（功能完整、零锁定代码）+ Pro（独立插件、自建分发）+ 授权服务器。
- free 重写为模块化结构（`includes/class-*.php`），修复 1.0.x 的 4 个 P0（IP 伪造、验证码绕过、验证码拦死非表单登录、2FA 死代码自锁）与 7 个 P1。
- 通过官方 Plugin Check：free 0 ERROR / 0 WARNING。
- 上线前自查修复 3 个安全项：作者枚举拦截 hook 优先级、包目录随机文件名、他人资料页 TOTP 密钥泄露。

---

## 1.0.x（私有分发时期，已废弃）

- 单文件插件 `at8-security.php`（2215 行），1.0.4 为最终版。
- 已被 2.0.0 全面取代，不再维护。
