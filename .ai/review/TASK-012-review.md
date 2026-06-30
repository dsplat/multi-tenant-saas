## Architecture

整体架构清晰、分层合理：`AiImageService`（编排层）→ Provider（实现层）→ `FileService`（存储层）+ `AiRequest`（日志层），与现有 `AiGatewayService` 保持一致的设计模式。

**优点：**
- Provider 解析三层 fallback（options → model map → config）设计优雅，可扩展性好
- 复用 `FileService` 落盘 + `AiRequest` 日志模式，与文本 AI 保持一致
- Provider 实例缓存（`$providerCache`）避免重复实例化
- 临时文件生命周期管理完善：`finally` 块清理 + `cleanupTempPath` 防误删

**问题：**
- **缺少 `AiImageProviderContract` 接口**：`DalleProvider` 和 `StableDiffusionProvider` 通过鸭子类型共享方法签名，但没有正式的接口约束。项目已有 `AiProviderContract`（文本 AI），图片 AI 应对称地定义一个接口。当前新增第三个 provider 时，编译期无法检测方法缺失，只能靠运行时崩溃发现。不过这与视频 AI（`RunwayProvider`/`KlingProvider`）的模式一致，属于项目级的已知取舍，不阻塞本次提交。

## Code Quality

**优点：**
- 命名规范：方法名、变量名、常量名均符合 PSR-12 与 Laravel 惯例
- PHPDoc 完整度极高：每个公开方法都有详细的 `@param`、`@return`（含精确的数组 shape 类型）、`@throws`
- 中文注释质量好，解释了"为什么"而不仅是"是什么"
- 错误消息全部走 `trans()`，中英文翻译同步完备
- `sanitizeOptions()` 正确剔除了敏感字段（`api_key`、`authorization`、`headers`）

**问题：**
- **`throwHttpError()` 在两个 Provider 中完全重复**（`DalleProvider:177-200` 与 `StableDiffusionProvider:133-156`）：逻辑、match 表达式、日志格式完全一致，仅 provider 名称不同。建议提取到一个 `BaseImageProvider` 基类或 trait 中。
- **HTTP 异常处理 try/catch 模式重复**：`textToImage`、`editImage`、`sendGenerate`、`sendGenerateWithInitImage` 中相同的 `ConnectionException → RequestException → Throwable` 三层 catch 块重复了 5 次。可提取为 `sendRequest(Closure $callback, string $operation, string $model): Response` 辅助方法。
- **`AiImageService` 四个公开方法的 try/catch/finally 结构高度重复**（`textToImage`、`imageToImage`、`editImage`、`styleTransfer`），仅 inputPath 清理逻辑不同。可考虑提取 `executeWithLogging(callable $callback, ...)` 模板方法。

## Type Safety

**优点：**
- 所有方法参数和返回值均有类型声明
- PHPDoc 中使用了精确的数组 shape 类型（`array{file_upload_id: int, url: string, ...}`）
- `int|string` union type 用于 ID 参数，兼容全局 ID 与自增 ID 场景

**问题：**
- **`resolveProvider()` 返回 `object` 而非具体接口类型**（`AiImageService.php:351`）：由于缺少 `AiImageProviderContract`，返回值类型只能是 `object`，调用 `$provider->textToImage(...)` 时无 IDE 类型提示，也无法在编译期检测方法签名不匹配。
- **`normalizeId()` 对非数字字符串返回 `0`**（`AiImageService.php:689-692`）：`(int) 'abc'` 结果为 `0`，后续 `FileUpload::find(0)` 返回 null 然后抛异常——虽然不会造成数据问题，但错误信息（"输入图片不存在"）会误导调用者。建议对非数字字符串单独抛出参数非法异常。
- **`fetchImageBinary()` 中 `Http::get($url)` 无超时、无重试**（`AiImageService.php:439`）：如果提供商返回的 URL 响应缓慢，会阻塞整个请求。

## Security

**优点：**
- API Key 不写入日志 metadata（`sanitizeOptions()` 剔除）
- 所有用户输入（prompt）经过长度校验
- 租户隔离通过 `BelongsToTenant` trait + `TenantContextContract` 实现
- 文件存储路径包含 UUID，不可预测
- `storage_is_public` 默认 `false`，生成文件默认私有

**问题：**
- **`fetchImageBinary()` 下载外部 URL 无 SSRF 防护**（`AiImageService.php:438-439`）：`Http::get($url)` 直接请求提供商返回的 URL。虽然 URL 来自受信任的 API 响应（DALL-E/Stability），但如果 API 响应被篡改或提供商返回内网地址，存在 SSRF 风险。建议至少校验 URL scheme 为 `https` 且非内网 IP。
- **临时文件权限未显式设置**：`tempnam()` 创建的文件使用 umask 默认权限，在共享主机环境可能被其他用户读取。建议 `chmod($tempPath, 0600)`。
- **`editImage` 的 maskPath 来源于用户选择的 FileUpload ID**：通过 `getInputFile()` 查询并受租户作用域过滤，租户隔离有效，无越权风险。

