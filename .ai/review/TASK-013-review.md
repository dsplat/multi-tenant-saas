Now I have a complete picture. Let me provide the review.

---

## Architecture

架构合理，三层职责清晰：`AiVideoService` 编排层 → `RunwayProvider` / `KlingProvider` 适配层 → HTTP 通信层。异步模型通过 `Queue::later` + 闭包实现，闭包内正确恢复 `TenantContext`，符合多租户队列场景。

`TenancyServiceProvider.php:157` 已注册 `AiVideoService` 为 singleton，`$providerCache` 缓存有效。与 `AiGatewayService` 的既有模式（`PROVIDER_CLASS_MAP` → `providerCache`）保持一致。

**无阻塞问题。**

## Code Quality

- PSR-12 合规，PHPDoc 使用中文注释且 `@return array{...}` shape 标注完整
- Provider 之间结构高度对称（`sendSubmit`、`throwHttpError`、`normalizeSubmitResponse`），存在可提取公共基类的空间，但不阻塞
- `KlingProvider:275` 已修正为 `video_operation_not_supported`，语义准确
- `elapsedSinceCreatedMs()` 已改为 `Carbon::now()->diffInMilliseconds($created)`，消除了之前的 `microtime(true)` 与 `$created->timestamp` 精度混搭问题

**无阻塞问题。**

## Type Safety

- 所有方法参数与返回值均有类型声明 ✓
- `resolveProvider()` 返回 `object`，两个 Provider 无公共接口，后续扩展时缺乏契约约束。建议定义 `VideoProviderContract` 接口。**不阻塞。**
- `$providerCache` 类型标注为 `array<string, object>`，同上

**无阻塞问题。**

## Security

- `sanitizeOptions()` 正确剔除 `api_key` / `authorization` / `headers` 敏感字段
- 纯 Eloquent 操作，无 SQL 注入风险
- API 服务无 HTML 输出，无 XSS 风险
- 轮询闭包仅捕获 `$requestId`（int）和 `$tenantId`（int），可安全序列化
- API Key 通过 `env()` + `config()` 读取，无硬编码

**无阻塞问题。**

## Performance

- **`storeVideoOutput()` (`AiVideoService.php:507`) 将整个视频下载到内存**：`(string) Http::get($url)->body()`。大视频（数百 MB）会导致 OOM。应考虑流式写入（`Http::withOptions(['sink' => $tempPath])->get($url)`）。**不阻塞，但生产环境有风险。**
- 轮询设计（10s 间隔 × 120 次 = 20 分钟上限）合理，`Queue::later` 闭包无法在任务提前完成时取消，会导致已无意义的空轮询。可接受。

**无阻塞问题。**

## Potential Bugs

1. **`storeBinary()` 临时文件保护** — `try/finally` 已正确包裹，`@unlink($tempPath)` 在 `finally` 块中执行。✓ 已修复

2. **`FileService` 导入** — `use MultiTenantSaas\Services\FileService` 已在第 16 行声明。✓ 已修复

3. **翻译 key 冗余** — `video_duration_not_supported`、`video_resolution_not_supported`、`video_task_not_completed` 已定义在 lang 文件中但代码未引用。冗余但不阻塞。

4. **`storeVideoOutput()` 无错误处理** — `Http::get($url)` 如果返回非 200 或网络异常，会抛出异常导致整个 `pollTask` 进入 `handlePollError` 路径并重新入队。这是合理的行为，但大文件场景下可能反复失败。

**无阻塞问题。**

---

## Verdict

**PASS**

前次 review 标记的 3 个【必须修复】问题在本次 diff 中均已修复：
1. ✅ `FileService` 导入已添加（`AiVideoService.php:16`）
2. ✅ `storeBinary()` 已用 `try/finally` 保护临时文件（`AiVideoService.php:543-566`）
3. ✅ `AiVideoService` 已在 `TenancyServiceProvider` 注册为 singleton（`TenancyServiceProvider.php:157`）

【建议改进】（非阻塞）：
1. `storeVideoOutput()` 使用 `Http::withOptions(['sink' => $tempPath])->get($url)` 流式写入，避免大视频 OOM
2. 为 `RunwayProvider` / `KlingProvider` 定义公共 `VideoProviderContract` 接口，`resolveProvider()` 返回接口类型
3. 清理 lang 文件中未使用的 key（`video_duration_not_supported`、`video_resolution_not_supported`、`video_task_not_completed`）
4. `calculateCost()` 当前固定返回 `0.0`，待计费模块接入后实现
