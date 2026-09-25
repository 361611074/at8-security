# At8 License Server 说明文档

> 版本 1.2.0 · 授权服务器插件 · 仅在本方站点运行，不分发、不装到客户站点

---

## 1. 这是什么

`at8-license-server` 是 at8 系列付费插件（目前为 `at8-security-pro`）的**授权与更新服务端**，运行在自己的 WordPress 站点上（计划部署于 at8.fun），负责四件事：

1. **签发授权密钥**（后台手动创建，格式统一、可读、防误抄）
2. **响应客户端插件的激活 / 校验 / 解绑请求**（REST API）
3. **托管更新包**：客户端凭有效授权收到 WP 原生更新推送，下载走短时效签名链接
4. **管理后台**：查看/管理密钥、已绑定站点、发布新版本、查阅审计日志

配套的客户端插件（如 at8-security-pro）在「设置 → 授权」填入密钥后，即自动完成激活、每日两次心跳校验、更新检查与自动更新。

---

## 2. 环境要求

| 项目 | 要求 |
|---|---|
| WordPress | 6.4+（`Requires at least: 6.4`） |
| PHP | 7.4+（低于此版本自动停用并显示后台提示，不影响站点运行） |
| 数据库 | 复用现有 WP 数据库，自动建 3 张表 |
| 服务器 | 无特殊要求；nginx 建议加一条目录禁访规则（见 §8） |

---

## 3. 安装

1. 上传 `at8-license-server` 到 `wp-content/plugins/` 并启用。
2. 激活时自动建表（`wp_at8_licenses`、`wp_at8_license_sites`、`wp_at8_audit_logs`），无需手动操作。
3. 后台左侧菜单出现 **「At8 Licences」**（锁图标），仅 `manage_options` 权限可见。

---

## 4. 后台管理（四个标签页）

### 4.1 授权密钥（Licenses）

- **创建密钥**：选择产品 → 填有效期天数（0 = 终身）→ 允许绑定站点数（0 = 不限）→ 备注（如客户标识）
- **密钥只在创建后显示一次**（见 §5），请当场复制交给客户
- 密钥列表显示：**掩码后的密钥**、产品、套餐、状态、到期时间、站点数、备注、创建时间
- 每条密钥可执行：**启用 / 停用 / 吊销**、**调整站点数上限**、**修改备注**、**删除**（同时解绑所有站点）
- 页面顶部如检测到数据表缺失，提供「重建数据表」修复按钮

### 4.2 已激活站点（Sites）

- 全部站点绑定列表：所属密钥、域名、激活时间、最后心跳时间、最后 IP、WP/PHP 版本
- 可对单个站点执行**解绑**——释放席位，密钥即可换绑到新站点

### 4.3 版本发布（Release）

- **上传更新包**：选择插件 zip + 填版本号 + 兼容版本（WP / PHP）+ 更新记录
- 已发布版本列表：产品、版本、文件、发布时间；可**下架**（文件保留在磁盘）
- 发布后，持有有效授权的站点会在「插件」页面自动收到更新推送

### 4.4 审计日志（Audit）

- 记录「有决策价值」的事件：密钥新建 / 状态变更 / 绑定 / 解绑 / 被拒 / 发布状态变更 / 设置变更
- **例行心跳不记**（每天两次 × 全部站点会淹没日志）
- 元数据只记标量，且**字段名含 key / secret / token / password / hash / signature 的值一律丢弃**
- 按保留期（30 / 90 / 180 / 365 天，默认 180）每日自动裁剪

---

## 5. 授权密钥

### 5.1 格式

```
AT8-SEC-XXXXX-XXXXX-XXXXX-XXXXX
```

- 前缀 `AT8` + 产品代码（`at8-security-pro` → `SEC`）
- 随机部分 4 组 × 5 位，字母表为 Crockford Base32 去掉 I/L/O/U——**抄写时不会混淆 1/0、不会拼出脏词**
- 校验时自动归一化：大小写、空格、连字符差异不影响匹配（客户怎么粘贴都能激活）