## Performance

**优点：**
- Provider 实例缓存避免重复构造
- 临时文件及时清理（`finally` + `@unlink`）
- 图片 base64 解码在内存中完成，无额外 I/O（对于小图片合理）

**问题：**
- **大图片 base64 全量加载到内存**：`file_get_contents($imagePath)` 将整个图片读入内存（`DalleProvider.php:326`、`StableDiffusionProvider.php:261`、`398`），对于高分辨率图片（如 1792×1024 PNG）可能消耗数十 MB 内存。多个并发请求时有 OOM 风险。但这是 HTTP client multipart upload 的固有限制，短期可接受。
- **`fetchImageBinary()` 中 URL 下载无流式处理**（`AiImageService.php:439`）：`Http::get($url)->body()` 一次性读入内存。对于 DALL-E 返回的 URL 场景（非 base64），图片可能较大。
- **无并发生成多图**：`storeImages()` 串行处理每张图片的下载和存储。当 `n > 1` 时（仅 Stability 支持），串行落盘。当前默认 `n=1` 影响不大。

## Potential Bugs

1. **`createLog()` 在日志禁用时返回未持久化的空 `AiRequest`**（`AiImageService.php:571-572`）：`new AiRequest` 没有 `exists` 属性为 true，`finalizeLog()` 中通过 `! $log->exists` 正确跳过了 save。但 `buildResult()` 中 `$log->exists ? (int) $log->request_id : null` 也正确处理了。**无 bug，逻辑自洽。**

2. **`DalleProvider::editImage()` 强制 dall-e-2 但未显式校验**（`DalleProvider.php:314`）：方法签名接受任何 model，`assertModelSupported()` 允许 dall-e-3 通过，但 DALL-E 3 实际不支持 `/images/edits` 端点。如果调用者传入 `model=dall-e-3`，请求会发到 OpenAI 然后返回 400 错误。虽然不会造成数据损坏，但错误信息不够明确。应在方法内增加 `if ($model !== 'dall-e-2') throw` 的显式校验。

3. **`StableDiffusionProvider::editImage()` 调用 `resolveSlug()` 但未使用返回值**（`StableDiffusionProvider.php:254`）：`$this->resolveSlug($model)` 的返回值被丢弃，仅起校验作用。功能上无 bug（校验仍然生效），但代码意图不明确，建议改为 `$this->resolveSlug($model)` 或用 `assert` 模式。

4. **`styleTransfer` 对 DALL-E 的处理路径**：`styleTransfer()` → `resolveProvider('dalle')` → `$provider->styleTransfer()` → 直接 throw。虽然功能正确，但请求日志已创建为 pending 状态，catch 块会将其标记为 failed。这意味着每次 DALL-E 风格迁移都会在 `ai_requests` 表留下一条失败记录。这是设计选择而非 bug，但调用者可能困惑。

5. **`storeBinary()` 中 `FileService::upload()` 抛异常时临时文件未清理**（`AiImageService.php:465-474`）：如果 `FileService::upload()` 抛出异常（如存储配额超限），`@unlink($tempPath)` 不会执行。应将 `@unlink` 放入 `finally` 块。

## Verdict

**PASS**

代码质量整体优秀，架构清晰，测试覆盖全面（18 个测试用例覆盖正常路径、边界条件和错误场景），翻译完备，与现有代码风格高度一致。

【建议改进】（非阻塞）：

1. **提取 `AiImageProviderContract` 接口**：定义 `textToImage`、`imageToImage`、`editImage`、`styleTransfer` 四个方法签名，让两个 Provider 实现它。提升类型安全和可扩展性。
2. **提取 `throwHttpError()` 到基类或 trait**：消除两个 Provider 之间的代码重复。
3. **`storeBinary()` 临时文件清理改用 `finally`**：防止 `FileService::upload()` 异常时临时文件泄漏。
4. **`DalleProvider::editImage()` 增加显式 dall-e-2 校验**：避免 dall-e-3 误调用产生不明确的 API 错误。
5. **`fetchImageBinary()` 的 URL 下载增加 scheme 校验**：至少限制为 `https`，防御 SSRF。
6. **`normalizeId()` 对非数字字符串抛 InvalidArgumentException**：避免 `(int) 'abc'` → `0` → "输入图片不存在"的误导性错误。
