# WordPress.org 上架

只针对 `at8-security`（免费版）。Pro 与 license-server 不上架。

## 账号与署名

- wp.org 账号：**`x361611074`**（readme 的 `Contributors` 一律填这个，不留占位符）
- 署名：`Author: 漫步白月光`，`Author URI: https://www.at8.fun/`
- ⚠️ 官方校验器**禁止 `Plugin URI` 与 `Author URI` 同值**（`your_plugin_and_author_uris_are_the_same`）。上架包 `Plugin URI` 留空或指向插件专属页
- slug 由 `Plugin Name` 生成，**审核通过后永久不可改** → `Plugin Name` 必须纯 ASCII

## 提交前核对

- [ ] 插件名 / Header 完整
- [ ] `Requires at least` / `Requires PHP` / `Tested up to` / `Stable tag`
- [ ] **`Requires PHP` ≥ 7.0 且与 readme 同值**（低于 7.0 会被退回；推荐 7.4 = WordPress 自身下限）
- [ ] `License: GPLv2 or later` + `License URI`
- [ ] `Tags` ≤ 5 个且全小写（超出校验器报 `readme_parser_warnings_too_many_tags`）
- [ ] `readme.txt` 含 Description / Installation / FAQ / Changelog
- [ ] 截图（`assets/screenshot-N.png`）、icon（`icon-128x128.png`）、banner（`banner-772x250.png`）
- [ ] Changelog 与本次版本对应
- [ ] Privacy 段（免费版声明不外连）
- [ ] External Services 段（**当前免费版不连任何服务，无需该段**；将来若接入必须补）
- [ ] 第三方依赖与商标 / 图片 / 字体 / Icon 授权
- [ ] 包内无 `.backups`、无 `dist`、顶层单目录、zip 条目正斜杠
- [ ] ZIP ≤ 10MB

## 提交

入口 `https://wordpress.org/plugins/developers/add/`。审核口径 1–10 天，实际常 1–4 周，**不可加急**。被退回直接在审核邮件里回复，不重新提交。

## 已知会被点名的高频问题（首次实测记录）

1. **`Requires PHP` 写 5.6** → 退回。改成 ≥7.0 且两处同值。
2. **在加载阶段枚举 post type / taxonomy 注册列表钩子** → 主题与插件通常在 `init` 注册 CPT，`plugins_loaded` 时它们还不存在，自定义类型永远拿不到列表列。**正确做法**：在 `current_screen` 里按当前屏幕的实际对象类型（`upload` / `edit` / `edit-tags` / `users`）挂钩子。
   （核心事实：`wp-admin/admin.php` 的 `do_action('admin_init')` 在 `set_current_screen()` **之前**，所以 `current_screen` 晚于 `admin_init`；在 `admin_init` 里 `get_current_screen()` 拿到 null。）
3. 审核回信要**简短克制、不罗列改动**；想改 permalink 必须在正文明确说明，否则永久不可改。

## 通过之后

- SVN：`https://plugins.svn.wordpress.org/at8-security/`（用户 `x361611074`，区分大小写，不能用邮箱）
- 结构：`/trunk`、`/tags/<版本>`、`/assets`；发新版 = 改 trunk 的 `Version` + readme 的 `Stable tag` 再 commit
- readme.txt 用**英文**；PHP 头部 `Description` 可留中文（只影响后台插件列表）

## 工具

自建 skill `wp-org-plugin-submission`（`~/.workbuddy/skills/wp-org-plugin-submission/`），含提交前体检：

```bash
python ~/.workbuddy/skills/wp-org-plugin-submission/scripts/check_plugin.py "<插件目录或 zip>"
```

**任何插件上架前先跑体检。**