### 5.2 存储（1.2.0 起）

**数据库里不存明文密钥。**

| 列 | 存什么 |
|---|---|
| `key_norm` | `hash_hmac( 'sha256', 归一化密钥, wp_salt('auth') )`，唯一索引，查找只走它 |
| `license_key` | 恒为空串（列保留，便于迁移与回滚） |
| `key_hint` | 密钥末 5 位，**仅供后台展示**，不足以反推密钥 |

**为什么用 HMAC 而不是 `password_hash()`**：后者每次调用都换盐，同一密钥两次哈希结果不同，
**没法按密钥查库**；授权校验必须先定位到那一行，所以需要「确定性 + 不可逆」。
加盐是为了防彩虹表，并让哈希不能跨站比对。

### 5.3 完整密钥只显示一次

签发后密钥经**用户级 transient** 展示一次（300 秒后失效，读取即删）。之后后台列表只显示：

```
AT8-SEC-•••••-•••••-•••••-9K3M2
```

**这是 1.2.0 的行为变化**：因为库里只有哈希，明文离开那一屏就再也取不回来。
工作流相应变为「**签发 → 立刻复制给客户**」。

从 1.0.x 升级上来的历史数据会在首次加载后台时自动迁移：逐行哈希、补上末 5 位、清空明文列。
迁移是**幂等**的，且**只加列不删列**。

> 极少数行（迁移前就已哈希、无法补末 5 位）在列表里显示 `licence #<id>`。
> 这比编一个假尾段诚实。

---

## 6. REST API

命名空间：`/wp-json/at8/v1/`（平台统一地址）与 `/wp-json/at8-license/v1/`（第一版客户端写死的地址，**不得下线**）。
两者注册同一组路由，这样服务器可以比客户站点先升级。

### 6.1 激活 `POST /activate`

客户端首次绑定站点时调用。

```json
// 请求
{
  "license": "AT8-SEC-XXXXX-XXXXX-XXXXX-XXXXX",
  "domain":  "customer-site.com",   // 或 site_url
  "product": "at8-security-pro",
  "wp":      "6.7",                 // 可选，记录客户环境
  "php":     "8.2",                 // 可选
  "environment": "production"       // 可选，production / staging / development
}

// 成功响应
{
  "valid": true,
  "status": "active",
  "expires": 0,          // Unix 时间戳，0 = 终身
  "plan": "lifetime",
  "message": "Licence active."
}
```

### 6.2 校验 `POST /validate`

每日两次心跳复检，不新增绑定。未绑定的站点调用会返回 `inactive`（防止把未激活的副本站当有效）。

### 6.3 解绑 `POST /deactivate`

站点停用插件时调用，释放该域名占用的席位。

```json
{ "license": "AT8-SEC-…", "domain": "customer-site.com" }
```

### 6.4 更新检查 `POST /update`

先重新校验授权（已吊销的密钥拿不到下载链接），有效则返回：

```json
{
  "valid": true,
  "version": "2.0.3",
  "download_url": "https://…/wp-json/at8/v1/package?token=…",
  "homepage": "https://www.at8.fun/",
  "requires": "6.4",
  "tested": "7.1",
  "requires_php": "7.4",
  "changelog": "…",
  "last_updated": "2026-09-25 08:00:00"
}
```

只有状态为 `published` 的构建才会被下发。客户端把这个响应对接 WP 原生 `update_plugins` transient，
后台即出现「有可用更新」。客户端会把结果缓存 12 小时，失败后退避 1 小时。

### 6.5 下载 `GET /package?token=…`

- `download_url` 中的 token 为 **HMAC-SHA256 签名**（密钥用 `wp_salt('auth')`，不落库不入客户端），
  载荷为 `license_id|product|expires`，**有效期 30 分钟**
- 下载时**再次核验**：token 签名 → 授权仍为 active → 授权未过期 → 文件 sha256 与上传记录一致，任一不满足即 403
- 响应为 zip 文件流（`application/zip`）

### 6.6 错误状态一览

