# 隐私

规范 §15：任何外部服务都必须能回答「发什么 / 为什么 / 什么时候 / 存多久 / 谁能访问 / 怎么删」。

## 免费版 at8-security

**不收集、不发送、不连接任何外部服务器。**

- 无 `wp_remote_*`、无 `curl`、无统计、无遥测。
- 数据只存在本站数据库（设置、审计日志、封锁记录），卸载时清理。
- 后台的「升级到 Pro」是普通链接，点击才离开站点，不携带任何本站数据。

## 商业版 at8-security-pro

两个出站目标，都不是分析或追踪服务：

### 1. AT8 授权服务器（`https://www.at8.fun/wp-json/at8-license/v1/*`，同时支持 `at8/v1`）

| 项目 | 内容 |
|---|---|
| 何时 | 填入密钥点激活时；之后每天两次心跳；检查更新时；卸载/停用时解绑 |
| 发什么 | 授权密钥、站点域名与站点 URL、WordPress 版本、PHP 版本、运行环境（production/staging/development） |
| 服务端另存 | 调用来源 IP、最后在线时间 —— 用于排查「这个密钥被用在了哪个站」 |
| 为什么 | 这是授权本身：判断密钥是否有效、还有没有席位、是否该给更新包 |
| 不发送 | 文章内容、用户数据、表单提交内容、客户名单、任何站点业务数据 |
| 存多久 | 授权有效期内 + 之后（商业记录，见 `docs/database.md`）；审计日志默认 180 天 |
| 谁能访问 | 仅站点所有者本人（`manage_options`）；不对外共享、不出售 |
| 怎么删 | 后台解绑站点；删除密钥；站点侧点「解除授权」即可解绑 |

### 2. Cloudflare Turnstile（`https://challenges.cloudflare.com/turnstile/v0/siteverify`）

| 项目 | 内容 |
|---|---|
| 何时 | 前台表单提交时（且仅在管理员已配置 Turnstile 密钥、并启用该功能时） |
| 发什么 | Turnstile 挑战 token、来源 IP |
| 为什么 | 判定「真人提交」，挡机器人垃圾提交 |
| 不发送 | 表单内容本身 |
| 隐私政策 | https://www.cloudflare.com/privacypolicy/ |

该功能可关闭：不需要时在设置里关掉 Turnstile，插件不再发起该请求。

## 授权服务器 at8-license-server

运行在自家站点，**只接收、不发送**。它存的内容见上表与隐私段（`license-server/at8-license-server/readme.txt` 的 `== Privacy ==`）。

原则：每个字段都必须能对应到运营授权的必要动作；没有理由的字段不加。

## 变更规则

**新增任何出站请求或新增任何上报字段，必须同时更新：** 本文件 + 对应 `readme.txt` 的 `== External Services ==`（免费版若将来接入）。没更新就不允许合入（`AGENTS.md` 第 15 条）。
