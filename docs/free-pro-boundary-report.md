# Free / Pro 边界验证报告

规范 §6 / §93 / §99 / §101 第 2 项（Free/Pro 功能矩阵）。

**验证对象**：`at8-security` 2.0.3（上架包）vs `at8-security-pro` 2.0.3
**验证日期**：2026-09-25
**验证方式**：分发包逐文件扫描 + 源码引用检查 + 测试站实测

边界契约本身见 `docs/free-pro-boundary.md`；本文件是**实测记录**。

---

## 结论

```text
Free / Pro 边界：PASS
Trialware：PASS
隐藏 Pro 代码：PASS
```

---

## 检查 1：免费版 zip 不含 Pro 代码

构建脚本 `at8sec-build-zips.py` 每次构建都对每个 zip 跑 `check_strings`，
规则是精确模式（避免误报免费版自己合法的升级提示）：

| 规则 | 含义 | 命中 |
|---|---|---|
| `At8\Security\Pro` | Pro 命名空间 | **0** |
| `at8-security-pro` | Pro slug | **0** |
| `at8-license/v1` | 授权服务器路由 | **0** |
| `at8ls_` | license-server 选项前缀 | **0** |
| `AT8LS_` | license-server 常量前缀 | **0** |
| `AT8SEC_PRO` | Pro 常量前缀 | **0** |

`dist/BUILD-REPORT.json` 中三个包的 `checks.forbidden strings` 均为空数组，`passed: true`。

> 规则写法说明：朴素的「搜 `pro`」会命中免费版自己的升级面板 —— 而那个面板是 wp.org
> 指南**要求**存在的。一条在正确代码上触发的规则比没有规则更糟，因为它会被关掉然后被忽略。

---

## 检查 2：免费版不引用任何 Pro 标识

```bash
grep -rnE "At8.Security.Pro|at8ls_|AT8LS_|at8-license/v1|AT8SEC_PRO" --include="*.php" free/
```

**0 命中。**

---

## 检查 3：免费版不做「Pro 是否存在」的探测

规范要求免费版**不知道** Pro 的存在。

| 检查 | 结果 |
|---|---|
| `class_exists( 'At8\Security\Pro\...' )` | **0 命中** |
| `defined( 'AT8SEC_PRO_*' )` | **0 命中** |
| `function_exists( 'at8sec_pro_*' )` | **0 命中** |

免费版里的 `function_exists()` 只用于 WordPress 核心函数（`insert_with_markers`、
`extract_from_markers`），`defined()` 只用于 WordPress 核心常量与本插件自己的常量
（`ABSPATH`、`WP_UNINSTALL_PLUGIN`、`DISALLOW_FILE_EDIT`、`WP_CLI`、`REST_REQUEST`、
`AT8SEC_DISABLE_CUSTOM_LOGIN`、`AT8SEC_TRUSTED_PROXIES`）。

**唯一的连接方式是钩子**（19 个，清单见 `docs/free-extension-api.md`）。

---

## 检查 4：免费版功能不以 `is_pro()` 为开关

`is_pro()` 在免费版中只有 **2 处**使用，**都在后台界面文案层**：

| 位置 | 用途 | 是否影响功能 |
|---|---|---|
| `class-admin.php:492` | 「Pro」标签页里显示一句「附加组件已激活」 | 否，纯文案 |
| `class-admin.php:655` `pro_note()` | 在 Pro 未激活时打印一行「X 在 Pro 附加组件中提供」 | 否，`return` 早退，不改变任何功能 |

`pro_note()` 的注释写明了理由：它描述的是**这个下载包里根本不存在**的功能，
所以没有任何东西被付费锁住。

**没有任何一个免费功能被 `is_pro()` 或任何条件包住。** 这是规范 §6 的核心红线。

---

## 检查 5：免费版零出站（可独立运行的前提）

| 检查 | 结果 |
|---|---|
| `grep -rn "wp_remote_\|curl_\|fsockopen" free/` | **0** |

免费版不连 AT8 服务器，因此**不存在「因为连不上服务器所以功能降级」的可能**。
详见 `docs/external-service-report.md`。

---

## 检查 6：无 Trialware 文案

```bash
grep -rniE "trial|unlock|expires in|limited time" --include="*.php" free/
```

**0 命中。**

界面与 readme 中没有任何「试用」「限量」「解锁」「到期」表述。

---

## Free / Pro 功能矩阵

规范 §101 第 2 项要求的功能矩阵，完整版见 `docs/free-pro-boundary.md`。摘录判定要点：

| 维度 | 免费版 | Pro |
|---|---|---|
| 插件是否可独立运行 | ✅ 完全独立 | ❌ 依赖免费版 |
| 加固 / 响应头 / HTTPS | ✅ 全部 | — |
| 登录防护 / 自定义登录路径 / 邮件告警 | ✅ 全部 | — |
| 人机校验 | ✅ 本地算术题 | ✅ 托管 Turnstile（**替代**，非解锁） |
| 口令策略 / 弱口令扫描 | ✅ 全部 | — |
| 防火墙 / 限流 / IP 黑白名单 | ✅ 全部 | — |
| 评论 / 上传 / REST 防护 | ✅ 全部 | — |
| 审计日志 + 保留期裁剪 | ✅ 全部 | — |
| CDN 网段自动探测 | ❌ | ✅ 新增能力 |
| 文件完整性监控 | ❌ | ✅ 新增能力 |
| 沉默账号报告 | ❌ | ✅ 新增能力 |
| 两步验证 | ❌ | ✅ 新增能力 |
| 自动更新 | ❌ | ✅ 新增能力 |

读法：Pro 列的每一项都是**新增能力**，不是「免费版已有但被关掉」。免费版列全部为 ✅，
即免费版在本列没有任何 ❌。

---

## 测试站实测（补充证据）

| 场景 | 期望 | 结果 |
|---|---|---|
| 只启用免费版，访问全部后台标签页 | 无 Fatal、无 Warning、功能齐全 | 见 `docs/testing.md` 回归记录 |
| 停用 Pro 后访问免费版全部页面 | 免费版一切照常 | 同上 |
| 免费版前台回归（首页 200、登录页正常、WAF 探针 403） | 通过 | `at8sec-attack.py` 30/30 |

---

## 风险清单

| 风险 | 判定 | 说明 |
|---|---|---|
| Trialware / Feature Lock | **无风险** | 免费功能不以 `is_pro()` 为开关，已逐处核对 |
| 免费版含 Pro 代码 | **无风险** | 构建脚本逐条扫描，0 命中 |
| 隐藏后门 / 私密接口 | **无风险** | 只有 19 个公开钩子，无私有入口 |
| 定时炸弹（到期降级） | **无风险** | 无到期逻辑 |
| 免费版依赖授权服务器 | **无风险** | 0 出站 |
| 「Pro」标签页被误判为功能锁定 | **低** | 该标签页只做展示与链接，是 wp.org 允许的自家附加组件提及 |

---

## 相关文件

| 文件 | 内容 |
|---|---|
| `docs/free-pro-boundary.md` | 边界契约（唯一连接点、功能矩阵、禁止项） |
| `docs/free-extension-api.md` | 19 个钩子的完整契约 |
| `docs/external-service-report.md` | 免费版零出站的实测记录 |
| `dist/BUILD-REPORT.json` | 每次构建的扫描结果 |
