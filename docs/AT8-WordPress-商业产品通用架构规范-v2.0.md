# AT8 WordPress 商业插件 / 主题通用架构规范

**版本：2.0.0**  
**用途：** 作为未来所有 WordPress 插件、主题、Pro 产品、SaaS 服务、License、自动更新及 AI Agent 开发的统一技术基线。

---

## 1. 架构目标

本架构面向以下产品形态：

- WordPress.org 免费插件
- 商业 Pro 插件
- 商业 WordPress 主题
- WooCommerce 扩展
- Elementor / Gutenberg 扩展
- 云端 SaaS / API 服务
- License / Subscription / Update 服务
- 多个产品共享的 AT8 平台

核心原则：

1. **产品代码归自己所有**
2. **Git 仓库是唯一代码事实来源**
3. **免费插件可以独立运行**
4. **商业服务与本地插件功能解耦**
5. **License 不等于核心功能本身**
6. **模型、Agent、服务器均可替换**
7. **所有产品遵循统一安全、测试、发布标准**
8. **从第一天就支持版本升级、回滚和迁移**
9. **所有外部服务和数据传输透明披露**
10. **AI Agent 必须遵守项目规则，不得自由改变架构**

---

# 2. 总体架构

```text
                         ┌──────────────────────┐
                         │       用户 / 客户      │
                         └──────────┬───────────┘
                                    │
                   ┌────────────────┴────────────────┐
                   │                                 │
                   ▼                                 ▼
          WordPress.org / 官网                    GitHub
          免费产品 / 商业产品                 Source / Issues / CI
                   │                                 │
                   └──────────────┬──────────────────┘
                                  │
                                  ▼
                         ┌─────────────────┐
                         │   Coding Agent │
                         │ Claude/Codex/   │
                         │ Copilot/其他    │
                         └────────┬────────┘
                                  │
                                  ▼
                    ┌─────────────────────────┐
                    │      产品代码仓库        │
                    │ Plugin / Theme / Woo    │
                    └────────────┬────────────┘
                                 │
                       ┌─────────┴─────────┐
                       ▼                   ▼
                 自动测试 CI/CD        Release
                       │                   │
                       ▼                   ▼
                测试 WordPress        产品发布包
                       │                   │
                       └─────────┬─────────┘
                                 ▼
                        ┌──────────────────┐
                        │   AT8 Platform   │
                        │                  │
                        │ Customer         │
                        │ Order            │
                        │ Subscription     │
                        │ License          │
                        │ Sites            │
                        │ Update           │
                        │ Cloud Services   │
                        └──────────────────┘
```

---

# 3. 三层架构

所有产品统一拆成三层。

## 3.1 产品层

例如：

- `at8-security`
- `at8-security-pro`
- `at8-seo`
- `at8-woo`
- `at8-theme`

负责：

- WordPress 功能
- 后台 UI
- 前台功能
- REST API
- Gutenberg / Elementor 集成
- 本地数据
- 本地安全逻辑

---

## 3.2 平台层

统一的 AT8 SaaS 平台负责：

- 用户
- 产品
- 订单
- 订阅
- License
- 站点
- 更新
- 云端服务
- API
- 使用量
- 审计日志

不要每个插件自己重新做一套 License Server。

---

## 3.3 基础设施层

包括：

- GitHub
- CI/CD
- 腾讯云 / 其他云服务器
- WordPress 测试环境
- 数据库
- 对象存储
- CDN
- DNS
- 邮件
- 支付平台
- AI API

基础设施可以更换，产品代码不应被绑定。

---

# 4. 免费版与 Pro 版设计

## 4.1 免费插件

免费插件必须尽量做到：

```text
安装
  ↓
激活
  ↓
可以正常使用
```

核心功能不能设计成：

```text
安装
 ↓
必须注册
 ↓
必须连接 License Server
 ↓
才能使用
```

免费插件不应该因为 License Server 暂时不可用而整体失效。

---

## 4.2 Pro / SaaS

Pro 可以增加：

- 高级功能
- 云端服务
- 高级规则
- 云端扫描
- 威胁情报
- 高级报告
- 多站点管理
- 自动化服务
- 商业支持

如果功能依赖云端服务，应明确告诉用户：

- 使用什么服务
- 什么时候连接
- 发送什么数据
- 为什么发送
- 数据保存多久
- 隐私政策
- 服务条款

---

# 5. License 架构

