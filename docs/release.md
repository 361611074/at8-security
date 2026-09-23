# 发布

## 版本号（SemVer）

`MAJOR.MINOR.PATCH`：MAJOR = Breaking、MINOR = 新功能、PATCH = 修 Bug。
WordPress 产品的 `Version:` / `Stable tag` / 常量必须与 Git Release 一致。

当前版本：

| 插件 | 版本 | 状态 |
|---|---|---|
| `at8-security` | 2.0.3 | wp.org 待提交 |
| `at8-security-pro` | 2.0.3 | 自建分发 |
| `at8-license-server` | 1.1.0 | 待部署 at8.fun |

## 分支

`main`（生产，只接受 tag 对应提交）+ `feature/*` + `fix/*`。生产代码必须来自 Git Tag / Release。

## 构建

```bash
python C:\Users\漫步白月光\WorkBuddy\2026-09-13-22-03-31\at8sec-build-zips.py
# 产出 dist/at8-security-X.Y.Z.zip、at8-security-pro-X.Y.Z.zip、at8-license-server-X.Y.Z.zip
```

版本号由脚本从各插件主文件的 `Version:` 头**读取**，不从脚本里写死——写死的数字会在下次升版时产出一个
「文件名与内容不一致」的包，而这种事通常发给客户之后才发现。升版后顺手删掉 `dist/` 里的旧包。

**加过文案就要先重编语言包**：`python at8ls-i18n.py`，`still untranslated` 必须为 0。

⚠️ **禁止用 PowerShell `Compress-Archive`**：它把 zip 条目名写成反斜杠，Linux 上解压出畸形文件名，WordPress 报「缺少 style.css」类错误。构建脚本用 PHP ZipArchive 显式写正斜杠。

打包必须排除：`.backups/`、`.git/`、`dist/`、`tests/`、`node_modules/`。

## Release Checklist（规范 §31 + §32）

**通用**

- [ ] 版本号三处一致（文件头 / 常量 / readme Stable tag）
- [ ] Changelog 已写（中文更新记录同步到仓库根 `CHANGELOG.md`）
- [ ] 语言包已重编（`at8ls-i18n.py`，`still untranslated: 0`）
- [ ] PHP 8.2 语法检查通过（服务器 `php -l`）
- [ ] WP 兼容性声明正确（`Requires at least` / `Tested up to`）
- [ ] PHPCS / PHPStan（本地未接入 CI 时至少做人工等价检查）
- [ ] Plugin Check 无新增问题
- [ ] 安全自查（`docs/security.md` 清单）
- [ ] 拒绝路径都给状态码（`grep -rnE "wp_die\(\s*[^,)]+\)\s*;" --include=*.php .` 应为空）
- [ ] readme 完整（含 Privacy；有出站则含 External Services）
- [ ] GPL 许可头与 `License URI`
- [ ] 第三方依赖已登记
- [ ] 数据库迁移已写且已在**有数据的库**上验过
- [ ] 升级测试 / 新装测试 / 卸载测试
- [ ] ZIP 内容检查（条目用正斜杠、顶层单目录、无 `.backups`）
- [ ] SHA256 记录
- [ ] Git Tag + Release
- [ ] 部署文件清单（一行一个文件名）已给用户

**商业额外**

- [ ] Product / Plan / Price
- [ ] Customer / Order / Subscription / License（Order 与 Subscription 尚未建，接支付前必须补）
- [ ] Site Activation 通路
- [ ] Update Server 已发布新版本并验证下发
- [ ] Payment Webhook / Refund Flow（待建）
- [ ] Email / Terms / Privacy / Support / Documentation

## 第三方依赖登记

| package | version | license | source | purpose |
|---|---|---|---|---|
| （暂无第三方依赖。文本域、翻译、zip 构建均用 WordPress 内置能力与系统 PHP） | — | — | — | — |

引入任何库前：先查 License 兼容性（Must be GPL-compatible）、安全公告、PHP 8.2/8.3/8.4 兼容性；通过后登记上一行。

## 部署

| 目标 | 方式 |
|---|---|
| 测试站 `wordpress.xmm.fan` | paramiko SFTP（`~/.workbuddy/wpv.json`），传完 `php -l` + 跑回归 |
| 生产 `at8.fun` | 同上；**改动前先备份数据库**（商业记录不可丢） |
| 客户站点 | 只通过 Pro 的自动更新下发，不手动传 |

## 回归顺序

`at8ls-deploy.py` 会把免费版与 Pro 从源码打包部署到测试站；`at8ls-spec.py` 负责部署授权服务器。
两者都要跑，之后是对抗与发布链路。当前基线：

```bash
python at8ls-deploy.py      # 38/38   免费版 + Pro 端到端（含授权服务器部署）
python at8ls-spec.py        # 78/78   规范符合性（带数据迁移、审计、sha256、卸载策略）
python at8sec-attack.py     # 26/26   对抗（越权、缺 nonce、注入、限流）
python at8ls-publish.py     # 63/63   后台发布、签名下载、撤回、更新下发
```

四项全绿才算可以发。任何一项变红，先判断是代码坏了还是测试过期了——
若是测试过期，改断言并写清「为什么这样才对」；**不许为了让汇总变绿而删断言**。

交付时告诉用户要覆盖哪些文件：**只列文件名，一行一个**。
