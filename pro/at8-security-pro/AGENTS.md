# AGENTS.md — at8-security-pro（商业版）

自建分发的付费附加组件，依赖 `at8-security` 免费版，需要有效 License。

上级规则见 `../../AGENTS.md` 与 `docs/AT8-WordPress-商业产品通用架构规范-v2.0.md`。

## 这个插件特有的约束

1. **授权失效不等于网站失效**。服务器不可达走宽限期（`AT8SEC_PRO_GRACE_DAYS`）；密钥被明确拒绝走更短的宽限期（`AT8SEC_PRO_GRACE_DAYS_REJECTED`）。收益是安全防护，不能在授权抖动时把它关掉。
2. **不做持续在线校验**。心跳是 `twicedaily`，不是每次请求。
3. **免费版存在时才启用**。Pro 的功能必须优雅降级：免费版缺失时给管理员提示，不 fatal。
4. **合规分界**：高风险功能（2FA、WAF 增强、CDN、Turnstile、完整性校验、账号保护）在 Pro；免费版功能必须完整可用，不挖空。
5. **对外数据披露**：任何新增的出站请求都必须同步更新 `readme.txt` 的 `== External Services ==` 与 `docs/privacy.md`，否则不允许合入。

## 目录

```text
at8-security-pro/
├── at8-security-pro.php   启动、常量、文本域加载
├── includes/
│   ├── class-api.php        授权服务器客户端（唯一出站通道之一）
│   ├── class-license.php    授权状态 / 宽限期 / 心跳调度
│   ├── class-updater.php    注入 WP 原生更新
│   ├── class-two-factor.php 2FA
│   ├── class-turnstile.php  Cloudflare Turnstile（出站通道之二）
│   ├── class-cdn.php  class-integrity.php  class-accounts.php
└── assets/  languages/  uninstall.php
```

## 出站请求白名单（新增必须经确认）

| 目标 | 何时 | 发什么 |
|---|---|---|
| `https://www.at8.fun/wp-json/at8-license/v1/*`（并支持 `at8/v1`） | 激活、每天两次心跳、更新检查 | 密钥、站点域名/URL、WP 与 PHP 版本、环境 |
| `https://challenges.cloudflare.com/turnstile/v0/siteverify` | 前台表单提交 | Turnstile token、来源 IP |

## 交付自检

```bash
grep -rn "!important" assets/   # 0
php -l at8-security-pro.php
grep -n "External Services" readme.txt   # 有出站就必须有这段
```
