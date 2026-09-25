# CHANGELOG · at8-security 全家桶

记录 free / Pro / 授权服务器三个产品的所有版本变更。
详细英文条目以各包内 `readme.txt` 为准。

---

## 2026-09-25 · license-server 1.2.0 —— 密钥不再明文落库，并补齐上架交付物

### 授权服务器 1.2.0

**密钥存储改为 HMAC-SHA256（规范 §5.3 / §17）**

原来 `license_key` 与 `key_norm` 两列都存明文，数据库一旦泄露或备份外流，等于把全部客户的密钥交出去。

- `key_norm` 改为存 `hash_hmac( 'sha256', 归一化密钥, wp_salt('auth') )`，查找只走它；
- `license_key` 恒为空串（列保留，便于迁移与回滚）；
- 新增 `key_hint` 列存密钥末 5 位，**仅供后台展示**，不足以反推密钥；
- 完整密钥改为**签发时经用户级 transient 展示一次**（300 秒，读取即删），后台列表只显示掩码
  `AT8-SEC-•••••-•••••-•••••-9K3M2`。

`Db::DB_VERSION` 2 → 3。迁移 `migrate_keys()` 在 `install()` 之后运行，把历史上以明文存在的行
逐行哈希并清空原值：**幂等**（已清空的行跳过）、**只加列不 DROP**，并在持有真实授权的库上验过。

为什么用 HMAC 而不是 `password_hash()`：后者每次换盐，同一密钥两次哈希不同，**没法按密钥查库**；
授权校验必须先定位到那一行，所以需要「确定性 + 不可逆」。加盐是为了防彩虹表。

**顺带**：移除 `load_plugin_textdomain()` 挂 `init` 时的 `_doing_it_wrong` 风险路径未动——
该调用经实测确认**必需**（WordPress 不会自动加载插件自带的 `/languages` 目录），
Plugin Check 的对应 WARNING 按规范 §47 逐条判断后接受，理由写进了代码注释。

### 上架交付物

- 新增 `THIRD-PARTY-LICENSES.md`（登记表；当前 0 个第三方依赖）。
- 新增 `docs/free-pro-boundary.md`、`docs/free-extension-api.md`（19 个钩子逐一记录
  Purpose / Parameters / Return / Version）、`docs/license-api.md`。
- 新增六份审计报告：`wordpress-org-compliance-report.md`、`security-audit-report.md`、
  `free-pro-boundary-report.md`、`third-party-license-report.md`、`external-service-report.md`、
  `release-report.md`。

### 构建

- 构建脚本移到仓库根 `at8sec-build-zips.py`，**路径由脚本自身位置推导**（旧版把作者机器的
  `E:\...` 写死）；版本从各插件头读取；构建后对每个 zip 跑规范 §65 六项审计（结构 / 禁用文件 /
  禁用字符串 / 密钥 / 许可 / 语法），**审计失败即非零退出**。
- 禁用字符串规则写成精确模式，避免朴素的「搜 pro」命中免费版自己合法的升级面板。
- `AGENTS.md` 等开发文件不再进入发行包。

### 测试

- `at8ls-spec.py` 97/97、`at8ls-deploy.py` 38/38、`at8sec-attack.py` 30/30、
  `at8ls-publish.py` 73/73；发行 zip 用目标 PHP 8.3.33 逐文件 lint 通过（55 文件）。
- `at8ls-publish.py` 加固：Pro 客户端在更新检查失败后会退避一小时，单次抖动曾级联成
  4 项失败 + 约 17 项未执行。现在测量前先清缓存，并断言
  `the client starts from a clean cache, with no back-off flag set`（套件由 72 项增至 73 项）。
- 新增 `at8sec-lint-zips.py`、`at8sec-plugin-check.py`、`at8sec-push.py`、`at8ls-run.py`。

### 仍未完成（阻塞上架）

WordPress.org 上架素材：`screenshot-1..4.png`、`icon-128x128/256x256.png`、
`banner-772x250/1544x500.png` 尚未制作。`FREE_WORDPRESS_ORG_READY = NO`，
其余项全部 PASS。见 `docs/release-report.md`。

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
