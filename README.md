# at8-security · WordPress 安全防御插件（免费版）

「漫步白月光」自研 WordPress 安全防御插件免费版，上架 WordPress.org，slug `at8-security`。
**私有仓库，仅存档与协作，不分发。**

## 产品线仓库划分

商业附加组件与授权服务器已拆分为**独立仓库**，本仓库不再包含它们的代码，
也不接受把它们的源码复制回来：

| 产品 | 仓库 | 说明 |
|---|---|---|
| `at8-security`（免费版） | 本仓库 | 上架 WordPress.org |
| `at8-security-pro`（商业版） | https://github.com/361611074/at8-security-pro | 自建分发，需 License |
| `at8-license-server`（授权服务器） | https://github.com/361611074/at8-license-server-v2 | 只跑在官网 `at8.fun` |

三者的关系只有一条：**免费版通过公开钩子暴露扩展点，Pro 挂上去**。
免费版内零 Pro 代码，见 `docs/free-extension-api.md` 与 `docs/free-pro-boundary.md`。

## 结构

```text
free/at8-security/         免费版插件（上架 WordPress.org，slug: at8-security）
wordpress-org-assets/      wp.org 上架素材（截图 4 张已完成；icon / banner 方案待定）
docs/                      边界 / 扩展 API / 安全 / 隐私 / 测试 / 发布 / 审计报告
AGENTS.md                  开发约定（改代码前先读）
THIRD-PARTY-LICENSES.md    第三方依赖登记表（当前 0 个依赖）
at8sec-build-zips.py       构建 + §65 六项 zip 审计
at8sec-wporg-brand.py      生成 wp.org icon / banner 方案
dist/                      构建产物 zip（本地构建，不入库）
CHANGELOG.md               更新记录
```

## 版本

| 产品 | 当前版本 | 分发 |
|---|---|---|
| at8-security (free) | 2.0.3 | WordPress.org（待提交 SVN；上架素材未完成） |

## 构建

```bash
python at8sec-build-zips.py              # 生成 dist/*.zip，顶层目录即插件目录；审计失败即非零退出
python at8sec-build-zips.py --only free  # 等价（本仓库只有免费版一个构建目标）
```

## 架构要点

- **免费版可独立运行**：不连 AT8 服务器，零出站请求；装上 → 激活 → 可用。
- **Hook 桥接**：免费版暴露 `apply_filters('at8sec_is_pro', false)`；Pro 授权有效时挂同名 filter 为 true。
  19 个钩子的完整契约见 `docs/free-extension-api.md`。
- **免费功能不挂 `is_pro()`**：该过滤器只决定「是否显示 Pro 入口」，不得用来开关任何免费功能。
- **CSS 铁律**：全部样式零 `!important`，用 `.at8sec-` 前缀保证特异性。
- **锁定恢复**：自定义登录路径失控时，在 `wp-config.php` 加
  `define('AT8SEC_DISABLE_CUSTOM_LOGIN', true);` 恢复默认登录。

## 上架状态

`FREE_WORDPRESS_ORG_READY = NO` —— 唯一未完成项是 wp.org 上架素材（icon / banner；截图已完成）。
代码、许可、安全、边界、readme、ZIP 全部 PASS。
详见 `docs/wordpress-org-compliance-report.md` 与 `docs/release-report.md`。

## 作者

漫步白月光 · https://www.at8.fun/
