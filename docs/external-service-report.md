# 外部服务清单与审计报告

规范 §8 / §15 / §93 / §99。回答一个问题：**这三个插件往外发了什么。**

结论先行：**免费版一个字节都不发。** Pro 发两个目标，都已在 readme 与隐私文档里逐字段声明。

> **范围说明（2026-10-11）**：本报告同时覆盖 `at8-security-pro` 与 `at8-license-server`。
> 这两个产品已迁至各自独立仓库（`at8-security-pro`、`at8-license-server-v2`），本仓库只保留免费版
> `at8-security`；报告中对它们的记述作为**历史审计记录**保留，其代码与后续变更以各自仓库为准。

---

## 总览

| 插件 | 出站目标 | 数量 | 是否上架 wp.org |
|---|---|---|---|
| `at8-security`（免费版） | **无** | 0 | 是 |
| `at8-security-pro` | AT8 授权服务器、Cloudflare Turnstile | 2 | 否 |
| `at8-license-server` | **无**（只接收） | 0 | 否 |

---

## 免费版：零出站

规范 §8 明令禁止免费版无授权自动请求 `at8.fun` / license API / 统计服务器 / 用户行为服务器。

**核查结果：全部为 0。**

| 检查 | 命令 | 结果 |
|---|---|---|
| HTTP 客户端 | `grep -rn "wp_remote_\|curl_\|fsockopen" --include="*.php" free/` | **0 命中** |
| 外链资源 | 分发包内无任何远程 `<script>` / `<link>` / 字体 / 图片引用 | 0 |
| 遥测 / 统计 | 无相关代码 | 0 |
| 账号体系 | 无 | 0 |

**「升级到 Pro」链接**：是普通 `<a href>`，点击才离开站点，**不携带本站任何数据**，
且 URL 经 `at8sec_pro_url` 过滤器可改。它不是请求，是用户主动导航。

**人机校验在本地**：免费版用算术题，题目由本站生成、答案存在本站 transient 里校验，
不加载任何第三方脚本。这是与 Pro 的 Turnstile 的关键区别，也是免费版能上架的前提之一。

**readme 声明**：`free/at8-security/readme.txt` 的 `== External services ==` 段写明
「None. This plugin does not connect to any external service」。
按 wp.org 惯例，无外部服务时该段可省略；这里保留是为了把结论写死，避免以后被误读。

---

## Pro：两个出站目标

### 1. AT8 授权服务器

| 项目 | 内容 |
|---|---|
| 端点 | `https://www.at8.fun/wp-json/at8-license/v1/*`（同时注册 `at8/v1`） |
| 代码位置 | `pro/at8-security-pro/includes/class-api.php` |
| 调用方法 | `Api::activate()` / `validate()` / `deactivate()` / `update()` |
| 何时 | 填密钥点激活时；之后每天两次心跳；检查更新时；解绑时 |
| 发起位置 | **仅后台与计划任务**，前台永不发起 |

**发送字段**（逐个）：

| 字段 | 内容 | 理由 |
|---|---|---|
| `license` | 授权密钥 | 这是授权本身 |
| `product` | `at8-security-pro` | 判定密钥属于哪个产品 |
| `domain` / `site_url` | 站点域名 / 完整 URL | 席位绑定：一把密钥能用几个站 |
| `home_url` | home 与 site 不同时上报 | 同上 |
| `wp_version` | WordPress 版本 | 判断兼容性与更新包 |
| `php_version` | PHP 版本 | 同上 |
| `environment` | `production` / `staging` / `development` | 避免 staging 站占用生产席位 |

**不发送**：文章内容、用户数据、表单提交内容、客户名单、任何站点业务数据。

**可改地址**：`at8sec_pro_api_base` 过滤器、`at8sec_pro_api_timeout` 过滤器。

### 2. Cloudflare Turnstile

| 项目 | 内容 |
|---|---|
| 端点 | 校验 `https://challenges.cloudflare.com/turnstile/v0/siteverify`；脚本 `.../turnstile/v0/api.js` |
| 代码位置 | `pro/at8-security-pro/includes/class-turnstile.php` |
| 何时 | 前台表单提交时；**仅在管理员已配置密钥并启用该功能时** |
| 发送 | Turnstile 挑战 token、来源 IP |
| 不发 | 表单内容本身 |
| 隐私政策 | https://www.cloudflare.com/privacypolicy/ |

**可关闭**：不需要时在设置里关掉 Turnstile，插件不再加载脚本、不再发起校验请求。

---

## 授权服务器：只接收

`at8-license-server` 运行在自家站点，**没有任何出站请求**（`grep` 0 命中）。
它存的内容与理由见 `license-server/at8-license-server/readme.txt` 的 `== Privacy ==` 段
与 `docs/privacy.md`。

---

## 声明一致性核对

规范 §15：新增任何出站请求或上报字段，必须同时更新隐私文档与 readme。

| 出站 | `docs/privacy.md` | readme | 一致 |
|---|---|---|---|
| 免费版：无 | ✅ 已声明 | ✅ `== External services ==` | ✅ |
| Pro → 授权服务器 | ✅ 逐字段表 | ✅ `== External Services ==` → `= AT8 licence server =` | ✅ |
| Pro → Turnstile | ✅ 逐字段表 | ✅ `== External Services ==` → `= Cloudflare Turnstile =` | ✅ |
| license-server：只接收 | ✅ | ✅ `== Privacy ==` | ✅ |

---

## 上架风险判定

| 风险 | 判定 | 依据 |
|---|---|---|
| Free 偷偷连自家服务器 | **PASS** | 0 出站，可复核 |
| Free 含遥测 / 统计 | **PASS** | 无相关代码 |
| Free 无授权即发送数据 | **PASS** | 无发送行为 |
| Pro 的发送未声明 | **PASS** | readme + privacy.md 逐字段声明 |
| 远程可执行代码（远程加载 JS 并执行） | **PASS** | Free 无远程加载；Pro 的 Turnstile 脚本是 Cloudflare 官方校验控件，且可关闭 |
| 第三方 CDN 静默代理 | **PASS** | 无 |

**结论：PASS。** 免费版符合规范 §8 的全部要求。
