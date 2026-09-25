# AGENTS.md — AT8 安全产品线

本仓库是 AT8 商业 WordPress 产品的第一个产品线：`at8-security`（免费版，上架 WordPress.org）、
`at8-security-pro`（商业附加组件，自建分发）、`at8-license-server`（授权与更新服务端，只跑在自家站点）。

**基线规范**：`docs/AT8-WordPress-商业产品通用架构规范-v2.0.md`。本文件是它的落地版；冲突时以规范为准，并把差异写进本文件。

---

## 1. 项目结构

```text
安全防御插件/
├── AGENTS.md                     ← 本文件
├── README.md  CHANGELOG.md
├── THIRD-PARTY-LICENSES.md       第三方依赖登记表（当前：0 个依赖）
├── at8sec-build-zips.py          构建 + §65 六项 zip 审计（在仓库根，路径自推导）
├── docs/                         架构 / API / 边界 / 扩展 API / 安全 / 隐私 / 数据库 / 测试 / 发布 / wp.org
│   ├── AT8-WordPress-商业产品通用架构规范-v2.0.md
│   ├── architecture.md  free-pro-boundary.md  free-extension-api.md
│   ├── license-api.md   api.md  security.md   privacy.md
│   ├── database.md      release.md  testing.md  wordpress-org.md
│   └── *-report.md              上架合规 / 安全审计 / 边界验证 / 第三方 / 外部服务 / 发布
├── free/at8-security/            免费版：上架 wp.org，GPLv2，可独立运行
├── pro/at8-security-pro/         商业版：依赖免费版，需 License
├── license-server/at8-license-server/   授权服务器：只在 at8.fun 运行
└── dist/                         构建产物（不入 Git）
```

三层对应：
- **产品层** = `free/` + `pro/`
- **平台层** = `license-server/`（Customer / License / Sites / Update；Order / Subscription / Payment 待建）
- **基础设施层** = GitHub、测试站、CDN、支付平台

---

## 2. 硬性规则（每条都可被验证）

1. **改前先读架构**：`docs/architecture.md` 与要改的文件本身，不要凭印象改。
2. **不得擅自改数据库结构**。改动必须走 `Db::DB_VERSION` 递增 + dbDelta 迁移，且先说明影响、经人类确认。
3. **不得删除现有功能**，不得注释掉检查来"修"问题。
4. **不得引入未经批准的第三方依赖**；引入必须登记 `docs/release.md` 的依赖表（package/version/license/source/purpose）。
5. **不得把 API Key、License 明文、支付密钥写进源码或提交 Git**。密钥走 `wp-config.php` 常量或选项，日志脱敏。
6. **不得关闭安全检查**（WAF、nonce、capability、限流、签名校验）。
7. **所有输入 sanitize / validate**；**所有输出 escape**（按上下文选 `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`）。
8. **数据库一律 `$wpdb->prepare()`**，表名用 `%i` 标识符占位符。
9. **后台操作必须 capability + nonce**；REST 必须有明确权限策略 + 限流 + 错误信息最小化。
10. **改完必须跑测试**（`docs/testing.md`）；**失败的测试不得假装通过**，不得删断言让它变绿。
11. **不得为修一个小问题重写整个项目**——增量修复，保持架构稳定。
12. **架构级改动先说明影响**（涉及数据、License、支付、权限、外部服务、用户数据、安全策略、删除数据、Breaking Change 时，必须经人类确认）。
13. **不得删除审计日志**（`wp_at8_audit_logs` 与免费版的审计表）。它是商业记录，随插件删除也不默认清空。
14. **不得绕过 License / Subscription 业务规则**（免费版不得因授权失效而整体失效；Pro 失效走宽限期）。
15. **不得收集未批准的用户数据**。发到外部服务器的每一个字段都必须能在 `docs/privacy.md` 里找到理由。
16. **不得把秘密写入 Git**。
17. **发布前必须完成 `docs/release.md` 的 Release Checklist**。
18. **CSS 禁止 `!important`**（用户铁律）；改完 `grep -n "!important"` 自查为 0。
19. **文案面向最终用户**：不出现开发者黑话、第三方插件名、"后续会做"这类虚词。
20. **署名统一**：`Author: 漫步白月光`、官网 `https://www.at8.fun/`。
21. **不得删掉主文件里的 `load_plugin_textdomain()`**。Plugin Check 会对它报一条建议性 WARNING，
    但实测（WordPress 7.1.2）WordPress **不会**自动加载插件自带的 `/languages` 目录——
    插件自己的语言目录之所以被搜索，只是因为该调用把它注册进了 `WP_Textdomain_Registry` 的
    `custom_paths`。删掉它会让界面在「语言包尚未生成」的站点上退回英文。
    判断依据已写进代码注释与 `docs/wordpress-org-compliance-report.md`。
