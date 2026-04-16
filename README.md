# OpenTranslation

OpenTranslation 是一个面向 [TranslatePress](https://translatepress.com/) 的自动翻译适配插件。

它的作用不是自己做页面翻译，而是把 TranslatePress 的自动翻译请求接管下来，转发到你配置的 AI 大模型，再把翻译结果写回 TranslatePress 词库。这样你可以继续使用 TranslatePress 的页面扫描、语言切换和词库体系，同时把实际翻译能力切换成 OpenAI、Claude 或兼容它们接口的第三方模型网关。

## 插件定位

这个插件适合下面几类场景：

- 你已经在用 TranslatePress，但不想继续使用默认的自动翻译服务。
- 你希望把自动翻译切换到自己可控的 AI 模型。
- 你需要主模型与备用模型的故障切换能力。
- 你希望翻译任务尽量在后台异步完成，避免前台访问被实时翻译拖慢。

## 核心特性

- 接入 TranslatePress，自定义自动翻译引擎 `OpenTranslation AI`
- 支持 `OpenAI` 与 `Claude` 两类 Provider
- 支持 OpenAI / Claude 兼容网关，自定义 `Base URL`
- 支持多模型优先级与自动降级
- 支持模型连通性测试与结构化诊断输出
- 支持缓存、失败重试、重试退避
- 支持后台异步队列翻译
- 支持按语言暂停 / 恢复队列
- 支持 URL、邮箱、媒体路径、协议链接等内容直通，不占模型请求
- 支持占位符保护，尽量避免 HTML、短代码、变量被错误翻译
- 默认关闭前台实时翻译，避免页面请求被同步模型调用拖慢

## 工作原理

OpenTranslation 的整体链路如下：

1. TranslatePress 在设置中选择自动翻译引擎 `OpenTranslation AI`
2. 页面内容被 TranslatePress 扫描并进入词库
3. OpenTranslation 从 TranslatePress 词库中取出未翻译内容
4. 插件根据规则判断：
   - 可直通内容直接回写原文
   - 已有缓存的内容直接回填
   - 其余内容提交给 AI 模型翻译
5. 翻译结果写入 OpenTranslation 缓存表
6. 最终批量写回 TranslatePress 的字典表

默认情况下，前台页面访问不会主动触发实时模型翻译。翻译主要由后台异步队列完成，这样可以显著降低前台 504、超时和首屏卡顿的风险。

## 当前运行策略

为了保证前台访问稳定，插件当前采用以下策略：

- **前台实时翻译默认关闭**
  - 管理后台
  - Ajax
  - Cron
  - REST
  - WP-CLI
  这些上下文允许触发翻译。
  普通前台访问默认不触发实时模型请求。

- **后台优先异步处理**
  - 优先使用 `Action Scheduler`
  - 如果不可用，则回退到 `WP-Cron`

- **后台队列已做吞吐优化**
  - 一次异步 worker 可在时间预算内连续处理多轮任务
  - 翻译批次会同时参考“条数上限”和“字符预算上限”切块
  - 长段落会自动拆成更小批次，减少模型超时或空响应概率
  - 已有缓存结果会直接回填到 TranslatePress 词库
  - URL 等明显无需翻译的内容会走 fast-path

## 环境要求

- WordPress 5.8+
- PHP 7.4+
- 已安装并启用 TranslatePress

建议环境：

- 允许 `WP-Cron` 正常运行，或服务器已配置真实 cron
- 站点可正常访问你配置的 AI Provider / 网关

## 安装方法

1. 将插件目录放到 WordPress 的插件目录：

```text
wp-content/plugins/opentranslation
```

2. 进入 WordPress 后台，启用 `OpenTranslation`
3. 确保 `TranslatePress` 已启用

启用插件后，OpenTranslation 会自动创建以下数据表：

- `wp_opentranslation_cache`
- `wp_opentranslation_log`
- `wp_opentranslation_rate_limit`

实际前缀取决于你的 WordPress 表前缀，不一定是 `wp_`。

## 快速开始

### 1. 添加模型

进入：

```text
OpenTranslation -> Models
```

你可以添加一个或多个模型。每个模型包含以下配置：

- `Provider`
  - `OpenAI`
  - `Claude`
- `API Key`
- `Base URL`
  - 可留空使用官方地址
  - 也可以填写兼容网关地址
- `Model`
- `Priority`
  - 数字越小优先级越高
- `Temperature`
- `Max Tokens`

说明：

- 如果主模型失败，插件会按优先级自动切换到备用模型。
- 模型页支持“获取模型列表”和“测试”。

### 2. 配置插件设置

进入：

```text
OpenTranslation -> Settings
```

主要配置项如下：

- `Batch Size`
  - 每轮模型翻译的目标条数上限
- `Cron Interval (minutes)`
  - 非 Action Scheduler 场景下的轮询间隔
- `Rate Limit (requests/min)`
  - 后台每分钟请求单位上限
- `System Prompt`
  - 模型翻译提示词
- `Plugin Language`
  - 插件后台界面语言

### 3. 在 TranslatePress 中选择引擎

进入 TranslatePress 的自动翻译设置，将自动翻译引擎切换为：

```text
OpenTranslation AI
```

保存后，点击 `Test API Credentials`。

如果配置正确，测试不会再走 TranslatePress 官方 MTAPI，而是返回 OpenTranslation 的诊断结果。

### 4. 触发后台队列

进入：

```text
OpenTranslation -> Queue & Logs
```

你可以：

- 查看待处理 / 已翻译 / 失败数量
- 查看当前队列执行器
- 手动点击 `Run Queue Now`
- 对失败项点击 `Retry Failed`
- 按语言暂停 / 恢复翻译
- 查看最近日志

## 推荐配置

如果你正在使用不太稳定、容易返回空响应或 524 的兼容模型，建议从下面的保守配置开始：

- `Batch Size`: `10 ~ 20`
- `Temperature`: `0.1 ~ 0.3`
- `Max Tokens`: 先用 `0`，如果模型输出经常被截断，再适当调高
- 至少配置一个备用模型

如果你希望优先追求吞吐，可以这样调整：

- `Batch Size`: `20`
- `Rate Limit`: 根据你的网关额度提高
- 保持默认后台异步模式，不要依赖前台实时翻译

## 管理页面说明

### Settings

用于配置后台运行参数和系统提示词。

### Models

用于管理模型列表。支持：

- 新增模型
- 删除模型
- 测试模型
- 设置主备顺序

### Queue & Logs

用于观察后台翻译状态。你可以看到：

- `Pending`
- `Translated`
- `Failed`
- 当前由 `Action Scheduler` 还是 `WP-Cron` 驱动
- 下次运行时间
- 各语言剩余未翻译数量
- 最近日志明细

## 缓存、重试与失败处理

插件内置了如下机制：

- 翻译结果缓存
  - 相同源文本、目标语言、上下文会复用缓存结果

- 重试退避
  - 临时失败不会立即标记为永久失败
  - 会按退避策略等待下次重试

- 模型降级
  - 主模型失败后，自动切换下一个优先级模型

- 空响应 / 524 处理
  - 对兼容网关常见的 `HTTP 524 + empty body`
  - 插件会自动重试
  - 对部分失败场景还会进一步拆小批次再试

## TranslatePress 接入说明

插件通过 TranslatePress 提供的过滤器把自己注册成一个新的自动翻译引擎。

注册后的引擎名称为：

```text
OpenTranslation AI
```

因此只要 TranslatePress 正常工作，OpenTranslation 就能沿用它的：

- 语言配置
- 字典表
- 自动翻译入口
- 页面语言切换逻辑

## 性能与稳定性说明

当前版本已经针对后台吞吐做过几轮优化，重点包括：

- 后台队列多轮连续执行
- 长文本与短文本分开切块
- 直通内容不占模型请求
- 缓存命中内容直接回填
- 请求单位记录改为更接近真实 HTTP 尝试次数

如果你的模型网关质量一般，仍可能看到以下日志：

- `HTTP 524`
- `OpenAI-compatible endpoint returned empty content`
- `Model response count does not match request count`

这不一定表示插件逻辑有问题，很多时候是上游模型或网关返回不稳定。插件会尽量通过重试、切块、降级模型来继续推进队列。

## 常见问题

### 1. Test API Credentials 通过了，但页面还是没立刻翻译

这是正常现象。

默认策略下，前台普通访问不会触发实时模型翻译。翻译主要依赖后台队列完成。请到 `Queue & Logs` 页面确认：

- 模型已配置
- TranslatePress 已选择 `OpenTranslation AI`
- 自动翻译已启用
- 队列在运行
- 目标语言没有被暂停

### 2. 前台页面不应该再出现 504 吗？

按当前实现，普通前台访问默认不会同步调用模型，所以前台 504 风险已经大幅下降。

如果你又通过代码或过滤器手动开启了前台实时翻译，就可能再次把模型延迟带回前台请求链路。

当前默认过滤行为是：

```php
apply_filters( 'opentranslation_allow_frontend_live_translation', false )
```

也就是说，除非你显式开放，否则前台实时翻译保持关闭。

### 3. `Run Queue Now` 点了以后看起来没反应

当前实现会优先把任务排到异步队列，而不是在管理页里同步跑完整翻译。这样是为了避免后台页面卡住。

请到 `Queue & Logs` 页面查看：

- `Next Run`
- `Queue Runner`
- 最新日志

### 4. 站点定义了 `DISABLE_WP_CRON`

如果站点禁用了 `WP-Cron`，你需要：

- 确保 `Action Scheduler` 可以运行，或
- 服务器层面配置真实 cron

否则后台队列不会自动推进。

### 5. 模型经常返回空响应或 524

建议按下面顺序排查：

1. 降低单模型压力
2. 减少 `Batch Size`
3. 保持 `Temperature` 较低
4. 配置备用模型
5. 更换更稳定的网关或模型

### 6. 为什么有些内容没有被翻译？

有一类内容会被插件判断为无需翻译，直接回填原文，例如：

- URL
- 邮箱
- `mailto:` / `tel:` / `sms:` / `fax:`
- 图片、视频、PDF 等资源路径
- 一些明显像语言标识或技术标记的内容

这样做是为了减少无意义模型调用，提升吞吐。

## 日志与诊断

你可以在 `Queue & Logs` 页面直接查看最近日志。

日志里常见的动作包括：

- `scheduler_run`
- `model_fallback`
- `retry`
- `failed`
- `placeholder_restored`

如果要排查连通性问题，优先看：

- 模型测试结果
- `model_fallback`
- `retry`
- `failed`

如果要排查翻译完整性问题，优先看：

- `placeholder_restored`

## 数据安全与存储说明

插件会保存：

- 模型配置
- 翻译缓存
- 失败状态
- 运行日志
- 请求单位窗口统计

其中模型配置通过插件内部的加密选项封装保存，不直接依赖 TranslatePress 的 API Key 字段。

## 卸载说明

停用插件会清理队列调度。

卸载插件会删除 OpenTranslation 创建的数据表：

- 缓存表
- 日志表
- 请求单位表

如果你希望保留历史翻译缓存或日志，请在卸载前自行备份数据库。

## 适合你的使用方式

如果你的目标是“站点前台稳定，后台慢慢把词库补全”，推荐这样使用：

- 前台实时翻译保持关闭
- TranslatePress 继续负责页面扫描与语言结构
- OpenTranslation 只负责后台异步翻译和词库回填
- 主模型外再配一个备用模型
- 定期在 `Queue & Logs` 页面观察失败和积压情况

## 免责声明

OpenTranslation 负责 TranslatePress 对接、缓存、队列、重试和回写流程。

最终翻译质量、延迟和稳定性，仍然取决于你所使用的模型本身，以及对应网关的可用性与吞吐能力。