License 是商业授权管理系统，不应该成为整个插件的单点故障。

推荐：

```text
Customer
   │
   └── Order
         │
         └── Subscription
                │
                └── License
                       │
                       ├── Product
                       ├── Plan
                       └── Sites
```

---

## 5.1 License 状态

建议支持：

```text
active
disabled
suspended
revoked
expired
```

不要轻易物理删除 License。

---

## 5.2 License 字段

建议至少：

```text
id
product_id
plan_id
customer_id
license_hash
status
expires_at
max_sites
created_at
updated_at
```

可扩展：

```text
order_id
subscription_id
activation_limit
notes
```

---

## 5.3 License 不建议明文存储

推荐：

```text
用户输入 License
       ↓
规范化
       ↓
Hash / HMAC
       ↓
数据库
```

后台只显示部分 License。

---

# 6. Site / Activation

一个 License 可以绑定一个或多个站点。

建议站点记录：

```text
id
license_id
product_id
site_url
home_url
domain
site_hash
environment
activated_at
last_seen_at
last_ip
wp_version
php_version
```

环境：

```text
production
staging
development
```

---

## 6.1 域名规范化

必须统一处理：

```text
example.com
www.example.com
https://example.com
https://example.com/
```

避免因为 URL 格式不同造成重复激活。

---

## 6.2 站点迁移

必须支持：

```text
旧服务器
   ↓
迁移
   ↓
新服务器
```

如果域名没有变化，不应强迫客户重新购买 License。

建议提供：

- 自助解绑
- 管理员解绑
- 迁移宽限期
- Staging → Production

---

# 7. API 设计

统一：

```text
/wp-json/at8/v1/
```

建议接口：

```text
POST /activate
POST /validate
POST /deactivate

POST /update
GET  /package

GET  /account
GET  /subscription
GET  /features
GET  /site
```

---

## 7.1 Activate

```json
{
  "license": "AT8-XXX-XXXX",
  "product": "at8-security",
  "site_url": "https://example.com",
  "wp_version": "6.9",
  "php_version": "8.3",
  "environment": "production"
}
```

---

## 7.2 Validate

Validate 应用于：

- 主动检查
- 云服务授权
- 账户状态
- 订阅状态

不要让免费插件依赖持续在线验证才能运行。

---

## 7.3 Update

推荐流程：

```text
WordPress
   ↓
检查更新
   ↓
AT8 Update API
   ↓
验证产品 / License
   ↓
返回版本信息
   ↓
生成短期下载 Token
   ↓
下载 ZIP
```

不要粗暴接管 WordPress 原生更新系统。

---

# 8. 更新包安全

每个 Release 保存：

```text
version
package_path
package_size
sha256
minimum_wp
tested_wp
minimum_php
changelog
status
created_at
published_at
```

Release 状态：

```text
draft
testing
published
deprecated
withdrawn
```

---

## 8.1 下载 Token

建议：

- HMAC-SHA256
- `hash_equals()`
- 短期有效
- 绑定 product
- 绑定 license
- 可绑定版本
- 可限制下载次数

建议有效期：

**10～30 分钟**

不要默认设置数小时。

---

# 9. 更新服务器文件安全

更新 ZIP 不应该直接暴露。

推荐：

```text
wp-content/uploads/at8-packages/
        ↓
Web Server 禁止直接访问
        ↓
AT8 API 签名下载
```

同时：

- 随机文件名
- SHA-256
- Web Server deny
- PHP 路由验证
- License 再验证

---

# 10. 支付系统

不要设计成：

```text
支付成功
 ↓
直接生成 License
```

推荐：

```text
Payment
   ↓
Order
   ↓
Subscription
   ↓
Entitlement
   ↓
License
```

支持中国大陆及海外支付渠道：

- **支付宝（Alipay）**
- **微信支付（WeChat Pay）**
- Stripe
- PayPal
- 其他支付渠道

推荐把支付系统设计成统一的 Payment Provider 抽象层，而不是把某一个支付平台直接写死在业务代码里。

```text
                    Payment Service
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
       Alipay          WeChat Pay        Stripe
          │                │                │
          └────────────────┼────────────────┘
                           ▼
                         Order
                           │
                      Subscription
                           │
                        License
```

---

## 10.1 中国支付优先设计

由于 AT8 面向中国用户，支付宝和微信支付应作为 **V1 正式支付渠道**，而不是后期再添加。

