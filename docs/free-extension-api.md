# 免费版扩展 API

`at8-security` 对外公开的钩子、函数、常量与数据结构。

**Pro 附加组件就是通过这里接进来的** —— 它和任何第三方附加组件走同一条路，没有内部后门，
也没有只有 Pro 才能调用的私有入口。这是规范 §102 的要求：免费版是完整可用的插件，不是 Pro 的演示版。

本文件列出的内容即契约。改名、改参数顺序、改返回值含义都算 Breaking Change，按 `docs/release.md`
的 SemVer 规则升 MAJOR。新增钩子不算 Breaking Change，但要补进本文件。

全部 19 个钩子自 **2.0.1** 起存在（2.0.1 是免费版进入仓库的第一个版本，
`git log -S "<hook 名>"` 可复核；`7385cd7` 与 `374894b` 之间没有任何钩子增删）。

---

## Filters

| Filter | 参数 | 返回 | 用途 |
|---|---|---|---|
| `at8sec_is_pro` | `bool` | `bool` | Pro 附加组件是否已激活并授权 |
| `at8sec_admin_tabs` | `array<string,string>` | 同上 | 后台设置页的标签页 |
| `at8sec_pro_url` | `string` | `string` | 「升级到 Pro」链接的目标地址 |
| `at8sec_captcha_method` | `string` | `string` | 选择人机校验方式 |
| `at8sec_captcha_rendered` | `string` | `string` | 声明校验控件已由附加组件渲染 |
| `at8sec_captcha_verify` | `\WP_User\|\WP_Error\|null`, `string` | 同第 1 个参数 | 让附加组件自行校验挑战 |
| `at8sec_weak_password_dictionary` | `string[]` | `string[]` | 弱口令字典 |
| `at8sec_public_rest_routes` | `string[]` | `string[]` | REST 白名单路由 |
| `at8sec_denied_upload_extensions` | `string[]` | `string[]` | 禁止上传的扩展名 |
| `at8sec_waf_patterns` | `array` | `array` | 防火墙规则集 |
| `at8sec_waf_xss_pattern` | `string` | `string` | XSS 检测正则 |
| `at8sec_bad_user_agents` | `string[]` | `string[]` | 扫描器 UA 特征 |
| `at8sec_trusted_proxies` | `string[]` | `string[]` | 可信反代 / CDN 地址 |
| `at8sec_client_ip_headers` | `string[]` | `string[]` | 取真实 IP 时按序尝试的 `$_SERVER` 键 |

### at8sec_is_pro

**用途**：判断 Pro 附加组件是否已激活且授权有效。免费版自身不实现任何 Pro 功能，因此在免费版内
这个过滤器没有回调，恒为 `false`。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `bool` | 当前判断结果，初始 `false` |

**返回值**：`bool`。Pro 附加组件在授权校验通过后返回 `true`。

**版本**：2.0.1

**红线**：免费版的任何**功能**都不得以本过滤器为开关。它只用来决定「要不要显示 Pro 的界面入口」，
不用来决定「免费功能要不要跑」。把免费功能挂在这个开关上就是 Trialware / Feature Lock（规范 §6、§102）。

### at8sec_admin_tabs

**用途**：增删后台设置页的标签页。Pro 附加组件用它挂上自己的标签。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `array<string,string>` | 标签 slug => 显示名 |

**返回值**：`array<string,string>`。返回值会被强转成数组，返回标量等于清空所有标签页。

**版本**：2.0.1

### at8sec_pro_url

**用途**：改「升级到 Pro」链接的目标地址（自建分发的镜像站、代理商落地页）。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string` | 目标 URL，默认 `https://www.at8.fun/` |

**返回值**：`string`。输出时经 `esc_url()`，可返回任何合法 URL。

**版本**：2.0.1

### at8sec_captcha_method

