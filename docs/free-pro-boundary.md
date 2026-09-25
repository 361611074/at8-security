# Free / Pro 边界

规范 §6 / §93 / §102 的核心。这份文件回答一个问题：**免费版里到底有没有 Pro 的东西。**

答案必须是「一点都没有」，而且必须是**可验证的**，不是一句声明。

---

## 一句话契约

```text
WordPress.org Free ≠ Pro Demo
WordPress.org Free = 完整、独立、真实可用的免费插件
```

免费版装上就能用，没有任何功能等着被解锁。Pro 是**另一个插件**，装上就多出几项功能，
不装也什么都不缺 —— 缺的只是那几项 Pro 功能本身。

---

## 物理隔离

两边是**不同的插件目录、不同的 zip、不同的发布渠道**，代码不共享文件。

| | 免费版 | Pro |
|---|---|---|
| 目录 | `free/at8-security/` | `pro/at8-security-pro/` |
| 插件 slug | `at8-security` | `at8-security-pro` |
| 命名空间 | `At8\Security\` | `At8\Security\Pro\` |
| 常量前缀 | `AT8SEC_` | `AT8SEC_PRO_` |
| 文本域 | `at8-security` | `at8-security-pro` |
| 分发 | WordPress.org | 自家站点 + 授权服务器下发 |
| 依赖 | **无** | 依赖免费版（未装则不启动） |

**免费版 zip 里不存在任何 `At8\Security\Pro` 代码、`at8-security-pro` 字符串或 `at8ls_` / `AT8LS_` 标识。**
这一条由构建脚本对每个 zip 逐条扫描（`at8sec-build-zips.py` 的 `check_strings`），失败即构建失败。

---

## 唯一的连接点：钩子

两个插件之间只有一条通路 —— 免费版在 19 个位置触发 `at8sec_*` 钩子（清单见
`docs/free-extension-api.md`），Pro 通过 `add_action` / `add_filter` 挂上去。

```text
免费版                                         Pro 附加组件
────────                                      ────────────
do_action( 'at8sec_loaded', $this )  ◄──────  add_action( 'at8sec_loaded', ... )
                                                     │ 在这里挂自己的钩子
