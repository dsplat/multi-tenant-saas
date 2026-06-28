## Architecture

**TASK-013 视频AI服务的三层结构清晰且职责分明：**

- `AiVideoService` 作为编排层，统一调度 `RunwayProvider` / `KlingProvider`，屏蔽提供商差异。对外暴露 `textToVideo` / `imageToVideo` / `editVideo` / `extractFrames` / `getTask` / `pollTask` 六个核心方法，与 task spec 中「文生视频 / 图生视频 / 视频编辑 / 帧提取 / 异步轮询 / 回调通知 / 任务管理」一一对应。
- 两个 Provider 仅负责 HTTP 通信与响应标准化（提交 → 轮询 → 输出解析），不触碰租户上下文 / 日志 / 存储，符合「Provider 放在 src/Services/Ai/ 并专注协议适配」的补充说明。
- 异步模型实现正确：提交后通过 `Queue::later()` + 闭包派发延迟轮询，闭包内恢复 `TenantContext` 再调用 `pollTask`——这是多租户异步任务的正确姿势（队列 worker 默认无租户上下文）。`PENDING/RUNNING` 计数重入队、`SUCCEEDED` 落盘通知、`FAILED` 终结通知、超时上限 `max_poll_attempts` 兜底，状态机完整。
- 请求日志复用 `ai_requests` 表（`AiGatewayService` 已建表），`metadata` JSON 列承载 `task_id` / `video_status` / `poll_attempts` / `usage` / `video`，未新增迁移——符合「集成：请求记录在 ai_requests 表」要求，且未越界改 schema。
- 结果存储通过 `FileService::upload()` 落盘并创建 `FileUpload` 记录（task spec 写作 FileUploadService，实际项目内为 `FileService`，与 `AiImageService` / 测试用例一致）。

## Code Quality

- PSR-12 合规，全面使用 PHP 8.1+ 特性：构造器属性提升（`protected TenantContextContract $tenantContext`）、`match` 表达式（operation 路由 / 错误码映射 / 扩展名映射）、`readonly` 风格的 `protected const` 映射表。
- 中文 PHPDoc 详尽，所有公开方法均给出 `@return array{...}` shape 标注与 `@throws` 说明。
- Provider 内 `STATUS_MAP` 将提供商原始状态（Runway 的 `THROTTLED`、Kling 的 `SUBMITTED`/`PROCESSING`/`SUCCEED`）统一归一为 `PENDING/RUNNING/SUCCEEDED/FAILED`，差异屏蔽到位。
- 错误处理分层：`ConnectionException` / `RequestException` / `Throwable` 三级捕获，HTTP 状态码 → 翻译 key 的 `match(true)` 映射（401/403/404/408/413/429/5xx），并 `Log::error` 记录上下文。
- `sanitizeOptions()` 在写日志前剔除 `api_key` / `authorization` / `headers`，符合敏感字段不落库规范。
- `KlingProvider::submitVideoEdit()` 显式抛 `ai.image_operation_not_supported`（Kling 不支持视频编辑），由 `AiVideoService::submit()` 的 `match` 路由到 Runway——能力差异处理得当。

## Type Safety

- 所有方法参数与返回值均有类型声明 ✓
- Provider 返回结构使用 `array{provider: string, task_id: string|null, status: string, usage: array<string, mixed>, raw: array<string, mixed>}` 形状标注 ✓
- `normalizeId(int|string $id): int`、`currentTenantIntId(): ?int` 等辅助方法类型严谨 ✓
- `normalizeStatus(mixed $raw): string` 对外部输入做 `strtoupper((string) $raw)` 兜底，未知状态默认 `PENDING`，防御性良好。

## Security

- API Key 全部经 `env()` + `config()` 读取，无硬编码密钥 ✓
- HTTP 请求统一 `Http::withToken($apiKey)->asJson()->timeout(...)`，超时可控 ✓
- 日志 `metadata` 写入前 `sanitizeOptions()` 清洗敏感字段 ✓
- 响应结构（`textToVideo` / `getTask` 返回值）不含 token / secret / key，仅含 `request_id` / `provider` / `model` / `task_id` / `status` / `video` 等业务字段 ✓
- 轮询闭包通过 `app(TenantContextContract::class)->storeTenantId()` 显式恢复租户上下文，跨租户串数据风险低 ✓

## Performance

- `AiVideoService::$providerCache` 缓存已实例化的 Provider，避免重复 `app($class)` 解析 ✓
- 异步轮询走 `Queue::later($interval, ...)`，主请求不阻塞，worker 端按配置间隔（默认 10s）延迟重试 ✓
- `max_poll_attempts`（默认 120）上限兜底，防止无限轮询占用队列 ✓
- 结果下载 `Http::get($url)->body()` 一次性载入内存——对短视频（通常 < 10MB）可接受；超长视频可后续改为流式存储，非本次阻塞项。