推荐：

```text
用户
 ↓
选择支付方式
 ├── 支付宝
 └── 微信支付
       ↓
创建订单
       ↓
Payment Provider
       ↓
支付平台
       ↓
异步支付通知 Webhook
       ↓
验证通知签名
       ↓
更新 Payment
       ↓
更新 Order
       ↓
创建 / 激活 Subscription
       ↓
创建 / 更新 License
       ↓
发送订单 / License 邮件
```

### 支付宝

至少支持：

- PC 网站支付
- 手机网站支付
- 订单查询
- 关闭订单
- 退款
- 退款查询
- 异步通知
- 签名验证

如果未来需要二维码支付，可增加当面付 / 扫码支付能力。

### 微信支付

至少支持：

- Native 扫码支付
- JSAPI（如果未来有微信内网页场景）
- H5 支付（根据商户资质和实际场景）
- 订单查询
- 关闭订单
- 退款
- 退款查询
- 支付通知
- 通知签名验证

实际可用的支付产品以你的微信支付商户资质、经营主体和支付场景为准。

---

## 10.2 Payment Provider 抽象层

禁止在订单业务中直接写：

```php
if ($payment === 'alipay') {
    // 大量支付宝业务
}
```

推荐：

```text
PaymentProviderInterface
        │
        ├── AlipayProvider
        ├── WechatPayProvider
        ├── StripeProvider
        └── PayPalProvider
```

统一接口例如：

```text
createPayment()
queryPayment()
closePayment()
refundPayment()
queryRefund()
verifyWebhook()
handleWebhook()
```

这样以后增加：

```text
云闪付
Paddle
其他支付平台
```

不会重写订单系统。

---

## 10.3 Payment 数据模型

建议：

```text
payments
├── id
├── order_id
├── provider
├── method
├── provider_trade_id
├── amount
├── currency
├── status
├── idempotency_key
├── raw_response_reference
├── paid_at
├── created_at
└── updated_at
```

例如：

```text
provider = alipay
method = alipay_pc

provider = wechat
method = wechat_native

provider = stripe
method = card
```

---

## 10.4 金额处理

**禁止使用浮点数保存金额。**

例如：

```text
❌ 29.90 作为 float
```

推荐：

```text
amount_minor = 2990
currency = CNY
```

数据库保存最小货币单位。

对于人民币：

```text
¥29.90
↓
2990 分
```

同时保存：

```text
currency = CNY
```

这样可以避免金额精度问题。

---

## 10.5 支付状态机

支付必须使用明确状态，而不是简单的 `paid = 1`。

建议：

```text
pending
 ↓
processing
 ↓
paid
```

异常状态：

```text
failed
closed
cancelled
refunded
partially_refunded
```

订单状态与支付状态分开。

例如：

```text
Payment = paid
Order = completed
Subscription = active
License = active
```

---

## 10.6 Webhook / 异步通知

支付宝和微信支付都应该以**服务端异步通知 + 服务端主动查询**作为支付确认的重要依据。

绝不能：

```text
用户浏览器跳转成功
 ↓
直接认为付款成功
```

必须：

```text
支付平台
 ↓
Webhook
 ↓
验证签名
 ↓
验证商户 / App / 订单
 ↓
验证金额
 ↓
验证币种
 ↓
验证订单号
 ↓
幂等处理
 ↓
更新支付状态
```

必要时再调用支付平台查询接口进行二次确认。

---

## 10.7 幂等性

Webhook 可能重复发送。

例如：

```text
支付成功通知
支付成功通知
支付成功通知
```

系统只能：

```text
第一次 → 更新订单
第二次 → 检查已处理，直接返回成功
第三次 → 检查已处理，直接返回成功
```

建议使用：

```text
provider + provider_trade_id
```

以及：

```text
idempotency_key
```

建立唯一约束。

---

## 10.8 金额与订单一致性

收到支付通知后必须重新验证：

```text
订单号
商户号
商品
金额
币种
支付状态
交易号
```

不能只验证：

```text
trade_status = SUCCESS
```

例如订单金额是：

```text
¥99
```

但通知金额是：

```text
¥0.99
```

必须拒绝。

---

## 10.9 退款

退款流程：

```text
Customer
 ↓
Refund Request
 ↓
Order
 ↓
Payment Provider
 ↓
Alipay / WeChat / Stripe / PayPal
 ↓
Refund Webhook / Query
 ↓
Payment Update
 ↓
Order Update
 ↓
Subscription Update
 ↓
License Update
```