**用途**：选择人机校验方式。免费版内置算术题（`math`）；Pro 附加组件返回自己的方法名，
把算术题换成托管控件。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string` | 方法名，默认 `math` |

**返回值**：`string`。返回空串或非字符串时回退为 `math` —— 一个装坏的附加组件不能让登录页失去校验。

**版本**：2.0.1

### at8sec_captcha_rendered

**用途**：附加组件声明「控件我已经渲染过了」，免费版据此跳过自己的算术题，避免一个表单里出现两个挑战。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string` | 已渲染内容的标记，默认空串 |

**返回值**：`string`。返回非空即表示已接管渲染。

**版本**：2.0.1

**配合**：必须在 `at8sec_captcha_render` 里真的渲染了控件，否则登录表单会没有任何校验。

### at8sec_captcha_verify

**用途**：让附加组件自行校验它渲染的挑战。仅当 `at8sec_captcha_method` 返回的不是 `math` 时触发。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `\WP_User\|\WP_Error\|null` | 到此刻为止的认证结果 |
| 2 | `string` | 当前选中的方法名 |

**返回值**：`\WP_User|\WP_Error|null`。校验通过返回传入的 `$user`；不通过返回 `WP_Error`
（或一个不带该用户的 `WP_Error`），登录随即被拒。

**版本**：2.0.1

### at8sec_weak_password_dictionary

**用途**：补充弱口令字典。与内置字典合并，不替换。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string[]` | 当前字典 |

**返回值**：`string[]`。

**版本**：2.0.1

### at8sec_public_rest_routes

**用途**：把路由加进 REST 白名单，使其在「锁定 REST」开启后仍然公开可访问。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string[]` | 当前白名单（路由前缀） |

**返回值**：`string[]`。

**版本**：2.0.1

**注意**：白名单是**放开**访问的开关。加进去之前先确认该路由本身有权限校验，否则等于自己开了个洞。

### at8sec_denied_upload_extensions

**用途**：增删禁止上传的扩展名。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string[]` | 当前黑名单，小写、不含点 |

**返回值**：`string[]`。

**版本**：2.0.1

### at8sec_waf_patterns

**用途**：增删防火墙规则集（SQLi / 路径穿越 / 命令执行等）。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `array` | 规则集，每条含匹配模式与命中后的处理 |

**返回值**：`array`。

**版本**：2.0.1

**性能**：规则在**每个**前台请求上跑一遍。加一条昂贵正则等于给全站加一份固定开销。

### at8sec_waf_xss_pattern

**用途**：替换 XSS 检测正则。整体替换，不是追加。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string` | 当前正则 |

**返回值**：`string`。必须是合法正则；返回非法正则会触发 PHP Warning。

**版本**：2.0.1

### at8sec_bad_user_agents

**用途**：增删扫描器 UA 特征串。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string[]` | 当前特征串 |

**返回值**：`string[]`。

**版本**：2.0.1

### at8sec_trusted_proxies

**用途**：合并进可信反代 / CDN 网段。Pro 的 CDN 模块用它把自动探测到的网段接进来，
而不是去写免费版的设置项。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string[]` | 当前列表（来自 `AT8SEC_TRUSTED_PROXIES` 常量 + `trusted_proxies` 设置项） |

**返回值**：`string[]`。IP 或 CIDR 均可，IPv4 / IPv6 都支持。

**版本**：2.0.1

**安全**：这份列表决定「转发头能不能被采信」。加错一个网段，就等于允许那个网段里的任何人
伪造来源 IP，同时绕过黑名单、限流和审计日志（见 `get_client_ip()` 的注释）。

### at8sec_client_ip_headers