## Potential Bugs / Observations

1. **[非阻塞 / 越界]** `AiVideoService`、`RunwayProvider`、`KlingProvider` 未在 `TenancyServiceProvider` 注册为 singleton（仅 `AiGatewayService` 已注册，line 153）。框架规范要求「Service 类通过 TenancyServiceProvider 注册为 singleton」。但 `src/TenancyServiceProvider.php` 属于 TASK-013 **禁止修改** 范围，且服务可自动解析（`TenantContextContract` 已绑定、Provider 无构造器依赖）、测试全绿、兄弟服务 `AiTextService` 亦未注册——属一致的既有模式。建议后续统一补注册，不在本任务处理。

2. **[stub，已文档化]** `calculateCost()` 固定返回 `0.0`，注释说明待计费模块接入后按 (时长 × 分辨率单价) 实现。当前不影响功能。

3. **[设计，已文档化]** `extractFrames()` 返回均匀分布的帧时间点描述而非二进制帧（`ffmpeg` 未引入），注释明确说明供上层调度/离线渲染使用。符合 task spec「帧提取」最小可用形态。

4. **[配置]** `ai.video.storage_disk` 默认 `env('AI_VIDEO_STORAGE_DISK')`（未设则 null），`FileService::upload()` 收到 null 时走默认磁盘；测试中显式配 `local` 通过。生产建议显式设置。

## Tests

`tests/AiVideoServiceTest.php` — **22 tests / 69 assertions，全绿**，覆盖：
- 文生视频 Runway + Kling（task_id / 状态 / 请求体断言 / 日志落库）
- 图生视频（输入图片 URL 注入请求体验证）
- 视频编辑 Runway + Kling 不支持抛异常
- 帧提取（均匀时间戳 / count 非法 / 输入不存在）
- 轮询成功（视频落盘 `file_uploads` + 回调 SUCCEEDED）/ 运行中重试（attempts++ 重新入队）/ 失败（落库 + 回调）/ 超时（max=1 触发 FAILED）
- `getTask` 状态查询 + not found
- 参数校验（空 prompt / 超长 / 未知 provider / 不支持 model / 输入不存在）
- 上游 500 错误 → 日志 FAILED
- 默认 provider 路由 + 模型映射路由到 Kling

Http::fake / Queue::fake / Event::listen / Storage::fake 隔离充分，符合「测试中 mock HTTP 请求和队列」要求。

全量 phpunit 中 AiVideoServiceTest 全绿；其余失败均位于 CouponService / InvoiceService / TaxService / TenantOnboarding / TrialService 等其他任务的测试文件（405 路由 / schema 问题），属预存在失败，TASK-013 未新增失败。

## 翻译 key

视频相关 10 个 key（`video_input_not_found` / `video_prompt_too_long` / `video_resolution_not_supported` / `video_duration_not_supported` / `video_frame_count_invalid` / `video_task_not_found` / `video_task_not_completed` / `video_task_failed` / `video_task_timeout` / `video_output_unavailable`）在 `lang/zh_CN/ai.php` 与 `lang/en/ai.php` 同步追加；代码引用的 14 个共享 provider key（`invalid_prompt` / `provider_not_implemented` / `provider_not_configured` / `model_not_supported` / `provider_auth_failed` / `provider_permission_denied` / `provider_not_found` / `provider_timeout` / `provider_request_too_large` / `provider_rate_limited` / `provider_server_error` / `provider_api_error` / `provider_connection_error` / `image_operation_not_supported`）均已存在。**无缺失。**

---

## Verdict

**PASS**

验收标准逐条核对：
- [x] 文生视频功能正常（Runway + Kling 两个提供商）
- [x] 图生视频功能正常
- [x] 异步任务轮询机制正常（Queue::later 延迟重试 + 状态机 + 超时兜底）
- [x] 任务状态回调通知正常（`event('ai.video.task.updated', ...)`）
- [x] 结果通过 FileService 存储并返回 URL
- [x] 请求记录在 ai_requests 表
- [x] phpunit AiVideoServiceTest 22/22 全绿，未新增失败
- [x] 新增翻译 key 无缺失（zh_CN + en 同步）

提交：`d4abf2c feat(ai): TASK-013 视频 AI 服务`，7 files changed, 2332 insertions。

唯一非阻塞观察项为「服务未在 TenancyServiceProvider 注册 singleton」（越界，无法在本任务处理，建议后续统一补齐）。