不要因为退款就物理删除 License。

建议：

```text
退款成功
 ↓
Subscription = cancelled
 ↓
License = suspended / revoked
```

具体策略根据产品的退款政策决定。

---

## 10.10 支付凭证与敏感信息

不要把完整支付响应、证书私钥、API Secret、微信支付密钥等直接写入普通日志。

必须：

- Secret 放环境变量或 Secret Manager
- 支付私钥禁止提交 Git
- 微信支付 API 密钥禁止提交 Git
- 日志脱敏
- 支付通知原文如需保存，应限制访问
- 后台只显示必要信息

推荐：

```text
.env
Secret Manager
Server Environment
```

而不是：

```text
config.php
```

里直接写生产密钥。

---

## 10.11 中国支付与海外支付共存

推荐订单统一使用：

```text
Order
 ↓
Payment
 ↓
Subscription
 ↓
License
```

不要让支付宝订单和 Stripe 订单使用两套业务逻辑。

例如：

```text
订单 #10001
产品：AT8 Security Pro
金额：¥199
支付方式：支付宝
状态：Paid
License：XXXX
```

或者：

```text
订单 #10002
产品：AT8 Security Pro
金额：$29
支付方式：Stripe
状态：Paid
License：XXXX
```

最终都进入同一套：

```text
Customer
Order
Subscription
License
```

---

## 10.12 推荐支付优先级

AT8 面向中国市场时：

```text
V1
├── 支付宝
└── 微信支付

V2
├── Stripe
└── PayPal

V3
└── 其他支付渠道
```

如果未来同时服务中国大陆和海外客户，可以根据用户地区和币种动态展示支付方式。


---

# 11. Audit Log

平台必须记录关键操作。

建议表：

```text
at8_audit_logs
```

记录：

```text
id
actor_id
action
object_type
object_id
ip
created_at
metadata
```

例如：

```text
license.created
license.activated
license.deactivated
license.revoked
license.suspended

site.added
site.removed

release.created
release.published
release.withdrawn

subscription.created
subscription.cancelled
subscription.refunded
```

---

# 12. 安全标准

所有 WordPress 产品统一遵守：

## 输入

```text
sanitize
validate
```

## 输出

```text
escape
```

## 数据库

```text
$wpdb->prepare()
```

## 管理操作

必须：

```text
current_user_can()
nonce
```

## REST API

必须：

- 参数验证
- 权限检查
- Rate Limit
- 错误信息最小化
- 不泄露数据库信息

---

# 13. REST API 安全

不要因为接口是：

```text
POST
```

就认为安全。

每个接口明确：

```text
Public
Authenticated
License Authenticated
Admin
Internal
```

例如：

```text
/activate
Public + Rate Limit

/validate
License Authenticated

/deactivate
License Authenticated

/package
License Authenticated + Signed Token

/admin/*
Admin
```

---

# 14. Rate Limit

建议至少分级：

```text
正常 License 请求
宽松限制

未知 License
严格限制

管理 API
更严格

登录 / 激活
防暴力攻击
```

同时：

- Cloudflare
- 可信代理
- 正确 IP 获取
- 不盲信 `X-Forwarded-For`

---

# 15. 数据隐私

任何外部服务都必须明确：

```text
发送什么
为什么发送
什么时候发送
保存多久
谁能访问
如何删除
```

特别注意：

- IP
- 域名
- WordPress 版本
- PHP 版本
- 用户邮箱
- 错误日志
- 网站数据
- 使用统计

不要默认收集与产品功能无关的数据。

---

# 16. WordPress.org 产品结构

WordPress.org 免费产品：

```text
at8-product/
├── at8-product.php
├── includes/
├── admin/
├── public/
├── assets/
├── languages/
├── tests/
├── uninstall.php
├── readme.txt
├── LICENSE
├── AGENTS.md
└── .github/
```

---

# 17. WordPress.org 上架流程

标准流程：

```text
开发
 ↓
Git Commit
 ↓
自动测试
 ↓
PHPStan
 ↓
PHPCS
 ↓
PHPUnit
 ↓
Plugin Check
 ↓
安全检查
 ↓
Readme 检查
 ↓
GPL / 第三方 License 检查
 ↓
打包 ZIP
 ↓
提交 WordPress.org
 ↓
审核
 ↓
SVN
 ↓
Release
 ↓
自动更新
```

