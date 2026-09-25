# at8-security · 安全防御插件全家桶

「漫步白月光」自研 WordPress 安全防御插件，free / Pro 双版本 + 自建授权服务器。
**私有仓库，仅存档与协作，不分发。**

## 结构

```
free/at8-security/                  free 版（上架 WordPress.org，slug: at8-security）
pro/at8-security-pro/               Pro 附加组件（独立 slug，自建分发）
license-server/at8-license-server/  授权服务器（装在官网，提供 /wp-json/at8/v1）
docs/                               架构 / 边界 / API / 安全 / 隐私 / 数据库 / 测试 / 发布 / 审计报告
THIRD-PARTY-LICENSES.md             第三方依赖登记表（当前 0 个依赖）
at8sec-build-zips.py                构建 + §65 六项 zip 审计
dist/                               构建产物 zip（本地构建，不入库）
CHANGELOG.md                        更新记录
```

## 版本

| 产品 | 当前版本 | 分发 |
|---|---|---|
| at8-security (free) | 2.0.3 | WordPress.org（待提交 SVN；上架素材未完成） |
| at8-security-pro | 2.0.3 | 自建（授权服务器） |
| at8-license-server | 1.2.0 | 官网 at8.fun（待部署） |

## 构建

```bash
python at8sec-build-zips.py   # 生成 dist/*.zip，顶层目录即插件目录；审计失败即非零退出
```

## 架构要点

- **Hook 桥接**：free 暴露 `apply_filters('at8sec_is_pro', false)`；Pro 授权有效时挂同名 filter 为 true。free 内零 Pro 代码，符合 wp.org Guideline 5/8。19 个钩子的完整契约见 `docs/free-extension-api.md`。
- **Pro 更新**：Pro 检查 `https://www.at8.fun/wp-json/at8/v1` 获取更新与授权校验。
- **密钥存储**：授权服务器库内不存明文，只存 HMAC-SHA256（`wp_salt('auth')` 加盐）+ 末 5 位提示；完整密钥仅签发时展示一次。见 `docs/license-api.md`。
- **CSS 铁律**：全部样式零 `!important`，用 `.at8sec-` 前缀保证特异性。
- **锁定恢复**：自定义登录路径失控时，在 wp-config.php 加 `define('AT8SEC_DISABLE_CUSTOM_LOGIN', true);` 恢复默认登录。

## 上架状态

`FREE_WORDPRESS_ORG_READY = NO` —— 唯一未完成项是 wp.org 上架素材
（icon / banner / screenshots）。代码、许可、安全、边界、readme、ZIP 全部 PASS。
详见 `docs/release-report.md`。

## 作者

漫步白月光 · https://www.at8.fun/