**用途**：调整取真实 IP 时按序尝试的 `$_SERVER` 键。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string[]` | 当前顺序，默认 `HTTP_X_REAL_IP`、`HTTP_CF_CONNECTING_IP`、`HTTP_X_FORWARDED_FOR` |

**返回值**：`string[]`。

**版本**：2.0.1

**安全**：这些头是攻击者可控的文本，**仅当直连对端落在可信代理列表内时才会被读取**。
本过滤器只改「读哪个头」，不改「能不能读」。

---

## Actions

| Action | 参数 | 用途 |
|---|---|---|
| `at8sec_loaded` | `Plugin` | 免费版加载完成，附加组件在此挂自己的钩子 |
| `at8sec_admin_panel` | `string` | 渲染某个标签页的内容区 |
| `at8sec_pro_panel` | — | 附加组件接管「Pro」标签页 |
| `at8sec_captcha_render` | — | 在免费版挑战渲染前插入 |
| `at8sec_login_locked` | `string`, `int` | 某个 IP 因连续失败被锁定 |

### at8sec_loaded

**用途**：免费版模块注册完毕后的总入口。附加组件在这里挂自己的钩子。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `Plugin` | 免费版主控实例（`At8\Security\Plugin`），可用 `->module( $name )` 取内部模块 |

**版本**：2.0.1

**契约**：触发时免费版已可用，**但附加组件不存在时免费版一切照常**。这是免费版可独立运行的保证，
也是 `docs/free-pro-boundary.md` 的核心。

### at8sec_admin_panel

**用途**：渲染某个设置标签页的内容区。Pro 附加组件用它把自己的面板画进免费版的界面。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string` | 标签 slug（`general` / `firewall` / `headers` / `comments` / `tools` / `pro`，或由 `at8sec_admin_tabs` 新增的） |

**版本**：2.0.1

### at8sec_pro_panel

**用途**：附加组件接管「Pro」标签页。免费版用 `has_action( 'at8sec_pro_panel' )` 判断它是否被接管，
接管了就不再输出升级说明，避免同一屏出现两份内容。

**参数**：无。

**版本**：2.0.1

**注意**：判断依据是**附加组件是否存在**，不是**是否已授权**。这个标签页正是填密钥的地方，
若按授权状态隐藏，用户就永远没地方激活 —— 门禁不能锁住它自己的钥匙。2.0.1 之前的版本犯过这个错。

### at8sec_captcha_render

**用途**：在免费版算术题渲染之前插入，给附加组件渲染自己控件的机会。

**参数**：无。

**版本**：2.0.1

### at8sec_login_locked

**用途**：某个 IP 因连续登录失败被锁定时触发（通知、拉黑、写外部日志）。

**参数**

| 位置 | 类型 | 说明 |
|---|---|---|
| 1 | `string` | 触发锁定的 IP |
| 2 | `int` | 锁定时长（分钟） |

**版本**：2.0.1

**注意**：回调里不要回写本站设置或做重活 —— 锁定发生在登录请求的关键路径上。

---

## Functions

命名空间 `At8\Security`。这些是免费版自己也在用的函数，因此行为稳定。

| 函数 | 签名 | 说明 |
|---|---|---|
| `is_pro()` | `(): bool` | `at8sec_is_pro` 的读取入口 |
| `parse_list( $raw )` | `( mixed ): string[]` | 按换行 / 逗号 / 分号 / 空白拆分列表 |
| `parse_ip_list( $raw )` | `( mixed ): string[]` | 拆分并只保留合法 IP / CIDR，去重 |
| `ip_in_list( $ip, $list )` | `( string, mixed ): bool` | IP 是否落在列表内（支持 CIDR） |
| `ip_in_cidr( $ip, $cidr )` | `( string, string ): bool` | IP 是否落在 CIDR 内，IPv4 / IPv6 同一套逻辑 |
| `get_trusted_proxies()` | `(): string[]` | 可信代理列表（常量 + 设置 + 过滤器） |
| `get_client_ip()` | `(): string` | 解析访客 IP，无法判定时返回 `0.0.0.0` |
| `ip_hash( $ip )` | `( string ): string` | IP 的 HMAC-SHA256 前 20 位，用于 transient 键 |
| `enqueue_front_style()` | `(): void` | 前台 / 登录页样式入队，可重复调用 |

**`get_client_ip()` 的行为契约**：直连对端不在可信代理列表内时，一律返回 `REMOTE_ADDR`，
转发头**完全不读**。这不是可以按站点放宽的策略，是这套防护的地基。