| `status` | 含义 |
|---|---|
| `active` | 授权有效 |
| `rejected` | 密钥不存在 / 产品不匹配 / 席位已满 |
| `inactive` | 已停用 / 已暂停 / 已吊销 / 站点未绑定 / 无已发布版本 |
| `expired` | 已过期 |
| `unreachable` | HTTP 429，触发频率限制 |

### 6.7 频率限制（防暴力猜 key）

| 限额 | 数值 | 窗口 |
|---|---|---|
| 常规调用（已知 key） | 600 次 / IP | 10 分钟 |
| 未知 key（视为猜测） | 30 次 / IP | 10 分钟 |

分开限额的原因：同一机房/NAT 出口下大量正常站点共享 IP，常规额度必须宽松；而拿不存在的 key 来问的只有猜 key 的人，从严。IP 来源识别支持 Cloudflare（`CF-Connecting-IP` 仅在直连 IP 属于可信网段时采信），本地调试回环放行。

---

## 7. 数据存储

### 7.1 数据表

**`{prefix}at8_licenses`** — 密钥主表

| 字段 | 说明 |
|---|---|
| `key_norm` | **HMAC-SHA256 哈希**（唯一索引，查找走它） |
| `license_key` | 恒为空串（历史遗留列） |
| `key_hint` | 密钥末 5 位，仅展示用（v3 新增） |
| `product` | 产品 slug |
| `plan` | 套餐（默认 `lifetime`，可扩展 `yearly` 等） |
| `status` | `active` / `disabled` / `suspended` / `revoked` |
| `expires` | 到期 Unix 时间，0 = 终身 |
| `max_sites` | 允许绑定站点数，0 = 不限 |
| `note` / `created_at` / `created_by` | 备注、创建时间、创建人 |

**`{prefix}at8_license_sites`** — 站点绑定表

| 字段 | 说明 |
|---|---|
| `license_id` + `domain` | 联合唯一索引（同一密钥同域名只有一条） |
| `site_url` / `home_url` / `site_hash` | 完整 URL 与域名哈希 |
| `environment` | `production` / `staging` / `development` |
| `activated_at` / `last_seen` / `last_ip` | 激活时间 / 最后心跳 / 最后来源 IP |
| `wp_version` / `php_version` | 客户环境（心跳时更新） |

**`{prefix}at8_audit_logs`** — 审计日志表

| 字段 | 说明 |
|---|---|
| `actor_id` / `actor_ip` | 操作人（0 = 客户端调用）/ 来源 IP |
| `action` | 点分事件名 |
| `object_type` / `object_id` | `license` / `release` / `setting` |
| `metadata` | JSON，仅标量且不含密钥 |
| `created_at` | 索引，按保留期裁剪 |

### 7.2 Options

- `at8ls_releases` — 各产品已发布版本的元数据（版本号、文件名、changelog 等）
- `at8ls_db_version` — 数据表版本号（升级程序依据，当前 `3`）
- `at8ls_audit_days` — 审计保留天数（默认 180）
- `at8ls_purge_on_uninstall` — 卸载是否删除商业记录（默认 0，见 §11）
- `at8_license_server_settings` — 预留

### 7.3 更新包文件

存放于 `wp-content/uploads/at8-packages/`：

- 写入 `.htaccess`（Apache 直接禁访）+ `index.html`
- **文件名带 24 位随机串**——nginx 不理会 `.htaccess`，随机文件名保证任何服务器上都无法被猜路径直连下载；正规下载只能走签名 token 路由

---

## 8. 服务器配置要求（nginx）

nginx 下 `.htaccess` 无效，需在站点 server 块加一条规则，把包目录彻底对 Web 关闭：

```nginx
location ~* /at8-packages/ {
    deny all;
}
```

不配置此条时风险可控（文件名不可猜），但配置后才是完全体：**签名路由成为获取安装包的唯一途径**。

---

## 9. 扩展点（Filters）

