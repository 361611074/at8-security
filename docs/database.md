# 数据库

## 表清单（prefix 随站点，多站点各自一套）

### `{prefix}at8_licenses` — 授权记录

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | bigint unsigned PK | |
| `license_key` | varchar(64) | **恒为空串**（1.2.0 起）。历史遗留列，保留是为了迁移与回滚，不再写入 |
| `key_norm` | varchar(64) UNIQUE | **HMAC-SHA256(归一化密钥, `wp_salt('auth')`)**，查找只走它。不再存明文或裸哈希 |
| `key_hint` | varchar(12) | 密钥末 5 位，**仅供后台展示**，不足以反推密钥。v3 新增 |
| `product` | varchar(64) KEY | 产品 slug |
| `plan` | varchar(32) | `lifetime` / 未来 `yearly` 等 |
| `status` | varchar(20) KEY | `active` / `disabled` / `suspended` / `revoked` |
| `expires` | bigint unsigned | 到期 Unix 时间，0 = 终身 |
| `max_sites` | int | 允许绑定站点数，0 = 不限 |
| `note` | varchar(255) | 备注（客户标识、订单号） |
| `created_at` / `created_by` | bigint unsigned | |
| `updated_at` | bigint unsigned | v2 新增 |

**密钥存储（1.2.0 / DB v3）**：库里**不存明文**。完整密钥只在创建时经用户级 transient
（`at8ls_new_key_<user_id>`，TTL 300 秒）展示一次，读取即删；之后后台只显示掩码
`AT8-SEC-•••••-•••••-•••••-9K3M2`。

**为什么用 HMAC 而不是 `password_hash()`**：`password_hash()` 每次换盐，同一密钥两次哈希不同，
**没法按密钥查库**。授权校验必须先定位到那一行，所以需要「确定性 + 不可逆」。

**v2 → v3 迁移**：`Db::maybe_install()` 在 `install()` 之后调用 `migrate_keys()`，
把历史上以明文存在的行逐行哈希、写入 `key_hint`、把 `license_key` 清空。
**幂等**（已为空的行跳过），**不 DROP、不改类型**（只加 `key_hint` 列）。详见 `docs/license-api.md`。

### `{prefix}at8_license_sites` — 站点绑定

| 列 | 说明 |
|---|---|
| `id` | PK |
| `license_id` | 所属授权 |
| `domain` | 归一化域名（去 www / 协议 / 尾斜杠） |
| `site_url` / `home_url` | v2 新增，完整 URL |
| `site_hash` | v2 新增，域名 md5，为将来「授权跟站还是跟域名」留余地 |
| `environment` | v2 新增，`production` / `staging` / `development` |
| `activated_at` / `last_seen` / `last_ip` | |
| `wp_version` / `php_version` | 心跳时刷新 |
| 索引 | UNIQUE(`license_id`,`domain`)、KEY(`license_id`)、KEY(`environment`) |

### `{prefix}at8_audit_logs` — 审计日志（v2 新增）

| 列 | 说明 |
|---|---|
| `id` | PK |
| `actor_id` | 管理员 user id，0 = 非后台发起（客户端调用） |
| `actor_ip` | 来源 IP |
| `action` | 点分事件名，见下表 |
| `object_type` / `object_id` | `license` / `release` / `setting` |
| `metadata` | JSON，**仅标量且不含密钥**（`Audit::is_secret()` 丢弃 key/secret/token/password/hash/signature 字段） |
| `created_at` | 索引，按保留期裁剪 |

**事件名**：`license.created` / `license.active` / `license.disabled` / `license.suspended` / `license.revoked` / `license.sites_changed` / `license.note_changed` / `license.deleted` / `license.refused`（带 reason：unknown_key、expired、seat_limit、wrong_product、not_bound、revoked/suspended/disabled）/ `site.activated` / `site.released` / `site.removed` / `release.draft|testing|published|deprecated|withdrawn` / `settings.changed` / `settings.audit_cleared`。

**写入策略**：只在「有决策价值」时写 —— 新建、状态变更、绑定/解绑、被拒。**例行心跳不写**（每天两次 × 全部站点，会淹没日志）。

## Options

| 名称 | 说明 |
|---|---|
| `at8ls_db_version` | 迁移依据 |
| `at8ls_releases` | 各产品已发布版本的元数据 |
| `at8ls_audit_days` | 审计保留天数（30/90/180/365，默认 180） |
| `at8ls_purge_on_uninstall` | 卸载是否删除商业记录（默认 0） |
| `at8_license_server_settings` | 预留 |

## 迁移规则

1. 加列 / 加表 → 改 `Db::install()` 的 CREATE 语句 + **递增 `Db::DB_VERSION`**，由 dbDelta 增量执行。
2. **禁止** `DROP TABLE` / 改列名 / 改类型（会丢真实授权数据）。真要重命名 = 加新列 + 回填 + 保留旧列一个版本。
3. 测试升级路径必须用**持有真实授权的库**（或它的副本），不能只在空库上跑。
4. `Db::ready()` 缺表时后台给「重建数据表」按钮（`Db::install()`），不用重装插件。

## 卸载策略（规范 §28）

| 动作 | 行为 |
|---|---|
| Deactivate | 什么都不删。授权、站点、审计全留 |
| Delete（卸载） | **默认保留三张表**；只清 transient 与 cron。删商业记录需显式开启：审计页开关，或 `wp-config.php` 定义 `AT8LS_PURGE_ON_UNINSTALL = true` |
| 免费版 / Pro 卸载 | 各自清理自己的网站本地数据（设置、审计、封锁记录） |

理由：删插件是日常动作（重装、拆 staging），商业记录一旦被顺手删掉，客户说「我的密钥呢」时无从查证。

## 待决项（需产品决策，未经确认不实施）

| 项 | 规范条款 | 影响 |
|---|---|---|
| 建 `at8_customers` / `at8_orders` / `at8_subscriptions` / `at8_payments` | §5 / §10.3 | 接入支付宝/微信前必须建，且金额用最小货币单位整数（¥29.90 → 2990 + `currency=CNY`） |
| `at8_audit_logs` 表名前缀统一为 `at8_`（现为 `wp_` + `at8_audit_logs` = `{prefix}at8_audit_logs`） | §11 | 已符合 |

**已实施**（原待决项，1.2.0 完成）：密钥改为 HMAC 存储、后台只显示部分、签发时一次性展示。
当前 `Db::DB_VERSION = 3`。
