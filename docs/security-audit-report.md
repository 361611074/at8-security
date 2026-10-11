# 安全审计报告

规范 §12 / §13 / §14 / §29 / §99。

**审计对象**：`at8-security` 2.0.3、`at8-security-pro` 2.0.3、`at8-license-server` 1.2.0
**审计日期**：2026-09-25
**环境**：测试站 `wordpress.xmm.fan`，WordPress 7.1.2 / PHP 8.3.33 / zh_CN

做法见 `docs/security.md`；本文件是**实测记录**。

> **范围说明（2026-10-11）**：本报告同时覆盖 `at8-security-pro` 与 `at8-license-server`。
> 这两个产品已迁至各自独立仓库（`at8-security-pro`、`at8-license-server-v2`），本仓库只保留免费版
> `at8-security`；报告中对它们的记述作为**历史审计记录**保留，其代码与后续变更以各自仓库为准。

---

## 结论

```text
XSS                    PASS
CSRF                   PASS
SQL Injection          PASS
Privilege Escalation   PASS
REST API               PASS
AJAX                   PASS
File Operations        PASS
Authentication         PASS
Secrets                PASS
```

---

## 自动化检查

| 检查 | 命令 | 结果 |
|---|---|---|
| CSS 无 `!important` | `grep -rn "!important" --include="*.css"` | **0** |
| 无动态执行 | `grep -rnE "eval\(\|create_function\|extract\(" --include="*.php"` | **0** |
| 拒绝路径带状态码 | `grep -rnE "wp_die\(\s*[^,)]+\s*\)\s*;" --include="*.php"` | **0** |
| 秘密模式 | `grep -rnE "sk_live\|pk_live\|AKIA[0-9A-Z]{16}\|BEGIN .*PRIVATE KEY"` | **0** |
| 免费版零出站 | `grep -rn "wp_remote_\|curl_\|fsockopen" free/` | **0** |
| 反序列化安全 | 见下 | 2 处，均安全 |
| 数据库查询参数化 | 见下 | **30/30** |

---

## SQL Injection

`license-server` 全部数据库调用逐条核对：

| 方式 | 数量 | 说明 |
|---|---|---|
| `$wpdb->prepare()` + `%i` / `%s` / `%d` | 12 | 表名用 `%i`（WP 6.2+ 标识符占位符） |
| `$wpdb->insert()` / `update()` / `delete()` | 18 | 走参数化 API，带格式数组（`%d` / `%s`） |
| **未参数化** | **0** | — |

核对方式（脚本遍历全部调用点，检查其后 4 行内是否有 `prepare(`）：

```text
wpdb calls total: 30
non-parameterised (excluding insert/update/delete API): 0
```

**表名为什么用 `%i` 而不是拼字符串**：静态分析工具看变量判定为注入，`%i` 既正确又能通过扫描。
**授权表为什么允许直连查询**：按密钥哈希查单行，缓存没有意义；相关调用点统一带
`phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, ...NoCaching`。

**密钥查找的注入面**：`Keys::find()` 先按 `key_hash()` 查，再回退按 `key_norm` 查，
两者都是 `prepare()` 的参数，密钥内容不参与 SQL 拼接。

---

## XSS

| 面 | 做法 | 状态 |
|---|---|---|
| 后台输出 | `esc_html` / `esc_attr` / `esc_url` / `esc_textarea` 按上下文 | 已核对 |
| REST 响应 | JSON 序列化，不拼 HTML | ✅ |
| 二进制流（zip 下载） | 不转义，带 `phpcs:ignore` 与理由注释 | ✅ 有意为之 |
| WAF 自身 | 检测规则读原始 `REQUEST_URI`（`(string) wp_unslash(...)`），**只用于匹配、不回显** | ✅ |

**为什么 WAF 读未清洗的 `REQUEST_URI`**：要识别 `?id=1' OR 1=1` 这类攻击，就必须看到原始串。
清洗后再匹配等于把攻击特征抹掉。该值**不进入任何输出**，只参与正则匹配与日志事件名。

---

## CSRF

| 面 | 做法 |
|---|---|
| 后台写操作 | 每个写操作一个 nonce，且**按对象 ID 细分**（`_update_{id}` / `_status_{id}` / `_release_{slug}`），避免跨对象重放 |
| 校验位置 | 每个 `switch` 分支内 `check_admin_referer()` |
| REST 写操作 | 不用 nonce（客户站点未登录），靠密钥本身鉴权 + 限流 |
| 公开表单（登录 / 评论） | 不需要 nonce（非状态变更），但走蜜罐 + 算术题 + 限流 |

`at8sec-attack.py` 有专门断言覆盖「无 nonce 的 POST 被拒」。

---

## Privilege Escalation

| 面 | 做法 | 状态 |
|---|---|---|
| 菜单注册 | `CAP = manage_options` | ✅ |
| 渲染前 | `current_user_can( 'manage_options' )` | ✅ |
| `handle()` 开头 | 再次校验 | ✅ |
| 拒绝的响应码 | **403**，不是 500 | ✅ |
| 拒绝留痕 | `Admin::refuse()` 写 `admin.refused` 审计（记 user_id / 所需 capability / 提交的 task） | ✅ |

**为什么拒绝必须是 403**：`wp_die( $message )` 不带 `response` 时返回 **HTTP 500**，
读起来像「服务器坏了」而不是「你没权限」，监控里也会被当成故障。
`at8sec-attack.py` 有一项断言专门盯这个（`the permission refusal carries a 403, not a 500`），
因为 500 与 403 在功能测试里都表现为「拒绝」，很容易悄悄退回去。

