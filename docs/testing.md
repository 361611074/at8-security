# 测试

## 测试环境

| 环境 | 位置 | 用途 |
|---|---|---|
| 生产 | `at8.fun` | 真客户。**不得拿它做测试** |
| 验收站 | `wordpress.xmm.fan` | 授权/发布/对抗测试都在这跑 |
| 本地 | phpstudy（PHP 8.2 nts） | 主题/插件离线渲染；本产品线语法检查以服务器为准 |

PHP 覆盖目标：8.2 / 8.3 / 8.4（规范 §23）。当前实测环境为 PHP 8.2。

**本地没有可用的 PHP 8.2 CLI**（phpstudy 自带 7.3 会因箭头函数报错），因此语法检查在测试站执行：

```bash
php -l /www/wwwroot/wordpress.xmm.fan/wp-content/plugins/at8-license-server/includes/class-keys.php
```

## 测试脚本

脚本在工作区 `C:\Users\漫步白月光\WorkBuddy\2026-09-13-22-03-31\`，SSH 配置在 `~/.workbuddy/wpv.json`。

| 脚本 | 覆盖面 |
|---|---|
| `at8ls-spec.py` | **规范符合性**：带数据升级 / 审计日志 / sha256 / token 期限 / 双命名空间 / 卸载策略 / 语言包（78 项） |
| `at8ls-deploy.py` | 授权链路：签发 / 激活 / 校验 / 解绑 / 席位上限 / 过期 / 吊销 / 停用（38 项） |
| `at8ls-publish.py` | 发布链路：上传 / 状态 / 签名下载 / token 过期 / 哈希校验 / 包缺失（63 项） |
| `at8sec-attack.py` | 对抗：越权访问后台、缺 nonce、注入、超大 key、无 key 调用、限流（26 项） |
| `at8sec-pro-http.py` | Pro 端到端：激活 → 更新检查 → 下载 |
| `at8sec-login404-final-verify.py` | 自定义登录路径矩阵（大小写 / 非法字符 / 保留词） |
| `at8sec-smoke.py` | 基础安装/激活烟测 |

### 探针脚本

`at8ls-tests/spec-probe.php` 通过 `wp eval-file` 在测试站内部取值（表结构、审计行、发布状态、token 载荷），
由 `at8ls-spec.py` 部署并解析。它**不能**再 `require wp-load.php`——`wp eval-file` 已经引导过 WordPress，
二次加载会重定义 wp-config 常量并把 stdout 污染成一片警告，所有断言随之失效。
WP-CLI 传给脚本的参数在 `$args` 里，不是 `$argv`。

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