---

# 18. WordPress.org 必须关注

提交前检查：

- 插件名称
- Plugin Header
- `Requires at least`
- `Requires PHP`
- `Tested up to`
- `Stable tag`
- GPL License
- `readme.txt`
- 截图
- Changelog
- Privacy
- External Services
- 第三方依赖
- 商标
- 图片 / 字体 / Icon 授权

---

# 19. External Services

如果免费插件连接 AT8 Cloud：

`readme.txt` 必须明确说明：

```text
== External Services ==

This plugin connects to AT8 services for ...

Service:
https://www.at8.fun/

Data sent:
...

When data is sent:
...

Purpose:
...

Privacy Policy:
...

Terms:
...
```

如果某功能不需要云服务，应允许用户不连接。

---

# 20. AI Agent 开发架构

每个项目必须包含：

```text
AGENTS.md
```

建议：

```text
/docs/
├── architecture.md
├── api.md
├── security.md
├── privacy.md
├── database.md
├── testing.md
├── release.md
└── wordpress-org.md
```

---

# 21. AGENTS.md 核心规则

Agent 必须遵守：

```text
1. 修改前先阅读项目架构。
2. 不得擅自改变数据库结构。
3. 不得删除现有功能。
4. 不得引入未经批准的第三方依赖。
5. 不得写死 API Key。
6. 不得关闭安全检查。
7. 所有输入必须 sanitize / validate。
8. 所有输出必须 escape。
9. 数据库查询必须安全。
10. 后台操作必须 capability + nonce。
11. REST API 必须有权限策略。
12. 修改后必须运行测试。
13. 失败测试不得假装通过。
14. 不得为了修复小问题重写整个项目。
15. 涉及架构的修改必须先说明影响。
16. 不得删除审计日志。
17. 不得绕过 License / Subscription 业务规则。
18. 不得收集未经批准的用户数据。
19. 不得把秘密写入 Git。
20. 发布前必须完成 Release Checklist。
```

---

# 22. CI/CD

推荐：

```text
GitHub
   ↓
Push / Pull Request
   ↓
GitHub Actions
   ├── PHP Syntax
   ├── PHPCS
   ├── PHPStan
   ├── PHPUnit
   ├── Plugin Check
   ├── Security Scan
   └── Build ZIP
```

Release：

```text
Git Tag
v1.2.0
   ↓
CI
   ↓
测试
   ↓
Build
   ↓
SHA256
   ↓
Release
```

---

# 23. 测试环境

至少准备：

```text
WordPress Stable
PHP 8.2
PHP 8.3
PHP 8.4
```

根据产品兼容性扩展。

测试：

- 新安装
- 激活
- 停用
- 删除
- 升级
- 降级 / 回滚
- 数据迁移
- 权限
- 多站点
- REST API
- AJAX
- Cron
- WooCommerce
- Gutenberg
- Elementor

只测试产品实际声明支持的环境。

---

# 24. 生产环境与测试环境

必须分开：

```text
Production
at8.fun

Staging
staging.at8.fun

Development
dev.at8.fun
```

禁止：

- 开发环境调用生产数据库
- 测试数据写入生产
- Agent 直接修改生产数据库
- 测试 License 与真实客户 License 混用

---

# 25. Git 分支

推荐简单模式：

```text
main
develop
feature/*
fix/*
release/*
```

个人开发也可以简化为：

```text
main
feature/*
```

核心原则：

**生产代码必须来自 Git Tag / Release。**

---

# 26. 版本号

使用 Semantic Versioning：

```text
MAJOR.MINOR.PATCH
```

例如：

```text
1.0.0
1.1.0
1.1.1
2.0.0
```

规则：

```text
MAJOR = Breaking Change
MINOR = New Feature
PATCH = Bug Fix
```

WordPress 产品的更新信息必须与 Git Release 保持一致。

---

# 27. 数据库迁移

禁止：

```text
插件激活时直接 DROP TABLE
```

采用：

```text
db_version
```

例如：

```text
1
 ↓
2
 ↓
3
 ↓
4
```

每次升级执行对应 migration。

必须支持：

```text
旧版本
 ↓
升级
 ↓
新版本
```

而不是要求用户删除插件重新安装。

---

# 28. 卸载策略

必须区分：

## Deactivate

不删除数据。

## Delete

根据产品政策清理数据。

## License Server