apply_filters( 'at8sec_is_pro', false ) ◄────  add_filter( 'at8sec_is_pro', ... )
apply_filters( 'at8sec_captcha_method', 'math' ) ◄── 返回 'turnstile'
do_action( 'at8sec_pro_panel' )      ◄──────  add_action( 'at8sec_pro_panel', ... )
```

**为什么用钩子而不是「检测 Pro 是否存在」**：钩子让免费版**不知道** Pro 的存在。
免费版里没有一处 `if ( Pro 存在 )`、没有 `class_exists( 'At8\Security\Pro\...' )`、没有
`defined( 'AT8SEC_PRO_VERSION' )`。免费版只知道「有人挂了这个钩子吗」——第三方附加组件与 Pro
拿到的是同一套接口，没有优先通道。

Pro 侧用到的 10 个钩子：

| 钩子 | Pro 的用途 |
|---|---|
| `at8sec_is_pro` | 授权校验通过后返回 `true` |
| `at8sec_admin_tabs` | 挂上 Pro 的标签页 |
| `at8sec_admin_panel` | 渲染 Pro 面板 |
| `at8sec_pro_panel` | 接管「Pro」标签页 |
| `at8sec_trusted_proxies` | 并入 CDN 网段 |
| `at8sec_client_ip_headers` | 调整取真实 IP 的头顺序 |
| `at8sec_captcha_method` | 返回 `turnstile` |
| `at8sec_captcha_render` | 渲染 Turnstile 控件 |
| `at8sec_captcha_rendered` | 声明已渲染，抑制算术题 |
| `at8sec_captcha_verify` | 校验 Turnstile token |

---

## 功能矩阵

| 功能 | 免费版 | Pro |
|---|---|---|
| 隐藏 WP 版本 / 禁用 XML-RPC / 关闭用户枚举 | ✅ | |
| 统一登录错误提示 | ✅ | |
| 禁用文件编辑器 / RSD+WLW / 作者枚举 / 目录列表 | ✅ | |
| 上传目录禁止执行 PHP | ✅ | |
| 安全响应头（含 CSP，默认 report-only） | ✅ | |
| 强制 HTTPS / HSTS | ✅ | |
| 登录限流 + 锁定 | ✅ | |
| 自定义登录路径 | ✅ | |
| 登录邮件告警 | ✅ | |
| 登录蜜罐 | ✅ | |
| **算术题人机校验** | ✅ | |
| **托管人机校验（Turnstile）** | | ✅ |
| 口令策略 / 弱口令扫描 | ✅ | |
| 防火墙（SQLi / XSS / 穿越 / RCE） | ✅ | |
| IP 黑白名单 / 全局限流 / 扫描器 UA / 404 洪水 | ✅ | |
| 评论垃圾拦截 / 关闭评论 | ✅ | |
| 上传扩展名限制 / REST 锁定 | ✅ | |
| 审计日志（含保留期裁剪） | ✅ | |
| **CDN / 反代网段自动探测** | | ✅ |
| **文件完整性监控（基线比对）** | | ✅ |
| **沉默账号报告** | | ✅ |
| **两步验证（恢复码 + 记住设备）** | | ✅ |
| **自动更新（授权服务器下发）** | | ✅ |

读法：免费版列有 ✅ 的行，就是免费版**完整可用**的功能，与 Pro 是否安装、是否授权**无关**。

---

## 明令禁止（规范 §6）

| 禁止项 | 具体表现 |
|---|---|
| Trialware | 免费版功能「试用 14 天」「限量 N 次」后失效 |
| Feature Lock | 免费版功能存在但被开关关掉，提示「升级解锁」 |
| 假功能 | 界面上有入口，点了提示「Pro 功能」 |
| Pro 代码 | 免费版 zip 里含 Pro 的实现（哪怕没启用） |
| 隐藏后门 | 免费版预留只有 Pro 知道的开关 / 私密接口 |
| 定时炸弹 | 免费版里写着到期或到量后自行降级的逻辑 |

**免费版的任何功能都不得以 `is_pro()` 为开关。** `at8sec_is_pro` 只用来决定「要不要显示 Pro 的界面入口」。
把免费功能挂在这个开关上，就是在自己造 Trialware。

---

## 怎么验证（不靠声明，靠检查）

| 检查 | 手段 | 通过标准 |
|---|---|---|
| 免费版无 Pro 代码 | 构建脚本 `check_strings`（规则：`At8\Security\Pro`、`at8-security-pro`、`at8-license/v1`、`at8ls_`、`AT8LS_`） | 0 命中 |
| 免费版不引用 Pro 常量 | 同上 | 0 命中 |
| 免费版可独立运行 | 测试站只启用免费版，跑完整前台 / 后台回归 | 无 Fatal、无白屏、功能齐全 |
| 免费版无出站请求 | `grep -rn "wp_remote_" --include="*.php" free/` | 0 |
| 免费版功能不依赖授权 | 断网 / 未装 Pro 下逐项功能测试 | 全部照常 |
| Pro 缺失时不报错 | 停用 Pro 后访问免费版全部页面 | 无 Warning |
| 免费版无 Trialware 文案 | 检查界面文案与 readme | 无「试用」「限量」「解锁」 |

`at8sec-build-zips.py` 每次构建都跑前两项，**失败即构建失败**（非零退出码）。
其余各项见 `docs/free-pro-boundary-report.md` 的实测记录。

---

## Pro 侧的两条对应约束

1. **未装免费版则不启动**：Pro 在 `plugins_loaded` 优先级 6 上检查免费版的函数与过滤器是否就绪
   （免费版在优先级 5 启动），不满足就提示并自行停用，不产生 Fatal。
2. **授权失效不致命**：服务器不可达时走宽限期（`AT8SEC_PRO_GRACE_DAYS`），不关闭安全功能。
   安全插件关掉防护比授权超期危险得多。见 `docs/architecture.md`。
