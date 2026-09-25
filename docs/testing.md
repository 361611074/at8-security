# 测试

## 测试环境

| 环境 | 位置 | 用途 |
|---|---|---|
| 生产 | `at8.fun` | 真客户。**不得拿它做测试** |
| 验收站 | `wordpress.xmm.fan` | 授权/发布/对抗测试都在这跑 |
| 本地 | phpstudy（PHP 7.3.4 nts） | 跑构建脚本与测试脚本；**不能**用于本产品线的语法检查 |

PHP 覆盖目标：8.2 / 8.3 / 8.4（规范 §23）。当前实测环境为 **PHP 8.3.33**。

**本地没有可用的 PHP CLI**：phpstudy 自带的 7.3.4 低于插件下限 7.4，
解析不了 `\u{2022}` 转义与箭头函数，`php -l` 会报一堆与真实问题无关的语法错误。
因此语法检查一律在测试站执行：

```bash
php -l /www/wwwroot/wordpress.xmm.fan/wp-content/plugins/at8-license-server/includes/class-keys.php
```

构建脚本遇到本地 PHP 低于插件下限时，会把这一项标成 `deferred` 并说明原因，
而不是把解析错误当成代码问题报出来。

## 测试脚本

脚本在工作区 `C:\Users\漫步白月光\WorkBuddy\2026-09-13-22-03-31\`，SSH 配置在 `~/.workbuddy/wpv.json`。

| 脚本 | 覆盖面 |
|---|---|
| `at8ls-spec.py` | **规范符合性**：带数据升级 / 审计日志 / sha256 / token 期限 / 双命名空间 / 卸载策略 / 语言包 / 密钥存储（97 项） |
| `at8ls-deploy.py` | 授权链路：签发 / 激活 / 校验 / 解绑 / 席位上限 / 过期 / 吊销 / 停用 / 宽限期 / 限流（38 项） |
| `at8ls-publish.py` | 发布链路：上传 / 状态 / 签名下载 / token 过期 / 哈希校验 / 撤回 / 更新下发（73 项） |
| `at8sec-attack.py` | 对抗：越权访问后台、缺 nonce、注入、超大 key、无 key 调用、限流、密钥存储（30 项） |
| `at8sec-lint-zips.py` | 用目标 PHP 对 `dist/` 的发行 zip 逐文件 `php -l`（当前 55 个文件） |
| `at8sec-plugin-check.py` | 在测试站安装发行 zip 并跑官方 Plugin Check，结果写 `dist/plugin-check-<slug>.json` |
| `at8sec-push.py` | 单文件推送 + 服务器端 `php -l`；把仓库的 `free/` `pro/` `license-server/` 分组层映射掉 |
| `at8sec-pro-http.py` | Pro 端到端：激活 → 更新检查 → 下载 |
| `at8sec-login404-final-verify.py` | 自定义登录路径矩阵（大小写 / 非法字符 / 保留词） |
| `at8sec-smoke.py` | 基础安装/激活烟测 |

### 探针脚本

`at8ls-tests/spec-probe.php` 通过 `wp eval-file` 在测试站内部取值（表结构、审计行、发布状态、token 载荷），
由 `at8ls-spec.py` 部署并解析。它**不能**再 `require wp-load.php`——`wp eval-file` 已经引导过 WordPress，
二次加载会重定义 wp-config 常量并把 stdout 污染成一片警告，所有断言随之失效。
WP-CLI 传给脚本的参数在 `$args` 里，不是 `$argv`。

跑任意一次性探针用 `at8ls-run.py <本地 php 文件>`，它负责上传与 `wp eval-file`。
把探针写成文件而不是拼进 shell 一行，是因为引号在传输里会失真——这一点踩过三次。

### 带数据的升级测试

规范禁止拿空表证明迁移。`migrate` 模式的做法是：先造 3 条授权 + 1 条站点绑定，
然后把表结构**退回 v1 形态**（`DROP` 审计表、`ALTER TABLE ... DROP COLUMN` 掉新增列）而**保留数据行**，
把 `at8ls_db_version` 置回 `1`，再调用插件自己的 `Db::maybe_install()`，最后比对前后行数、
列名与一条真实 `activate` 是否仍能写入新列。

### 语言包

后台全中文，因此**断言不得依赖英文字符串**。`at8ls-i18n.py` 负责从源码抽取全部可翻译串、
补齐缺失翻译、把已废弃的标记为 obsolete 并编译 `.mo`：

```bash
python at8ls-i18n.py     # 输出应为 still untranslated: 0
```

### 翻译是怎么加载的（别凭印象改）

`at8ls-tests/i18n-probe.php` 打印每个文本域的 `.mo` 位置、加载前后状态、一条真实译文，
以及运行内核里 `_load_textdomain_just_in_time()` 与 `WP_Textdomain_Registry` 的相关源码。

**实测结论（WordPress 7.1.2）**：`get_paths_for_domain()` 只搜索
`wp-content/languages/plugins`、`wp-content/languages/themes`，以及该域**自己注册过的**路径。
插件自己的 `/languages` 之所以会被搜索，**只是因为 `load_plugin_textdomain()` 把它注册进了
`custom_paths`** —— WordPress 并不会自动加载插件自带的语言目录。

所以三个主文件里的 `load_plugin_textdomain()` 是**必需的**，不是遗留代码。
Plugin Check 会对它报一条建议性 WARNING，该警告已按规范 §47 逐条判断并记录在
`docs/wordpress-org-compliance-report.md`，**不要顺手删掉**。

### 客户端退避

Pro 的 `Updater::remote_info()` 在一次调用失败后会**退避一小时**
（`FAILURE_SECONDS = 3600`）。测更新链路前必须清掉这个标志，否则会把
「上次抖动的残留」读成「服务端没有提供版本」。`at8ls-tests/t7-toggle.php clear-cache`
会同时清缓存与退避标志，并打印 `FAIL_FLAG`。

## 每次改动必须覆盖

规范 §23 的清单，按本产品线裁剪：

- [ ] 新安装（空库 → 激活插件 → 表自动建好）
- [ ] 升级（**持有真实授权的库** → 覆盖文件 → dbDelta 迁移 → 授权仍有效）
- [ ] 卸载（默认保留记录；开启开关后确实清空）
- [ ] 权限（非管理员访问后台 → 403；无 nonce POST → 403）
- [ ] REST（五个路由的正常 + 拒绝路径）
- [ ] Cron（审计裁剪任务已调度、停用后取消）
- [ ] 多站点（逐站建表、逐站清理）
- [ ] 前端回归（首页 200、登录页正常、WAF 探针仍 403）

## 规矩

1. **失败的测试不得假装通过**，也不许删断言让它变绿；要么修代码，要么把「为什么这样才对」写进注释并改断言。
2. **测试脚本必须先快照再动手**：涉及写 `wp_options` 的脚本一律用 `wp option patch update`（改单键），**禁止 `wp option update` 传部分数组**（会整项覆盖）。
3. 测试完清理残留（测试密钥、绑定、发布记录、transient）。
4. 前端问题不许猜：先 curl / Console 拿证据。
5. **断言要落在结构与状态码上，不落在文案上**。站点语言是 zh_CN，REST 还会把非 ASCII 转义成 `\uXXXX`；
   断言英文短语等于把测试绑死在 en_US 站点上。可用标记：`tab=licenses`、`name="at8ls_task" value="issue"`、
   `"status":"rejected"`。确实要匹配文案时，中英各写一条，并先 `json.loads` 再比对。
6. **版本号不许写死在测试里**。要判断「有新版本可升级」，就从插件头读当前版本再往上取一档；
   写死的数字会在下一次版本号递增时静默失效，表现为「没有更新可提供」却看不出是测试过期了。
7. **统计口径要能暴露自己**：`check()` 必须同时写 `checks` 与 `failures`。
   只写 `failures` 会让汇总恒为 `0/0`，看起来像通过而其实什么都没统计。
