# License API 与数据流

授权从签发到下载的完整链路，以及这条链路上每一份数据存在哪里、谁能看到。

REST 的**原始报文形状**（字段名、状态码、限流桶）见 `docs/api.md`；本文件讲的是**生命周期与数据流**。

---

## 角色

| 角色 | 位置 | 说明 |
|---|---|---|
| 卖家 | `at8.fun` 后台 | 签发密钥、绑定/解绑、吊销、发布版本 |
| 授权服务器 | `license-server/at8-license-server` | 唯一的授权判定方 |
| 客户站点 | 装了 `at8-security-pro` 的 WordPress | 只问「这个 key 对我这个站有效吗」 |
| 免费版 | 装了 `at8-security` 的 WordPress | **不参与授权**，零出站（见 `docs/privacy.md`） |

---

## 密钥形态与存储

### 形态

```text
AT8-SEC-9K3M2-7QP4X-2NBVH-5RTWY
└─┬─┘ └┬┘ └─────── 4 组 × 5 位 ───────┘
  │    └ 产品代号（SEC / PRO）
  └ 固定前缀
```

字符集为 Crockford Base32 去掉易混的 `I` `L` `O` `U`，用 `random_int()` 逐字符生成，
签发时查重，唯一索引兜底。

### 存储（1.2.0 起）

**数据库里不存明文。**

| 列 | 内容 |
|---|---|
| `license_key` | **恒为空串**（历史遗留列，保留是为了迁移与回滚） |
| `key_norm` | `hash_hmac( 'sha256', 归一化密钥, wp_salt('auth') )`，唯一索引，查找只走它 |
| `key_hint` | 密钥末 5 位，**仅供后台展示**，不足以反推密钥 |

**为什么用 HMAC-SHA256 而不是 `password_hash()`**：`password_hash()` 每次调用都换盐，
同一密钥两次哈希结果不同，**没法按密钥查库**。授权校验必须先定位到那一行，
所以需要「确定性 + 不可逆」——加盐 HMAC 正好。

**为什么加盐而不是裸 `sha256()`**：裸哈希可被彩虹表反查（密钥空间虽大但格式固定，
且卖家可能批量签发），加盐后哈希不能跨站比对。

**为什么留 `key_hint`**：后台列表要能让人认出「这条是哪把钥匙」。显示末 5 位即可，
完整密钥不可反推。

### 迁移（`Db::DB_VERSION` 2 → 3）

`Db::maybe_install()` 在 `install()` 之后调用 `migrate_keys()`：把历史上以明文存在的行
逐行哈希、写入 `key_hint`、**把 `license_key` 清空**。

- **幂等**：`license_key` 已为空的行跳过，重复执行不产生变化。
- **不 DROP、不改类型**：只加 `key_hint` 列（dbDelta 只做 ADD COLUMN）。
- **在持有真实授权的库上验过**：见 `docs/testing.md` 的「带数据的升级测试」。

### 完整密钥只在创建时出现一次

签发后密钥经**用户级 transient**（`at8ls_new_key_<user_id>`，TTL 300 秒）展示一次，
读取即删除。之后后台列表只显示掩码：

```text
AT8-SEC-•••••-•••••-•••••-9K3M2
```

**理由**：库里只有哈希，明文一旦离开这一屏就再也取不回来。所以「签发 → 立刻复制给客户」
是唯一窗口，界面必须把这个窗口说清楚，而不是让卖家以为「回头再来列表里抄」。

> 历史遗留：`key_hint` 为空的行（迁移前就已哈希、无法补尾）在列表里显示 `licence #<id>`。
> 这比编一个假尾段诚实。

---

## 生命周期

```text
1. 签发     卖家在 At8 Licences → 创建密钥
            ├─ random_int() 生成，查重
            ├─ 存 key_norm(HMAC) + key_hint，license_key 留空
            ├─ 密钥经用户级 transient 展示一次，读取即删
            └─ audit: license.created

2. 激活     客户在 Pro 设置页填入密钥
            ├─ POST /activate  {license, product, domain|site_url, wp_version, php_version, environment}
            ├─ 服务端：限流 → 按 key_hash 查库 → 状态/产品/有效期 → 席位
            ├─ 成功：写 sites 表 + audit: site.activated
            └─ 失败：audit: license.refused（记 reason，不记密钥）

3. 心跳     twicedaily POST /validate（不新增绑定，例行心跳不写审计）
            └─ 服务器不可达 → 客户端走宽限期，不关功能

4. 更新     POST /update
            ├─ 服务端重新验授权
            ├─ 只有 status=published 的构建才回版本信息
            └─ 回 download_url = /package?token=…

5. 下载     GET /package?token=…
            ├─ token = base64url(license_id|product|expires) + "." + HMAC-SHA256(wp_salt('auth'))
            ├─ 30 分钟内有效
            ├─ 再验：授权仍 active、未过期、文件 sha256 与上传记录一致
            └─ 以 application/zip 流式输出

6. 解绑     POST /deactivate → 删绑定 + audit: site.released
            卖家也可在后台解绑 → audit: site.removed
```

