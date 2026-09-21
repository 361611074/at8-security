# at8-security · 安全防御插件全家桶

「漫步白月光」自研 WordPress 安全防御插件，free / Pro 双版本 + 自建授权服务器。
**私有仓库，仅存档与协作，不分发。**

## 结构

```
free/at8-security/                  free 版（上架 WordPress.org，slug: at8-security）
pro/at8-security-pro/               Pro 附加组件（独立 slug，自建分发）
license-server/at8-license-server/  授权服务器（装在官网，提供 /wp-json/at8-license/v1）
dist/                               构建产物 zip（本地构建，不入库）
CHANGELOG.md                        更新记录
```

## 版本

| 产品 | 当前版本 | 分发 |
|---|---|---|
| at8-security (free) | 2.0.1 | WordPress.org（待提交 SVN） |
| at8-security-pro | 2.0.1 | 自建（授权服务器） |
| at8-license-server | 1.0.0 | 官网 at8.fun（待部署） |

## 构建

```bash
python at8sec-build-zips.py   # 生成 dist/*.zip，顶层目录即插件目录
```

## 架构要点

- **Hook 桥接**：free 暴露 `apply_filters('at8sec_is_pro', false)`；Pro 授权有效时挂同名 filter 为 true。free 内零 Pro 代码，符合 wp.org Guideline 5/8。
- **Pro 更新**：Pro 检查 `https://www.at8.fun/wp-json/at8-license/v1` 获取更新与授权校验。
- **CSS 铁律**：全部样式零 `!important`，用 `.at8sec-` 前缀保证特异性。
- **锁定恢复**：自定义登录路径失控时，在 wp-config.php 加 `define('AT8SEC_DISABLE_CUSTOM_LOGIN', true);` 恢复默认登录。

## 作者

漫步白月光 · https://www.at8.fun/