22. **文档与代码同步**：改了行为就改对应的 `docs/*.md`；`docs/*-report.md` 是审计记录，
    结论变了要重跑并更新，不允许留一份过期的 PASS。

---

## 3. 关键不变量（改这些之前先停下来）

| 不变量 | 说明 |
|---|---|
| 免费版可独立运行 | 免费版不连 AT8 服务器，无 outbound 请求；装上→激活→可用 |
| Pro 失效不致命站点 | 服务器不可达走宽限期（`AT8SEC_PRO_GRACE_DAYS`），不是关功能 |
| 授权判断只认服务端回包 | 已吊销的 key 拿不到下载链接，签名链接 30 分钟过期 |
| 下载只能走签名路由 | `uploads/at8-packages/` 文件名随机 + Web Server 禁访 + sha256 校验 |
| 审计日志不含密钥 | 只记 license id + 事件 + 主体 + 来源 IP |
| 卸载不毁商业记录 | license-server 默认保留表；显式开启或 `AT8LS_PURGE_ON_UNINSTALL` 才删 |
| **库内不存密钥明文** | `key_norm` 存 HMAC-SHA256（`wp_salt('auth')` 加盐），`license_key` 恒为空串，`key_hint` 只存末 5 位；完整密钥仅签发时展示一次 |
| **免费功能不挂 `is_pro()`** | 该过滤器只决定「是否显示 Pro 入口」，不得用来开关任何免费功能 |

---

## 4. 常用命令

```bash
# 语法检查（在测试站跑；本地 phpstudy 是 PHP 7.3.4，低于插件下限 7.4，不可用）
php -l /www/wwwroot/wordpress.xmm.fan/wp-content/plugins/at8-license-server/includes/class-keys.php

# 构建分发包（在仓库根执行；脚本自带 §65 六项审计，失败即非零退出）
python at8sec-build-zips.py

# 对发行 zip 用目标 PHP 逐文件 lint
python at8sec-lint-zips.py
# 在测试站对发行 zip 跑官方 Plugin Check
python at8sec-plugin-check.py

# 测试（工作区内脚本，需测试站 SSH 配置 ~/.workbuddy/wpv.json）
python at8ls-deploy.py        # 38/38  授权链路（会先部署授权服务器）
python at8ls-spec.py          # 97/97  规范符合性
python at8ls-publish.py       # 73/73  发布/下载/更新下发
python at8sec-attack.py       # 30/30  对抗测试
python at8sec-pro-http.py     # Pro 端到端

# 单文件推送到测试站 + 服务器端 php -l（自动映射掉 free/ pro/ license-server/ 分组层）
python at8sec-push.py free/at8-security/at8-security.php
# 跑一次性探针
python at8ls-run.py at8ls-tests/i18n-probe.php

# CSS 自查
grep -rn "!important" free pro license-server --include="*.css"
```

---

## 5. 交付格式

- 告诉用户要覆盖哪些文件时：**只列文件名**，一行一个，附一句说明（必传/建议传）。
- 工作汇报用表格；结论先行。
- 对外可见的动作（发帖、评论、邮件）**只起草，不发布**。
