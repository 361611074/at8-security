# AGENTS.md — at8-license-server（授权服务器）

AT8 平台层的第一个组件：签发 License、回答客户端激活/校验/解绑、托管更新包。**只在自家站点运行，不分发给客户，不上架 wp.org。**

上级规则见 `../../AGENTS.md` 与 `docs/AT8-WordPress-商业产品通用架构规范-v2.0.md`。

## 这个插件特有的约束

1. **商业记录优先于干净卸载**。卸载默认保留 `wp_at8_licenses` / `wp_at8_license_sites` / `wp_at8_audit_logs`；只有显式开启（审计页开关或 `AT8LS_PURGE_ON_UNINSTALL`）才删除。→ 这是与规范 §28 对齐的行为，改动需人类确认。
2. **审计日志不得删除**（除保留期自动裁剪与管理员显式清理），且**永不写入密钥明文**；字段名含 key / secret / token / signature 的一律丢弃。
3. **下载链接 30 分钟过期**（`Package::TOKEN_TTL`，规范 §8.1 要求 10–30 分钟）。不要为了省事调大。
4. **只有 `published` 状态的构建会下发**；下架 = 改状态为 `withdrawn`，记录与文件都保留。
5. **API 双命名空间**：`at8/v1`（规范地址，新的下载链接走它）与 `at8-license/v1`（老客户端在用，不得下线）。
6. **字段名双读**：`domain`/`wp`/`php` 与 `site_url`/`wp_version`/`php_version` 都要接受——服务端必须先升级。
7. **DB 迁移只看 `Db::DB_VERSION`**，加列/加表用 dbDelta（增量安全），**禁止 DROP**；升级路径必须能在持有真实授权的库上跑通。
8. **限流分两档**：已知密钥宽松（600/10min）、未知密钥严格（30/10min）。改数值前先想清楚 NAT 出口共享 IP 的站点。
9. **拒绝要给状态码**：权限拒绝走 `Admin::refuse()` → `wp_die( $msg, '', array( 'response' => 403 ) )`。`wp_die( $msg )` 默认是 500，会被当成服务器故障。
10. **每次加文案都要重编语言包**：后台是 zh_CN，漏翻的串会让客户在最需要看懂的页面看到英文。改完跑 `python at8ls-i18n.py`，`still untranslated` 必须是 0。
11. **探针脚本不得二次 `require wp-load.php`**（`wp eval-file` 已引导；参数在 `$args` 不在 `$argv`）。

## 目录

```text
at8-license-server/
├── at8-license-server.php   常量（含 AT8LS_REST_NAMESPACE / AT8LS_REST_CANONICAL）、文本域
├── includes/
│   ├── class-db.php        三张表 + db_version 迁移
│   ├── class-audit.php     审计事件写入/读取/裁剪
│   ├── class-keys.php      密钥签发、状态、站点绑定、verify/refuse
│   ├── class-package.php   版本发布、sha256、签名下载链接
│   ├── class-rest.php      五个路由（双命名空间）
│   ├── class-admin.php     四个标签页 + 全部写操作 nonce
│   ├── class-plugin.php    启动、cron 裁剪、激活/停用
│   └── helpers.php         密钥生成、域名归一、限流、签名、CIDR
├── assets/admin.css
├── languages/  uninstall.php
```

## 改这里的流程

1. 读 `docs/architecture.md` 的「授权链路」与 `docs/api.md`。
2. 改 schema → 递增 `DB_VERSION`。
3. 改 API → 只做加法（新字段/新命名空间），老客户端不得被破坏。
4. 加了文案 → 跑 `python at8ls-i18n.py` 重编 `.po` / `.mo`。
5. 本地起不来 PHP 8.2 → 传测试站 `php -l`，再按顺序跑
   `at8ls-spec.py`（会部署）→ `at8ls-deploy.py` → `at8ls-publish.py` → `at8sec-attack.py`。

## 交付自检

```bash
php -l includes/class-keys.php
grep -rn "!important" assets/                          # 0
grep -rn "TOKEN_TTL" includes/                         # 必须是 1800
grep -rn "wp_die(" includes/ | grep -v "response"      # 0（拒绝都要带状态码）
python at8ls-i18n.py                                   # still untranslated: 0
```