**免费版 `/pro` 标签页**：判断依据是 `has_action( 'at8sec_pro_panel' )`（附加组件是否存在），
**不是**授权状态。若按授权状态隐藏，用户就没地方填密钥了 —— 门禁不能锁住它自己的钥匙。

---

## REST API

| 面 | 做法 |
|---|---|
| 权限声明 | 每个路由显式 `permission_callback`；公开路由也 `return true` 写明白，不省略 |
| 限流 | 双档：已知密钥 600/10min，未知密钥 30/10min（按 IP） |
| 429 响应 | 统一结构，不泄露内部原因 |
| 错误信息 | 不吐 SQL、不吐路径 |
| 命名空间 | `at8/v1`（平台统一）+ `at8-license/v1`（旧客户端，不得下线） |
| 参数校验 | `register_rest_route()` 的 `args` 声明类型；回调内再洗一遍（REST 不负责业务约束） |

**IP 识别**：直连 `REMOTE_ADDR`；仅当直连对端落在可信代理列表内时才采信转发头。
**不读取裸 `X-Forwarded-For`**（除非对端可信）。见 `docs/free-extension-api.md` 的
`at8sec_client_ip_headers` 与 `get_client_ip()` 的行为契约。

---

## Authentication

| 面 | 做法 |
|---|---|
| 登录限流 | 按 IP 计数，超限锁定指定分钟 |
| 锁定告警 | `do_action( 'at8sec_login_locked', $ip, $minutes )` |
| 蜜罐 | 隐藏字段，机器人会填 |
| 人机校验 | 免费版本地算术题（答案存 transient，15 分钟）；Pro 可换 Turnstile |
| 口令策略 | 最短长度 + 弱口令字典 + 注册/改密表单校验 |
| 用户枚举 | 统一登录错误提示；隐藏 REST 用户列表；阻止 `?author=` 枚举 |
| 两步验证（Pro） | 恢复码 + 记住设备；`$_COOKIE` 读取经 `sanitize_text_field( wp_unslash( ... ) )` |
| 密码字段 | `(string) wp_unslash( $_POST['pass1'] )` —— **有意不清洗**，清洗会破坏密码本身 |
| 应用密码 / REST / WP-CLI | 算术题只对真正提交登录表单的请求生效（检查 `$_POST['log']`），这些流程保持可用 |

---

## File Operations

| 面 | 做法 |
|---|---|
| 上传 | `wp_handle_upload()`，MIME 白名单 = `application/zip`，只接受 `.zip` |
| 上传文件名 | `sanitize_file_name()` + 扩展名黑名单 + 双扩展名检测 |
| 安装包存放 | `uploads/at8-packages/`，文件名 24 位随机串 |
| 安装包访问 | Web Server 禁访，只能经签名路由取得 |
| 包完整性 | 上传时记 sha256，下载前重新比对 |
| `.htaccess` | 只在 Apache 上写；卸载时移除自己写的块（`insert_with_markers` / `extract_from_markers`） |
| 免费版上传目录 | 可选禁止 PHP 执行 |

---

## Secrets

| 项 | 做法 | 状态 |
|---|---|---|
| 授权密钥生成 | `random_int()` 逐字符，Crockford Base32 去 `I`/`L`/`O`/`U`，4 组 × 5 位，签发查重 | ✅ |
| 密钥存储 | **HMAC-SHA256 + `wp_salt('auth')` 加盐**，库里不存明文（1.2.0 起） | ✅ |
| 后台展示 | 掩码 `AT8-SEC-•••••-•••••-•••••-9K3M2`；完整密钥只在创建时经用户级 transient 展示一次，读取即删 | ✅ |
| 下载 token | HMAC-SHA256（key = `wp_salt('auth')`），base64url，30 分钟有效 | ✅ |
| 比较 | `hash_equals()` 恒定时间 | ✅ |
| 审计日志 | `Audit::is_secret()` 丢弃字段名含 key/secret/token/password/hash/signature 的值 | ✅ |
| 源码 | 无硬编码密钥（秘密模式扫描 0 命中） | ✅ |
| 反序列化 | `@unserialize( $data, array( 'allowed_classes' => false ) )` —— 禁止对象注入 | ✅ |
| base64 解码 | 2 处均传 `$strict = true` | ✅ |

**为什么密钥用 HMAC 而不是 `password_hash()`**：`password_hash()` 每次换盐，同一密钥两次哈希不同，
**没法按密钥查库**。授权校验必须先定位到那一行，所以需要「确定性 + 不可逆」。
加盐是为了防彩虹表，且哈希不能跨站比对。详见 `docs/license-api.md`。

---

## 对抗测试

`at8sec-attack.py` —— 越权访问后台、缺 nonce、注入、超大 key、无 key 调用、限流。

```text
30/30
```

覆盖要点：

| 类别 | 断言示例 |
|---|---|
| 越权 | 非管理员访问后台 → 403（**不是 500**） |
| CSRF | 无 nonce POST → 拒绝 |
| 注入 | SQL / XSS / 路径穿越 / 命令执行探针 → 403 |
| 密钥 | 超大 key、无 key、错误 key → 拒绝且不泄露细节 |
| 限流 | 未知密钥高频请求 → 429 |
| 密钥存储 | 库内无明文、64 位哈希、转储的哈希无法当密钥用、尾段不足以重建密钥 |

---

## 相关文件

| 文件 | 内容 |
|---|---|
| `docs/security.md` | 做法清单（本文档的规范侧） |
| `docs/wordpress-org-compliance-report.md` | 上架合规结论 |
| `docs/license-api.md` | 密钥形态、存储与迁移 |
| `docs/testing.md` | 测试脚本与每次改动必须覆盖的清单 |
