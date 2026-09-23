# 安全

适用于本产品线全部三个插件。逐条对应规范 §12 / §13 / §14 / §29。

## 输入

- 所有外部输入过 `sanitize_*` / `absint` / `(int)` / `sanitize_key` / `sanitize_file_name`；`$_POST` / `$_GET` / `$_SERVER` 一律先 `wp_unslash()` 再清洗。
- REST 参数在 `register_rest_route()` 的 `args` 里声明类型；回调内再洗一遍（REST 不负责业务约束）。
- 文件上传走 `wp_handle_upload()`（MIME 白名单 = `application/zip`），只接受 `.zip`。

## 输出

| 场景 | 函数 |
|---|---|
| HTML 文本 | `esc_html` / `esc_html_e` / `esc_html__` |
| 属性 | `esc_attr` |
| URL | `esc_url` / `esc_url_raw`（入库） |
| textarea | `esc_textarea` |
| JS 内联 | `esc_js` |
| 富文本 | `wp_kses_post` |
| **二进制流**（zip 下载） | 不转义，`// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` + 注释说明原因 |

## 数据库

- 一律 `$wpdb->prepare()`；表名用 `%i`（WP 6.2+ 标识符占位符），避免静态分析看变量判定为注入。
- 自建表用 dbDelta 建/改，`Db::DB_VERSION` 控制迁移；**不 DROP**（卸载流程除外，且默认不执行）。
- 直连查询处统一 `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching` —— 授权表按密钥查询，缓存没有意义。

## 后台与权限

| 检查 | 位置 |
|---|---|
| `current_user_can( 'manage_options' )` | 菜单注册（`CAP`）+ 渲染前 + `handle()` 开头 |
| nonce | 每个写操作一个，且**按对象 ID 细分**（`_update_{id}` / `_status_{id}` / `_release_{slug}`），避免跨对象重放 |
| `check_admin_referer()` | 每个 case 分支内 |

**拒绝必须给出明确状态码。** `wp_die( $message )` 不带 `response` 时返回 **HTTP 500**，
读起来像「服务器坏了」而不是「你没权限」，监控里也会被当成故障。权限拒绝统一走 `Admin::refuse()`：

```php
wp_die( $message, '', array( 'response' => 403 ) );
```

`refuse()` 同时往审计日志写一条 `admin.refused`（记 user_id / 所需 capability / 提交的 task）：
一个没有权限的账号去提交授权操作，不是角色配错就是有人在试，两种都该查得到。
`at8sec-attack.py` 有一项断言专门盯这个状态码（`the permission refusal carries a 403, not a 500`），
因为 500 与 403 在功能测试里都表现为「拒绝」，很容易悄悄退回去。

## REST

- 每个路由显式声明 `permission_callback`；公开路由也要 `return true` 写明白（不是省略）。
- 限流双档（见 `docs/api.md`）；429 返回统一结构，不泄露内部原因。
- 错误信息最小化：不吐 SQL、不吐路径、不区分「密钥不存在」与「密钥被删」的细节差异超出必要。

## 密钥与签名

| 项目 | 做法 |
|---|---|
| 授权密钥 | `random_int()` 逐字符生成（Crockford Base32 去 I/L/O/U），4 组 × 5 位，签发时查重，唯一索引兜底 |
| 下载 token | HMAC-SHA256，key = `wp_salt('auth')`（源码与库中都不存在），base64url 编码 |
| 比较 | `hash_equals()` 恒定时间 |
| 校验 | 签名 → 授权 active → 未过期 → 文件 sha256 一致 |
| 存储 | `wp_at8_licenses.license_key` + `key_norm`（归一化，唯一索引）。**注意：当前为明文存储**，规范 §5.3 建议改为 HMAC 存储 + 后台只显示部分，该项未实施，需产品决策（见 `docs/database.md` 的待决项） |

## 日志与审计

- 审计日志记录：actor、来源 IP、动作、对象、metadata（仅标量）。
- **字段名含 `key` / `secret` / `token` / `password` / `hash` / `signature` 的值一律丢弃**（`Audit::is_secret()`），`license_id` 与 `max_sites` 例外白名单。
- 登录 / 支付 / API Secret 不进日志；支付私钥禁止进 Git。
- 审计表按保留期（默认 180 天）每日裁剪，可配置。

## WAF（免费版）

- 签名匹配：SQLi / 路径穿越 / 命令执行 / XSS、扫描器 UA、请求频率、404 洪水。
- 后台请求不扫描（后台已在认证之后，扫描只会误伤正常操作）。
- CLI（WP-CLI 或裸 PHP 加载 wp-load，如自定义 cron）跳过：无 `REMOTE_ADDR` 时不能判定来源。

## 自查清单（每次改动后跑）

```bash
grep -rn "!important" --include="*.css" .          # 0
grep -rnE "eval\(|create_function|extract\(" --include="*.php" .   # 0
grep -rn "_GET\['" --include="*.php" . | grep -v "wp_unslash"      # 应为 0 或已 sanitize
grep -rn "wp_remote_" --include="*.php" free/      # 免费版必须 0
```