| Filter | 用途 |
|---|---|
| `at8_license_server_products` | 注册可授权产品（slug → label + 密钥代码）。新增付费插件时在这里挂 |
| `at8_license_server_strip_www` | 是否把 `www.` 与裸域视为同一站点（默认 true） |
| `at8_license_server_trusted_proxies` | 可信代理网段（默认 Cloudflare 全段 + 127.0.0.1） |
| `at8_license_server_package_dir` | 更新包存储目录（默认 `uploads/at8-packages/`） |

---

## 10. 安全设计要点

- **密钥生成**：`random_int()` 逐字符生成（无模偏差），每次签发查重，唯一索引兜底
- **密钥存储**：库内只存 HMAC-SHA256（`wp_salt('auth')` 加盐），**无明文**；完整密钥仅签发时展示一次
- **签名**：HMAC-SHA256 + `hash_equals()` 恒定时间比较，防时序攻击；签名密钥取 `wp_salt('auth')`，源码与数据库中均不存在
- **下载链路四重校验**：token 签名有效 → 授权状态 active → 授权未过期 → 文件 sha256 一致；
  token 只有 30 分钟，且**下载前再验一次**，吊销不留窗口
- **SQL**：全部参数化（`prepare()` 的 `%i` / `%s` / `%d`，或 `insert/update/delete` API + 格式数组）
- **后台**：所有写操作 capability 检查 + 独立 nonce（按操作 + 对象 ID 细分）
- **拒绝的状态码**：权限拒绝返回 **403**，不是 500（500 会被监控当成故障）
- **上传**：zip 走 `wp_handle_upload` 标准校验（MIME 白名单），仅 `.zip` 可入
- **审计脱敏**：metadata 中字段名含 key / secret / token / password / hash / signature 的值一律丢弃

---

## 11. 卸载

**默认保留全部商业记录。** 后台「删除插件」时执行：

- 删除 option 与全部 `at8ls_` 前缀 transient
- 两张表与审计表**保留**——密钥与审计是商业承认的唯一凭据，客户说「我的密钥呢」时要查得到

确需连数据一起删除时，显式开启其一：

- 后台审计页的开关（`at8ls_purge_on_uninstall`），或
- `wp-config.php` 中 `define( 'AT8LS_PURGE_ON_UNINSTALL', true );`

`uploads/at8-packages/` 中的 zip 文件在任何情况下都保留在磁盘（防止误删唯一安装包），可手动清理。

多站点下逐站处理。

---

## 12. 更新记录

### 1.2.0

- **密钥不再明文落库**：`key_norm` 改存 HMAC-SHA256（`wp_salt('auth')` 加盐），
  `license_key` 清空，新增 `key_hint` 存末 5 位供展示；`Db::DB_VERSION` 2 → 3，
  历史明文行自动幂等迁移
- **完整密钥只在创建后显示一次**（用户级 transient，300 秒，读取即删），后台列表显示掩码
- 后台新增**审计日志**标签页；密钥状态变更、绑定解绑、被拒请求均留痕

### 1.0.1

- 内置简体中文翻译（授权管理界面）

### 1.0.0

- 初版：密钥签发 / 激活校验解绑 / 签名下载 / 版本发布管理

---

## 13. 已知待办

- [ ] 部署到 at8.fun 官网（当前仅在测试站 wordpress.xmm.fan 验证通过）
- [ ] 生产环境的包目录 nginx 禁访规则（见 §8）
- [ ] 后续支付回调（支付宝/微信 → 自动建 key → 发邮件）将挂在本插件的 REST 层；
      `Customer` / `Order` / `Subscription` / `Payment` 四个对象尚未建模，接支付前必须补
- [ ] 密钥改为一次性展示后，考虑补「签发即邮件发送给客户」的流程，减少人工转抄

---

## 14. 相关文档

| 文件 | 内容 |
|---|---|
| `docs/license-api.md` | 授权生命周期与数据流（含支付规划） |
| `docs/api.md` | REST 报文、状态码、限流、token 格式 |
| `docs/database.md` | 表结构、迁移规则、卸载策略 |
| `docs/privacy.md` | 隐私与数据留存 |
| `docs/architecture.md` | 三层架构与端到端链路 |
