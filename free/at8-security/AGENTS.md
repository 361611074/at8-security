# AGENTS.md — at8-security（免费版）

上架 WordPress.org 的免费安全插件。GPLv2 or later，必须能独立运行。

上级规则见 `../../AGENTS.md` 与 `docs/AT8-WordPress-商业产品通用架构规范-v2.0.md`。

## 这个插件特有的约束

1. **不连任何外部服务**。免费版不发 any outbound 请求（`grep -rn "wp_remote_" .` 必须为空）。Pro 的升级按钮只是普通链接。
2. **装上即可用**。任何功能都不得依赖注册、授权或网络。
3. **wp.org 合规**：`Requires PHP` 与 readme 同值且 ≥7.0；`Tags` ≤5 个且小写；不弹窗推销 Pro、不做恐吓式营销。
4. **slug 永久不可改**（`at8-security`，由 Plugin Name 生成）。
5. **模块化**：功能以 `Plugin::instance()->module( 'xxx' )` 注册，新增一个防护点 = 新增一个 module + 设置开关 + 一条测试，不要往已有类里堆。

## 目录

```text
at8-security/
├── at8-security.php      启动、常量、文本域加载
├── includes/             各防护模块（waf / hardening / login-protection / audit-log ...）
├── templates/            前台与邮件模板
├── assets/               admin.css / admin.js（零 !important）
├── languages/            zh_CN 翻译
└── uninstall.php         卸载清理（选项 + 自建表 + transient）
```

## 改这里之前先读

- `docs/security.md`（安全模型与自查清单）
- `docs/testing.md`（回归命令）
- `docs/privacy.md`（这个插件收什么、发什么——答案是：不收、不发）

## 交付自检

```bash
grep -rn "!important" assets/           # 必须 0
grep -rn "wp_remote_\|curl_" --include="*.php" .   # 必须 0
php -l at8-security.php
```