**`ip_hash()` 为什么不是 `md5()`**：选项表里出现裸 IP 是隐私问题，固定长度则让 transient 键可预期。
加盐（`wp_salt('nonce')`）使哈希不能跨站比对。

---

## Constants

| 常量 | 类型 | 说明 |
|---|---|---|
| `AT8SEC_VERSION` | `string` | 版本号，与插件头 `Version:` 同值 |
| `AT8SEC_FILE` | `string` | 主文件绝对路径 |
| `AT8SEC_DIR` | `string` | 插件目录（尾斜杠） |
| `AT8SEC_URL` | `string` | 插件 URL（尾斜杠） |
| `AT8SEC_BASENAME` | `string` | `at8-security/at8-security.php` |
| `AT8SEC_OPTION` | `string` | 设置项名，`at8_security_settings` |
| `AT8SEC_MIN_PHP` | `string` | 最低 PHP 版本，`7.4` |

可选常量（定义在 `wp-config.php`，不定义即不生效）：

| 常量 | 类型 | 说明 |
|---|---|---|
| `AT8SEC_TRUSTED_PROXIES` | `string` | 可信代理网段，与设置项合并后进入 `at8sec_trusted_proxies` |

---

## Data structures

### 设置项 `at8_security_settings`

单一数组选项，47 个键。schema 在 `Settings::schema()`，**以源码为准**（每个键带类型与默认值）。

| 分组 | 键 |
|---|---|
| 加固 | `hide_wp_version` `disable_xmlrpc` `disable_rest_user_enum` `uniform_login_errors` `disable_file_editor` `remove_rsd_wlw` `disable_author_enum` `disable_dir_listing` `uploads_disable_php` |
| 安全响应头 | `security_headers` `enable_csp` `csp_report_only` `csp_policy` |
| HTTPS | `force_https` `hsts` |
| 登录防护 | `login_throttle` `login_max_retries` `login_lockout_minutes` `change_login_url` `login_slug` `login_email_alert` `login_alert_email` `honeypot` `login_captcha` |
| 口令策略 | `pwd_policy` `pwd_min_length` `weak_pwd_scan` |
| 防火墙 | `waf_basic` `waf_sql` `waf_xss` `waf_traversal` `waf_rce` `ip_blacklist` `ip_whitelist` `trusted_proxies` `rate_limit_global` `rate_limit_max` `rate_limit_window` `block_bad_ua` `error_404_throttle` `error_404_threshold` |
| 评论 / 上传 / REST | `comment_spam` `disable_comments` `upload_restrict` `rest_lockdown` |
| 审计 | `audit_log` `audit_log_days` |

类型为 `Settings::BOOL` / `INT` / `TEXT` / `EMAIL` / `SLUG` / `LIST` / `CSP` 之一。
写入一律经 `Settings::sanitize()` 按 schema 走 —— 缺失的键取默认值，不从提交值猜。

**附加组件不应直接写这个选项**。要用什么就加过滤器（如 `at8sec_trusted_proxies`），
否则免费版的 schema 校验会把附加组件写的键清掉，表现为「设置保存后自己变回去了」。

### 其他选项

| 选项 | 说明 |
|---|---|
| `at8sec_version` | 已安装版本，激活时写入 |
| `at8sec_audit_purge` | 审计裁剪 cron 钩子 |

---

## Compatibility

| 项目 | 要求 |
|---|---|
| PHP | ≥ 7.4（`AT8SEC_MIN_PHP`；低于此版本插件自行停用并在后台提示，不会白屏） |
| WordPress | ≥ 6.4 |
| 多站点 | 逐站各自一套设置与审计表 |
| 冲突处理 | 过滤器返回非法类型时一律回退到默认值，不抛异常、不返回空 |

**稳定性承诺**：

- **MINOR 版本只增不改** —— 新钩子可以加，已有钩子的名字、参数顺序、返回值含义不动。
- 钩子被废弃时先保留并加 `_deprecated` 提示，至少一个大版本后才移除。
- 免费版**不会**因为附加组件缺失而少任何一个功能；`at8sec_*` 钩子全部是「增强点」而非「补全点」。
