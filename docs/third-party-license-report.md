# 第三方依赖审计报告

规范 §7 / §99 / §101 第 4 项（第三方依赖清单）。

**审计对象**：`at8-security` 2.0.3、`at8-security-pro` 2.0.3、`at8-license-server` 1.2.0
**审计日期**：2026-09-25
**审计方式**：逐文件扫描分发包内容 + 全仓库资产扫描

---

## 结论

```text
第三方依赖数量：0
```

三个分发包内**没有一行非自研代码**，也没有许可证不明的内容。
登记表见仓库根 `THIRD-PARTY-LICENSES.md`。

---

## 分发包内容实测

### `at8-security-2.0.3.zip` — 29 个条目

| 类型 | 数量 | 说明 |
|---|---|---|
| `*.php` | 21 | 自研（含 5 个 `index.php` 空目录守卫） |
| `*.css` | 2 | `admin.css`、`front.css`，手写 |
| `*.js` | 1 | `admin.js`，1.7KB，手写 ES5，**无 jQuery、无框架** |
| `*.po` / `*.mo` | 2 | 本项目自己的翻译 |
| `readme.txt` | 1 | 本项目文档 |
| 图片 / 字体 / 图标 | **0** | — |

### `at8-security-pro-2.0.3.zip` — 23 个条目

| 类型 | 数量 |
|---|---|
| `*.php` | 18（自研） |
| `*.css` | 2（手写） |
| `*.po` / `*.mo` | 2 |
| `readme.txt` | 1 |
| 图片 / 字体 / 图标 / JS 库 | **0** |

### `at8-license-server-1.2.0.zip` — 18 个条目

| 类型 | 数量 |
|---|---|
| `*.php` | 14（自研） |
| `*.css` | 1（手写） |
| `*.po` / `*.mo` | 2 |
| `readme.txt` | 1 |
| 图片 / 字体 / 图标 / JS 库 | **0** |

---

## 分类核查

规范 §7 列出的每一类都单独查过：

| 类别 | 检查 | 命中 |
|---|---|---|
| composer packages | `composer.json` / `composer.lock` / `vendor/` | 0 |
| npm packages | `package.json` / `package-lock.json` / `node_modules/` | 0 |
| JS libraries | `*.min.js` / `jquery*` / `bootstrap*` | 0 |
| CSS libraries | `*.min.css` / 框架样式表 | 0 |
| fonts | `*.woff` / `*.woff2` / `*.ttf` / `*.eot` | 0 |
| icons | `*.svg` / `*.ico` / 图标字体 | 0 |
| images | `*.png` / `*.jpg` / `*.jpeg` / `*.gif` | 0 |

复核命令（在仓库根执行，输出为空即为通过）：

```bash
find . -type f \( -iname "*.png" -o -iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.gif" \
  -o -iname "*.svg" -o -iname "*.ico" -o -iname "*.woff*" -o -iname "*.ttf" -o -iname "*.eot" \
  -o -iname "*.min.js" -o -iname "*.min.css" \) -not -path "./.git/*"
```

---

## GPL 兼容性

| 内容 | 授权 | 与 GPLv2+ 兼容 |
|---|---|---|
| 全部 PHP / CSS / JS | 本项目自研，随插件以 GPLv2 or later 发布 | ✅（本身即 GPL） |
| 翻译文件（`.po` / `.mo`） | 本项目自研 | ✅ |
| WordPress 核心 API 调用 | 不复制代码，仅调用 | ✅（无需登记） |

**Free ZIP 中不含许可证不明的第三方代码** —— 规范 §7 的硬性要求，**PASS**。

---

## 工具链（不进分发包）

| 工具 | 授权 | 用途 |
|---|---|---|
| Python 3 标准库 | PSF License | 构建与测试脚本 |
| `paramiko` | LGPL-2.1 | 测试站 SFTP 部署 |
| WordPress Plugin Check | GPLv2+ | 提交前体检 |
| `msgfmt` / `msgcat` | GPLv3+ | 编译 `.mo` |

以上只存在于开发机与测试站，**不进任何分发包**。它们的产物（`.mo`）是二进制数据，
不含工具代码。

---

## 风险清单

| 风险 | 判定 | 说明 |
|---|---|---|
| 含无授权声明的代码 | **无风险** | 无第三方代码 |
| 含 GPL 不兼容的库 | **无风险** | 无第三方代码 |
| 含被 CVE 影响的依赖 | **无风险** | 无第三方依赖 |
| 字体 / 图标 / 图片授权不明 | **无风险** | 分发包不含任何图片与字体 |
| 第三方商标 | **无风险** | 未使用任何第三方商标 |
| 语言包工具链污染 | **无风险** | 工具不进包，产物为二进制 `.mo` |

---

## 维护规则

引入任何库前必须完成 `THIRD-PARTY-LICENSES.md` 里的五步（授权兼容性 → 安全公告 →
PHP 8.2/8.3/8.4 兼容 → 双处登记 → 确认不进 Free 包）。未登记即不允许合入。

---

## 相关文件

| 文件 | 内容 |
|---|---|
| `THIRD-PARTY-LICENSES.md` | 依赖登记表（Library / Version / License / Source / Used Files / GPL Compatibility） |
| `docs/external-service-report.md` | 外部服务清单 |
| `docs/release.md` | 构建流程内的依赖登记表 |
