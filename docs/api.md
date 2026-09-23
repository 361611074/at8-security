# API

REST 接口契约。命名空间 `at8/v1`（规范地址）与 `at8-license/v1`（兼容旧客户端，**不得下线**）。

所有写操作都是 `POST` + JSON body；没有任何接口靠 GET 改数据。

## 鉴权级别

| 路由 | 级别 | 说明 |
|---|---|---|
| `POST /activate` | Public + 限流 | 客户站点未登录，靠密钥本身鉴权；未知密钥严格限流 |
| `POST /validate` | License Authenticated | 同上限流；只回状态不新增绑定 |
| `POST /deactivate` | License Authenticated | 释放席位；密钥缺失时幂等返回成功 |
| `POST /update` | License Authenticated | 内部再验一次授权，被吊销的密钥拿不到下载链接 |
| `GET /package` | Signed Token | 签名 + 30 分钟有效期 + 授权再校验；URL 是 upgrader 唯一能带的东西 |

## 请求字段

| 字段 | 必填 | 说明 |
|---|---|---|
| `license` | activate / validate / update 必填 | 密钥，大小写与连字符差异不影响匹配 |
| `product` | 必填 | 产品 slug，如 `at8-security-pro` |
| `domain` | 二选一 | 站点域名；旧的字段名 |
| `site_url` | 二选一 | 完整站点 URL；规范的字段名 |
| `home_url` | 否 | home 与 site 不同时上报 |
| `wp` / `wp_version` | 否 | WordPress 版本（两种拼法都接受） |
| `php` / `php_version` | 否 | PHP 版本 |
| `environment` | 否 | `production` / `staging` / `development`，默认 production |

## 响应

统一形状：

```json
{
  "valid": true,
  "status": "active",
  "expires": 0,
  "plan": "lifetime",
  "message": "Licence active."
}
```

| `status` | HTTP | 含义 |
|---|---|---|
| `active` | 200 | 有效 |
| `rejected` | 200 | 密钥不存在 / 产品不匹配 / 席位已满 |
| `inactive` | 200 | 已停用 / 已暂停 / 已吊销 / 站点未绑定 / 无已发布构建 |
| `expired` | 200 | 已过期 |
| `unreachable` | 429 | 触发限流 |

`/update` 成功时额外返回：`version`、`download_url`、`homepage`、`requires`、`tested`、`requires_php`、`changelog`、`last_updated`。

## 限流

| 桶 | 上限 | 窗口 | 理由 |
|---|---|---|---|
| `rest`（已知密钥） | 600 | 10 分钟 | 同机房 / NAT 出口下大量正常站点共享 IP，必须宽松 |
| `guess`（未知密钥） | 30 | 10 分钟 | 拿不存在的密钥来问的只有猜密钥的人 |

IP 识别：直连 `REMOTE_ADDR`；仅当直连对端落在 Cloudflare 网段（或 127.0.0.1）时才采信 `CF-Connecting-IP`。**不读取 `X-Forwarded-For`。**

## 下载链接

```text
token = base64url(license_id|product|expires) + "." + HMAC-SHA256(payload, wp_salt('auth'))
```

- 有效期 **30 分钟**（规范 §8.1 的 10–30 分钟区间）
- 下载时重新校验：签名 → 授权仍为 active → 授权未过期 → 文件 sha256 与上传记录一致
- 安装包在 `uploads/at8-packages/`，文件名带 24 位随机串，只能通过本路由取得

## 扩展点（Filters）

| Filter | 用途 |
|---|---|
| `at8_license_server_products` | 注册可授权产品 |
| `at8_license_server_strip_www` | 是否把 www 与裸域视为同一站点 |
| `at8_license_server_trusted_proxies` | 可信代理网段 |
| `at8_license_server_package_dir` | 更新包目录 |

## 尚未实现（规范 §7 规划内）

`GET /account`、`GET /subscription`、`GET /features`、`GET /site` —— 等 Customer / Order / Subscription 建起来后补。
