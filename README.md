# OpenTranslation

OpenTranslation 是一个面向 [TranslatePress](https://translatepress.com/) 的自动翻译适配插件。

它的作用不是自己做页面翻译，而是把 TranslatePress 的自动翻译请求接管下来，转发到你配置的 AI 大模型，再把翻译结果写回 TranslatePress 词库。这样你可以继续使用 TranslatePress 的页面扫描、语言切换和词库体系，同时把实际翻译能力切换成 OpenAI、Anthropic 或兼容它们接口的第三方模型网关。

## 插件定位

这个插件适合下面几类场景：

- 你已经在用 TranslatePress，但不想继续使用默认的自动翻译服务。
- 你希望把自动翻译切换到自己可控的 AI 模型。
- 你需要主模型与备用模型的故障切换能力。
- 你希望翻译任务尽量在后台异步完成，避免前台访问被实时翻译拖慢。

## 核心特性

- 接入 TranslatePress，自定义自动翻译引擎 `OpenTranslation AI`
- 支持 `OpenAI` 与 `Anthropic` 两类 Provider（即 Chat Completions 与 Messages 两种接口协议）
- 支持 OpenAI / Anthropic 兼容网关，自定义 `Base URL`
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
  - `OpenAI`：Chat Completions 协议
  - `Anthropic`：Messages 协议
- `API Key`
- `Base URL`
  - 留空时使用 Provider 官方地址：OpenAI 为 `https://api.openai.com/v1/`，Anthropic 为 `https://api.anthropic.com/v1/`
  - 也可以填写兼容网关地址
  - 必须是 `https`，且不接受内网 / 保留地址（回环、私有网段、云元数据地址等）；自建网关如需豁免可用 `opentranslation_allowed_base_url_hosts` 过滤器
- `Model`
- `Priority`
  - 数字越小优先级越高
  - 每个模型应设置不同的优先级，重复时降级顺序不可预期，页面会给出告警
- `Temperature`
  - 有效范围 0-2，超出会被夹取到边界
- `Max Tokens`
  - 填 `0` 表示由模型决定
  - Anthropic 因 API 要求 `max_tokens >= 1`，填 0 时插件自动使用 4096

说明：

- 如果主模型失败，插件会按优先级自动切换到备用模型。
- 模型页支持“获取模型列表”、“测试”和“编辑”。编辑时 API Key 留空表示保持原值，不需要重新粘贴密钥。
- “测试”是只读操作，不会改写已保存的 Base URL。

### 2. 配置插件设置

进入：

```text
OpenTranslation -> Settings
```

主要配置项如下：

- `Batch Size`
  - 每轮模型翻译的目标条数上限
- `Cron Interval (minutes)`
  - 仅 WP-Cron 模式生效。若站点已安装 Action Scheduler（如随 WooCommerce），队列以固定 1 分钟间隔驱动，此项被忽略。实际驱动方式见 Queue 页的 `Queue Runner`
- `Rate Limit (requests/min)`
  - 后台每分钟请求单位上限（一次 HTTP 尝试计 1 个单位，重试与切块都会累加）
  - 填 `0` 表示完全不限流（不推荐，可能导致 API 费用失控）。该限制全局共享，不区分模型
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
- 编辑模型（API Key 留空保持原值）
- 删除模型
- 测试模型
- 设置主备顺序
- 列表展示 Temperature、Max Tokens、完整 Base URL、API Key 长度

### Glossary

术语表。让品牌名、产品型号、专有名词在所有语言中保持固定译法或原样不翻译。支持新增 / 编辑 / 删除、按语言筛选（带计数 tab）。

- 术语在**送交模型之前**被替换为占位符，模型看不到该词，译文中该位置固定为你指定的译法——这是硬保证，不依赖模型自觉
- `Target` 留空 = 不翻译（与原文保持一致）
- 可按语言限定（`All languages` 或某个目标语言）
- 可设置区分大小写与全词匹配（全词匹配不命中 `sensors` 里的 `sensor`，CJK 邻接视为词尾，故 `LED灯` 仍命中 `LED`）
- tab 计数为「生效于该语言」的术语数（通用 + 该语言专属）

**适用范围**：品牌名、产品型号、专有名词。术语在句中会变成不透明 token，模型失去该词的语义，因此**不适用于普通词汇**，尤其俄语等有词形变化的语言会产生生硬译文。

**注意**：术语只影响之后的翻译。已缓存、已写回的译文不会自动重翻，需在 TranslatePress 编辑器中人工修改。

`Source` 不得含尖括号、不得只由数字组成，也不得匹配 `protect` 或 TranslatePress 自身的 `1TP1T` 形式占位符——这类输入保存时会被直接拒绝。

### Queue & Logs

用于观察后台翻译状态。你可以看到：

- `Cache Overview`：缓存表的全局 `Pending` / `Translated` / `Failed`（所有语言合计）
- 当前由 `Action Scheduler` 还是 `WP-Cron` 驱动
- 下次运行时间
- `Last Run`：上一次队列执行的统计——写回条数、来自模型 / 缓存 / 直通的条数、失败数、API 请求单位、按语言计数、耗时
- 各语言的 TP 未翻译数与缓存表的按语言计数，失败数可点击跳转到 `Failures` 页
- 日志：按动作筛选、分级着色、分页

### Usage

用量看板。按 UTC 日期与模型聚合每次模型调用的请求次数与 token 消耗（prompt / completion / total），展示最近 30 天明细、按模型汇总与本月累计。可为每个模型填写每千 token 单价，页面据此估算费用。估算值仅供参考，实际以服务商账单为准。若某模型的响应不含 usage 字段，页面会标注「该模型未返回用量数据」而非显示 0 成本。

### Failures

失败条目详情页。列出已达到最大重试次数的条目，显示原文、语言、重试次数、最后一条日志消息，支持按语言筛选与单条 `Retry`。单条重试会把该条目重置为待翻译，在下次队列执行时再试一次。

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
  - OpenAI 与 Anthropic 两类 Provider 均支持重试与失败切块，重试次数与延迟可用 `opentranslation_openai_max_attempts` / `opentranslation_claude_max_attempts` 等过滤器调整

- 占位符保护（严格模式）
  - 每个条目使用独立的占位符映射
  - 若模型返回的译文缺失占位符，或残留未知的 `<protect-N>`，该条目判为失败并进入重试
  - 重试 3 次后标记 `failed`，保持原文不翻译，可在 `Failures` 页人工处理
  - 设计上宁可不翻译，也不产出破损 HTML
  - TranslatePress 自身的 `1TP1T` 形式占位符也会被保护

- 写回后清理 TP 缓存
  - 译文写回字典表后会调用 TranslatePress 的缓存清理入口（如该版本提供），带 5 分钟节流，可用 `opentranslation_tp_cache_clear_throttle` 过滤器调整

- 术语表（前置占位）
  - 术语在送交模型前被替换为占位符，`restore()` 时填入你指定的固定译法，译法不依赖模型自觉
  - 术语占位符与 HTML 占位符走同一条严格校验链路：模型吞掉或改坏术语占位符时，该条目同样判失败并重试
  - 术语只影响之后的翻译，已缓存 / 已写回的译文不会自动重翻

- 模型熔断
  - 客户端内部重试与切块全部用尽后仍失败的模型，连续失败 3 次进入熔断，时长指数退避：300 秒起，翻倍至上限 3600 秒
  - 熔断期内该模型不再被请求；有备用模型时自动降级，队列不停滞
  - 熔断到期后放行一次半开探测：成功则清零恢复，失败则失败次数 +1 并重新熔断
  - 所有模型都在熔断中时，本轮队列直接跳过（不查字典表、不标记任何条目失败），后台出现红色告警并可一键重置
  - Models 页每行显示健康状态（正常 / 连续失败 N / 熔断中剩余秒数 / 半开探测）、累计成功失败数与最后错误，可单独重置
  - 建议至少配置 2 个模型，避免单模型熔断后队列停滞

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

等待 30-60 秒后刷新 `Queue & Logs` 页面，`Last Run` 区块会显示这次执行的统计：写回条数、来自模型 / 缓存 / 直通各多少、失败数、API 请求单位、按语言计数。若 `Last Run` 没有更新，再查看：

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

你可以在 `Queue & Logs` 页面直接查看日志，支持按动作筛选和分页。

日志分四级并着色显示：

- `error`：`failed`、`tp_engine_error`、`tp_bulk_update_failed`、`cache_*_failed`
- `warn`：`retry`、`model_fallback`
- `info`：其它动作
- `debug`：`scheduler_run`（调度心跳），仅在 `WP_DEBUG` 开启时入库

日志默认保留 30 天，可用 `opentranslation_log_retention_days` 过滤器调整（1-365）。入库前会对 Bearer token、`sk-` 前缀密钥、`x-api-key` 字段值做打码。

如果要排查连通性问题，优先看：

- 模型测试结果
- `model_fallback`
- `retry`
- `failed`

如果要排查翻译完整性问题，筛选 `retry` 与 `failed`，看消息以 `Placeholder validation failed` 开头的记录；这类条目最终会出现在 `Failures` 页。

## 数据安全与存储说明

插件会保存：

- 模型配置
- 翻译缓存
- 失败状态
- 运行日志
- 请求单位窗口统计
- 按日与模型聚合的用量统计（`wp_opentranslation_usage`）

其中模型配置通过插件内部的加密选项封装保存，不直接依赖 TranslatePress 的 API Key 字段。

安全相关约定：

- 模型配置以 AES-256-GCM 加密存储，密钥派生自 `wp-config.php` 的 `AUTH_KEY`。**轮换 `AUTH_KEY` 会导致已存配置无法解密**，后台会给出明确提示，需要重新录入模型配置或恢复原 `AUTH_KEY`
- Base URL 只接受 `https`，并拒绝回环、私有网段、云元数据等内网 / 保留地址
- 对模型服务的请求禁止跟随重定向
- 后台不是 HTTPS 时，模型页会提示 API Key 将以明文经网络传输

## 已知技术债

- `class-claude-client.php` 与 `class-openai-client.php` 有约 120 行相似的重试 / 切块逻辑。两者请求体、响应体、错误结构都不同，抽公共基类的耦合可能比重复更差，留待出现第三个 Provider 时再评估。
- `class-scheduler.php`、`class-translator.php`、`class-claude-client.php`、`class-openai-client.php` 超过 300 行的单文件上限；`class-translator.php` 的 `translate_batch()` 与 `test_connection()` 超过 50 行的单函数上限。这些超限是内聚的，强拆会降低可读性，暂不处理。
- `Cache::set()` 的写后失效修复在无持久化对象缓存的环境下无法实证，将来上 Redis 后需补验。

## 卸载说明

停用插件会清理队列调度。

卸载插件会删除 OpenTranslation 创建的数据表：

- 缓存表
- 日志表
- 请求单位表
- 用量表

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