**撤销立即生效**：签名链接只有 30 分钟，且**下载前再验一次授权状态**，吊销不留窗口。

---

## 客户端 API（Pro 侧）

`At8\Security\Pro\Api`，全部走 `wp_remote_post()`，只在后台与计划任务里发起，**前台永不发请求**。

| 方法 | 目标 | 说明 |
|---|---|---|
| `Api::activate( $key )` | `POST /activate` | 激活并占用一个席位 |
| `Api::validate( $key )` | `POST /validate` | 心跳，只读状态 |
| `Api::deactivate( $key )` | `POST /deactivate` | 释放席位 |
| `Api::update( $key )` | `POST /update` | 取版本信息与签名下载链接 |

| 常量 / 过滤器 | 默认 | 用途 |
|---|---|---|
| `Api::DEFAULT_BASE` | `https://www.at8.fun` | 授权服务器地址 |
| `at8sec_pro_api_base` | 同上 | 改服务器地址（测试站 / 镜像） |
| `at8sec_pro_api_timeout` | `15` 秒（下限 5） | 单次请求超时 |
| `Api::ROUTE` | `/wp-json/at8-license/v1` | 旧命名空间，**不得下线** |

**为什么保留 `at8-license/v1`**：它是第一版客户端写死的地址，已装在客户站上。
服务端同时注册 `at8/v1`（平台统一地址）与 `at8-license/v1`（兼容），
这样服务器可以比客户站点先升级。见 `docs/architecture.md`。

---

## 数据流：谁存了什么

| 数据 | 存在哪 | 谁能看到 |
|---|---|---|
| 完整密钥（明文） | **不落库**；仅创建时那一屏 | 卖家（一次），客户（自己保存的那份） |
| 密钥哈希 | `{prefix}at8_licenses.key_norm` | 仅数据库 |
| 密钥尾段 | `{prefix}at8_licenses.key_hint` | 卖家后台列表 |
| 站点域名 / URL | `{prefix}at8_license_sites` | 卖家后台 |
| 站点环境、WP/PHP 版本 | 同上（心跳刷新） | 卖家后台 |
| 调用来源 IP、最后在线 | 同上 | 卖家后台 |
| 审计事件 | `{prefix}at8_audit_logs` | 卖家后台；**metadata 不含密钥**（`Audit::is_secret()` 丢弃 key/secret/token/password/hash/signature 字段） |
| 发布包 | `uploads/at8-packages/`，文件名 24 位随机串 | 只能经签名路由取得；Web Server 禁访 |

**不发送、不存储**：文章内容、用户数据、表单提交内容、客户名单、任何站点业务数据。

---

## 支付（尚未实现）

**当前没有接支付。** 规范 §5 / §10.3 规划中的对象尚未建模：

| 对象 | 状态 | 说明 |
|---|---|---|
| `Customer` | 未建模 | V1 用「邮箱 + 密钥」即可 |
| `Order` | **未建** | 接支付宝 / 微信前必须建 |
| `Subscription` | **未建** | 同上 |
| `Payment` | **未建** | 金额用最小货币单位整数（¥29.90 → `2990` + `currency=CNY`），不用浮点 |

**这件事不能由 Agent 自行决定**（规范 §92：不得擅自改变商业模式、支付金额、退款政策、License 期限）。
当前签发是**手工**的：卖家在后台建密钥，把密钥交给客户。接入支付前需要产品决策，见
`docs/database.md` 的待决项。

---

## 相关文件

| 文件 | 内容 |
|---|---|
| `docs/api.md` | REST 原始报文、状态码、限流桶、下载 token 格式 |
| `docs/architecture.md` | 端到端链路与设计理由 |
| `docs/database.md` | 表结构、迁移规则、卸载策略 |
| `docs/privacy.md` | 发到外部服务器的每一个字段及其理由 |
| `docs/security.md` | 密钥生成、比较、校验的做法 |