商业记录原则上不要因为 WordPress 插件删除而删除。

---

# 29. 日志策略

日志必须：

- 控制大小
- 定期清理
- 不记录密码
- 不记录 API Key
- 不记录 License 完整明文
- 不记录敏感用户数据
- 提供 Debug 模式

---

# 30. 第三方依赖

所有依赖必须记录：

```text
package
version
license
source
purpose
```

升级依赖前：

```text
License Check
Security Check
Compatibility Test
```

不要把不兼容许可证的代码打包进商业产品。

---

# 31. 产品发布 Checklist

发布前：

```text
[ ] 版本号正确
[ ] Changelog
[ ] PHP 兼容
[ ] WordPress 兼容
[ ] PHPStan
[ ] PHPCS
[ ] PHPUnit
[ ] Plugin Check
[ ] Security Scan
[ ] Readme
[ ] License
[ ] 第三方依赖
[ ] Privacy
[ ] External Services
[ ] 更新机制
[ ] 数据库 Migration
[ ] 升级测试
[ ] 新安装测试
[ ] 卸载测试
[ ] ZIP 内容检查
[ ] SHA256
[ ] Git Tag
[ ] Release
```

---

# 32. 商业产品发布 Checklist

额外：

```text
[ ] Product
[ ] Plan
[ ] Price
[ ] Customer
[ ] Order
[ ] Subscription
[ ] License
[ ] Site Activation
[ ] Update Server
[ ] Payment Webhook
[ ] Refund Flow
[ ] Email
[ ] Terms
[ ] Privacy
[ ] Support
[ ] Documentation
```

---

# 33. 推荐目录结构

平台：

```text
at8-platform/
├── customers/
├── orders/
├── subscriptions/
├── licenses/
├── sites/
├── products/
├── plans/
├── releases/
├── audit/
├── api/
├── billing/
└── docs/
```

插件：

```text
at8-product/
├── at8-product.php
├── includes/
│   ├── Admin/
│   ├── API/
│   ├── Database/
│   ├── License/
│   ├── Update/
│   ├── Services/
│   └── Security/
├── admin/
├── public/
├── assets/
├── languages/
├── tests/
├── docs/
├── AGENTS.md
├── readme.txt
├── LICENSE
└── uninstall.php
```

---

# 34. 最终产品生命周期

```text
Idea
 ↓
Specification
 ↓
Architecture
 ↓
Development
 ↓
Unit Test
 ↓
Integration Test
 ↓
Security Test
 ↓
WordPress Compatibility
 ↓
Plugin Check
 ↓
Release Candidate
 ↓
Staging
 ↓
Production
 ↓
WordPress.org / Commercial Release
 ↓
Monitoring
 ↓
Bug Fix
 ↓
Update
 ↓
End of Life
```

---

# 35. AI Agent 工作方式

Agent 不应该被当成“随便写代码的聊天机器人”。

正确模式：

```text
人类
 ↓
产品需求
 ↓
Agent 分析
 ↓
Agent 提出实施方案
 ↓
人类确认重大架构
 ↓
Agent 编码
 ↓
自动测试
 ↓
Agent 修复
 ↓
Code Review
 ↓
人类验收
 ↓
Release
```

重大修改必须经过人工确认：

- 数据库结构
- License 逻辑
- 支付
- 权限
- 用户数据
- 外部服务
- 安全策略
- 删除数据
- Breaking Change

---

# 36. 核心原则

以后所有 AT8 WordPress 产品都遵循：

> **产品代码独立、平台能力共享、License 商业化、云服务创造价值、Git 管理代码、CI 保证质量、Agent 加速开发、人类负责最终决策。**

最终形成：

```text
                    AT8 产品工厂

       ┌──────────┬──────────┬──────────┐
       │ Security │   SEO    │   Woo    │
       └────┬─────┴────┬─────┴────┬─────┘
            │          │          │
            └──────────┼──────────┘
                       ▼
                 AT8 Platform
                       │
       ┌───────────────┼────────────────┐
       ▼               ▼                ▼
    Customer        License          Update
       │               │                │
       └───────────────┼────────────────┘
                       ▼
                  Cloud Services
                       │
                       ▼
                  AI / Agent
                       │
                       ▼
                 GitHub + CI/CD
```

这套架构的目标不是只做好一个插件，而是让**第二个、第三个、甚至十个 WordPress 产品都能复用同一套基础设施**。
