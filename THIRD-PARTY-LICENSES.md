# Third-Party Licenses

本文件登记 AT8 安全产品线三个插件里**所有非自研代码**的授权情况。

规范 §7 要求逐项记录 `Library / Version / License / Source / Used Files / GPL Compatibility`。
规范同时规定：**Free ZIP 中不能包含许可证不明的第三方代码。**

---

## 结论

**没有第三方代码。**

三个分发包（`at8-security` / `at8-security-pro` / `at8-license-server`）里的每一个文件都是自研的，
或由本项目自己的工具生成。

| Library | Version | License | Source | Used Files | GPL Compatibility |
|---|---|---|---|---|---|
| — | — | — | — | — | — |

---

## 核查方式

不是靠声明，是逐项查过的：

| 类别 | 检查 | 结果 |
|---|---|---|
| composer packages | 仓库内无 `composer.json` / `composer.lock` / `vendor/` | 0 |
| npm packages | 仓库内无 `package.json` / `package-lock.json` / `node_modules/` | 0 |
| JS libraries | 分发包内仅 `assets/admin.js`（1.7KB，手写 ES5，无 jQuery、无框架依赖） | 0 |
| CSS libraries | 仅 `assets/admin.css` / `assets/front.css`，手写，无框架 | 0 |
| fonts | 全仓库无 `*.woff*` / `*.ttf` / `*.eot` | 0 |
| icons | 全仓库无 `*.svg` / `*.ico` | 0 |
| images | 全仓库无 `*.png` / `*.jpg` / `*.jpeg` / `*.gif` | 0 |
| minified / vendored | 无 `*.min.js` / `*.min.css`，无 `jquery` / `bootstrap` / `vendor` 命名文件 | 0 |

复核命令：

```bash
find . -type f \( -iname "*.png" -o -iname "*.jpg" -o -iname "*.svg" -o -iname "*.woff*" \
  -o -iname "*.ttf" -o -iname "*.min.js" -o -iname "*.min.css" \) -not -path "./.git/*"
# 输出为空
```

分发包内文件清单也可直接核对（`unzip -l dist/at8-security-2.0.3.zip`）：
29 个条目全部是 `*.php` / `*.css` / `*.js`（自研）/ `*.po` / `*.mo` / `readme.txt`。

---

## 间接依赖：WordPress 自带资源

以下不是「打包进来的第三方代码」，而是运行时由 WordPress 提供的平台能力。列出是为了说清
「为什么这些不算第三方依赖」，避免审计时被当成漏登记。

| 资源 | 来源 | 说明 |
|---|---|---|
| jQuery / underscore / wp-util 等 | WordPress 核心 | **本项目不使用**。`admin.js` 刻意写成无依赖的 ES5 |
| Dashicons | WordPress 核心 | 后台图标，未打包任何图标字体 |
| `wp_remote_post()` | WordPress 核心 | HTTP 客户端，未打包 cURL 封装 |
| `dbDelta()` | WordPress 核心 | 建表 / 迁移，未打包迁移库 |

---

## 工具链（不进分发包）

| 工具 | 授权 | 用途 | 是否进包 |
|---|---|---|---|
| Python 3（标准库 + `paramiko`） | PSF / LGPL-2.1 | 构建脚本、测试脚本、SFTP 部署 | 否，仅开发机 |
| WordPress Plugin Check | GPLv2+ | 提交前体检 | 否，仅测试站 |
| `msgfmt` / `msgcat` | GPLv3+ | 编译 `.mo` 语言包 | 否，仅构建时 |

工具链产物（`.mo` 文件）是二进制数据，不含工具本身的代码。

---

## 商标与图片

| 项目 | 说明 |
|---|---|
| 插件名 | `At8 Security`，自研名称 |
| 作者署名 | `漫步白月光` / `https://www.at8.fun/` |
| 图片素材 | **无**。分发包不含任何图片（见上表） |
| 字体 | 使用系统字体栈与 WordPress 后台自带字体，未打包字体文件 |
| 第三方商标 | 未使用任何第三方商标 |

---

## 引入新依赖的规则

引入任何库之前必须：

1. 确认授权与 GPLv2+ 兼容（MIT / BSD / Apache-2.0 / ISC 可以；GPL 兼容条款要逐条看；
   **不得引入** 无授权声明、CC-BY-NC、SSPL、Commons Clause 等）；
2. 查安全公告（`composer audit` / `npm audit` / 对应项目 CVE）；
3. 确认与 PHP 8.2 / 8.3 / 8.4 兼容；
4. 在 `docs/release.md` 的依赖表与**本文件**同时登记；
5. 若进分发包，确认 Free 包内不含它（Free 包只允许自研代码与 GPL 兼容代码）。

未登记即不允许合入（`AGENTS.md` 第 4 条）。

---

## 相关文件

| 文件 | 内容 |
|---|---|
| `docs/third-party-license-report.md` | 本次审计的实测记录与结论 |
| `docs/release.md` | 第三方依赖登记表（构建与发布流程内） |
| `docs/wordpress-org-compliance-report.md` | 上架合规结论 |
