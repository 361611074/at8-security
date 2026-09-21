# CHANGELOG · at8-security 全家桶

记录 free / Pro / 授权服务器三个产品的所有版本变更。
详细英文条目以各包内 `readme.txt` 为准。

---

## 2026-09-21 · free 2.0.2 / pro 2.0.2 / license-server 1.0.1 —— 中文语言包

用户反馈：后台界面显示英文，希望跟随 WordPress「设置 → 常规 → 站点语言」自动切换。

根因：三个插件的界面文案都是英文原文，`languages/` 目录为空，且主文件从未调用 `load_plugin_textdomain()`——翻译文件即使存在也不会加载。

修复内容：

1. **补上文本域加载**：free / pro / license-server 主文件各加一处 `load_plugin_textdomain()`，挂 `init`（WP 6.7 起挂 `plugins_loaded` 会触发 doing_it_wrong）。
2. **内置简体中文翻译**：
   - free `at8-security-zh_CN.po/.mo`，157 条
   - pro `at8-security-pro-zh_CN.po/.mo`，176 条
   - license-server `at8-license-server-zh_CN.po/.mo`，101 条
   - 覆盖设置页、通知、登录表单提示、邮件标题、REST 错误等全部用户可见文案。
3. **顺带修复（free）**：请求防火墙新增 CLI 豁免——WP-CLI 及裸 PHP 脚本加载 wp-load（如自定义 cron 任务）此前会被误拦（无 REMOTE_ADDR 触发 deny），现在直接跳过；对真实 Web 请求的拦截不变（XSS 探针实测仍 403）。

验证：测试站（zh_CN）：`wp eval` 三个文本域均返回中文；后台设置页实测渲染中文（保存更改/登录保护/自定义登录路径/基础加固）；CLI wp-load 通过；WAF 探针 403；首页 200。

生效规则（WordPress 原生机制，无需任何配置）：

- 站点语言为简体中文 → 后台显示中文；
- 站点语言为英文 → 显示英文；
- 用户在「资料 → 语言」单独选择时，优先按用户个人设置。

验证：测试站（zh_CN）登录后台，设置页、通知、登录页提示均为中文；en_US 环境回退英文。

## 2026-09-21 · free 2.0.1 / pro 2.0.1 / license-server 1.0.0

### free（at8-security）2.0.1 —— 自定义后台登录地址修复

用户反馈：开启「自定义后台登录地址」后，打开自定义地址显示 404。

排查结论（真实用户流程复现 + 服务器证据）：

- 保存表单本身正常；404 的根因是**路径匹配过于严格 + 无反馈**：
  - 保存时会把输入规范化（大写转小写、剔除字母数字连字符以外的字符，如点号、空格、斜杠、中文），但页面从不显示最终生效的地址；
  - 访问时按原始大小写逐字比对——填 `Admin-Login` 存成 `admin-login`，再按原样访问就 404；
  - 输入被清空或撞上保留词（admin / login 等）时功能被静默关闭，唯一提示是 60 秒的后台 transient，极易错过。

修复内容：

1. **宽容匹配**：入口按「URL 解码 + 忽略大小写」与保存的路径比对，任何大小写和编码形式都能打开。
2. **保存即确认**：保存后立刻提示实际生效的完整登录地址；输入被调整时（如 `my.login` → `mylogin`）明确告知新地址；输入无效（中文/保留词/与现有文章冲突）时说明原因并保持原状。
3. **常驻地址显示**：开关打开时，设置页直接显示当前登录地址，随时可查、可收藏。

验证矩阵（全部通过）：干净路径 / 大小写不一致 / 点号被剔除 / 中文被拒 / 保留词 admin 被拒，匿名访问均返回登录表单，`wp-login.php` 按设计返回 404。

### pro（at8-security-pro）2.0.1

- 同步 free 修复后的 hook 兼容；无功能变更。

### license-server（at8-license-server）1.0.0

- 无变更。

---

## 2026-09-20 · 2.0.0 架构重写

- 按 WordPress.org Guideline 5/8 拆分为 free（功能完整、零锁定代码）+ Pro（独立插件、自建分发）+ 授权服务器三个产品。
- free 重写为模块化结构（`includes/class-*.php`），修复 1.0.x 的 4 个 P0（IP 伪造、验证码绕过、验证码拦死非表单登录、2FA 死代码自锁）与 7 个 P1。
- 通过官方 Plugin Check：free 0 ERROR / 0 WARNING。
- Pro：2FA（TOTP）、Cloudflare Turnstile、完整性监控、CDN 感知 IP、账号策略、独立更新器。
- 授权服务器：HMAC-SHA256 签名 API、恒定时间比较、密钥随机文件名（nginx 防直连）、登录锁定。
- 上线前自查修复 3 个安全项：作者枚举拦截 hook 优先级、包目录随机文件名、他人资料页 TOTP 密钥泄露。

---

## 1.0.x（私有分发时期，已废弃）

- 单文件插件 `at8-security.php`（2215 行），1.0.4 为最终版。
- 已被 2.0.0 全面取代，不再维护。
