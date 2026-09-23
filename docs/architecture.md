# 架构

AT8 安全产品线的三层结构，以及三层之间只允许通过什么方式通信。

## 全景

```text
                    客户 / 买家
                        │
        ┌───────────────┴────────────────┐
        │                                │
   WordPress.org                    at8.fun（自家站点）
   免费版 at8-security               ┌──────────────────────────┐
   （无出站请求）                     │ at8-license-server       │
        │                            │  · License / Sites       │
        │  升级                       │  · Releases / Audit      │
        ▼                            │  · REST: at8/v1          │
   at8-security-pro  ──── 激活 ─────► │  （+ at8-license/v1）    │
   （需 License）      ── 心跳/更新 ─► │                          │
        │                            │  packages/ 签名下载       │
        │  按 WP 原生更新机制安装      └──────────────────────────┘
        └──────────── 下载 zip ◄──────────────┘
                        │
                   Cloudflare（代理 + Turnstile）
```

## 三层职责

### 产品层 — `free/` `pro/`

- 只做 WordPress 功能：后台 UI、前台逻辑、REST（对内的）、编辑器集成、本地数据、本地安全。
- **不知道**授权是怎么卖出去的，只问服务端「这个 key 对我这个站点有效吗」。
- 免费版零出站；Pro 两个出站目标：授权服务器、Turnstile。

### 平台层 — `license-server/`

| 概念 | 现状 |
|---|---|
| Customer | 未建模（V1 用邮箱 + 密钥即可，规格见规范 §5） |
| Order / Subscription / Payment | **未建**，规划中（支付宝 + 微信优先，Provider 抽象层） |
| License | 已建：`wp_at8_licenses` |
| Sites | 已建：`wp_at8_license_sites` |
| Update | 已建：`at8ls_releases` + 签名下载路由 |
| Audit | 已建：`wp_at8_audit_logs` |

### 基础设施层

GitHub（代码与 CI）、测试站 `wordpress.xmm.fan`、生产 `at8.fun`、CDN（Cloudflare）、支付平台（待接入）。产品代码不得绑定其中任何一个的具体实现。

## 授权链路（端到端）

```text
1. 签发    管理员在 At8 Licences → 创建密钥 → AT8-SEC-XXXXX-XXXXX-XXXXX-XXXXX
                              └─► audit: license.created

2. 激活    客户在 Pro 设置页填入密钥
            │
            ├─► POST /wp-json/at8/v1/activate {license, site_url, domain, product, wp_version, php_version, environment}
            │      ├─ 限流：已知密钥 600/10min，未知密钥 30/10min（按 IP）
            │      ├─ Keys::verify()
            │      │    ├─ 密钥存在？状态 active？产品匹配？未过期？
            │      │    ├─ 该站点是否已绑定？（已绑定 → 只更新 last_seen）
            │      │    └─ 席位未满？（max_sites=0 表示不限）
            │      └─ 回 {valid, status, expires, plan, message}
            │
            └─► 成功 → 写入 sites 表 + audit: site.activated
                失败 → audit: license.refused（含 reason，不含密钥）

3. 心跳    twicedaily：POST /validate（不新增绑定）
            免费版的宽限期逻辑在客户端：服务器不可达不关功能

4. 更新    Post /update ─► 服务端重新验链
                         ─► 只有 status=published 的构建才回版本信息
                         ─► 回 download_url = /wp-json/at8/v1/package?token=…

5. 下载    GET /package?token=…
            ├─ token = base64url(license_id|product|expires) . HMAC-SHA256(wp_salt('auth'))
            ├─ 过期？30 分钟内有效
            ├─ 授权仍 active？未过期？（吊销立即生效，不留窗口）
            ├─ 文件在？sha256 与上传时一致？
            └─ 以 application/zip 流式输出

6. 解绑    POST /deactivate → 删站点绑定 + audit: site.released
           管理员也可在后台解绑 → audit: site.removed
```

## 为什么这么设计

- **服务端必须先升级**：API 双命名空间、字段名双读，都是为了让服务器可以比客户站点先更新。
- **撤销必须立即生效**：签名链接只有 30 分钟，下载前再验一次授权状态，吊销不留窗口。
- **断网不关功能**：安全插件关掉防护比授权超期危险得多，所以走宽限期。
- **记录不可丢**：License 与审计日志是商业承认的唯一凭据，删除插件不默认删除它们。
