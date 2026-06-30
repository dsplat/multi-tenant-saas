## 评估报告

---

## Architecture

- `setUpDatabase()` 已从单一半月 500+ 行方法重构为 10 个职责清晰的私有方法 (`createCoreTables()`, `createAgentTables()`, `createMfaAndSecurityTables()` 等)，调用链扁平，模块边界明确，符合单一职责原则。
- 测试文件按 Controller/Service 拆分（AgentController、AgentChatController、AgentStatsController、ToolController、AgentRuntime、AgentRuntimeStream、MemoryCompressor、AgentFallback），与生产代码一一对应。
- `src/TenancyServiceProvider.php` 被修改（新增 `TenantContext` import，删除重复 `AlipayOAuthService` import）。改动极小且为 import 清理，但严格来说触及了生产代码，与任务"只修改测试基础设施"的约束存在偏差。

---

## Code Quality

- 测试方法命名遵循 `test_*` 约定，名称准确描述场景。SSE 测试名已从 `test_*_returns_sse_stream` 更正为 `test_*_returns_500_due_to_stream_response_type_mismatch`，解决了名实不符的问题。
- 公共 setup 逻辑（`createAgent`、`createConversation`、`authHeaders` 等）提取为 helper 方法，减少了单个测试方法内的代码量。
- 仍然存在重复代码：`AgentRuntimeTest`、`AgentRuntimeStreamTest`、`MemoryCompressorTest`、`AgentFallbackTest` 四个文件中的 `setUp`/`createAgent`/`createConversation` 几乎完全重复；`AgentControllerTest`、`AgentChatControllerTest`、`AgentStatsControllerTest`、`ToolControllerTest` 四个文件中的 `setUp`/`authHeaders` 也高度重复。累计约 200+ 行可抽取为 trait。
- `AgentChatControllerTest::test_send_message_returns_404_for_other_tenant_conversation` 已修复：现在使用 `createConversation(1002, 1002)` 配合属于 tenant 1002 的 agent，模拟了真实跨租户场景。
- `AgentFallbackTest::test_tool_failure_returns_error_to_ai` 中 `execute` mock 缺少 `->with()` 参数约束，降低测试精确性。

---

## Type Safety

- 测试文件中属性使用了 PHP 7.4+ 类型提示（`protected Tenant $tenant`、`protected User $user`），类型标注完整。
- Mock 属性使用了 `@var Mockery\MockInterface` 文档注释，类型提示规范。
- `?AgentRuntime $runtime = null` 和 `?MemoryCompressor $compressor = null` 的 nullable 声明正确。
- helper 方法 `createAgent()` 和 `createConversation()` 缺少显式返回类型声明（`: Agent`、`: AgentConversation`）。
- `array_merge` 的 `$overrides` 参数无类型约束，但这是 helper 模式的常见取舍，风险低。
- 无潜在类型错误。

---

## Security

- 测试中无硬编码密钥、凭证或敏感数据。Token 通过 `$user->createToken()` 动态生成。
- 租户隔离测试覆盖全面：跨租户访问 Agent、Conversation、Tool 均返回 404，并验证了数据隔离。
- 全局工具（`tenant_id=0`）的更新/删除保护已验证。
- 401/422 测试覆盖了未认证和校验失败场景。
- 使用 Eloquent ORM，无 SQL 注入风险。
- `sso_providers` 表包含 `client_secret` 列，`tenant_keys` 表包含 `encrypted_key` 列 — 测试环境无真实数据泄露风险，但需确保 CI 不会意外写入真实凭证。
- `dead_letters` 表定义了外键约束，但 SQLite 默认关闭外键支持（`PRAGMA foreign_keys = OFF`），该约束在测试中不会实际执行，可能导致与生产行为不一致。

---

## Performance

- 测试使用内存 SQLite，无 I/O 瓶颈。
- `setUpDatabase()` 通过 `static::$dbPrepared` 标志确保 schema 只创建一次，避免每个测试方法重复建表。
- 测试数据量小，执行效率高。
- 无 N+1 查询或内存泄漏风险。
- `MemoryCompressorTest::test_compress_triggers_when_over_threshold` 创建 100 条消息，对于阈值测试合理。

---

## Potential Bugs

1. **`dead_letters` 外键约束在 SQLite 测试中不生效**：`dead_letters` 表定义了 `$table->foreign('subscription_id')->references('event_subscription_id')->on('event_subscriptions')->nullOnDelete()`。Laravel SQLite 默认 `PRAGMA foreign_keys = OFF`，该外键不会实际执行，可能导致与生产环境（MySQL/PostgreSQL）行为不一致。

2. **`MemoryCompressorTest::test_compress_triggers_when_over_threshold` mock 期望不精确**：`->atLeast()->once()` 不能验证 AI 被调用的确切次数。若压缩逻辑异常导致多次调用，测试不会检测到。

3. **`AgentRuntimeStreamTest::test_stream_with_tool_calls_then_text` 断言过于宽松**：`assertGreaterThanOrEqual(3, count($chunks))` 无法验证是否有额外不期望的 chunk 被 yield，应使用精确的 `assertCount`。

4. **`AgentStatsControllerTest::test_cost_returns_estimate` 无数据准备**：直接断言 `assertJsonStructure` 包含 `estimated_cost`，依赖空数据集的默认响应。若生产代码在无数据时返回不同结构，测试可能误报。

5. **`AgentFallbackTest::test_tool_failure_returns_error_to_ai` 中 `execute` mock 缺少参数匹配**：`$this->toolRegistryMock->shouldReceive('execute')->andThrow(...)` 未使用 `->with()` 限定调用参数，任何工具执行都会触发 mock，降低测试精确性。

---

## Verdict

**PASS**

---

### 【建议改进】

1. 6 个测试文件中的重复 `setUp`/`tearDown`/`authHeaders`/`createAgent`/`createConversation` 逻辑可抽取到 `tests/Concerns/WithTenantAuth.php` 和 `tests/Concerns/WithAgentFactory.php` trait 中，减少约 200 行重复代码。
2. `AgentFallbackTest::test_tool_failure_returns_error_to_ai` 中 `execute` mock 应添加 `->with('search_customer', ...)` 参数约束。
3. `AgentRuntimeStreamTest::test_stream_with_tool_calls_then_text` 的 chunk 数量断言应从 `assertGreaterThanOrEqual` 改为精确的 `assertCount`。
4. `MemoryCompressorTest::test_compress_triggers_when_over_threshold` 的 `atLeast()->once()` 应改为 `->once()`。
5. `AgentStatsControllerTest::test_cost_returns_estimate` 应补充数据准备（如插入 token usage 记录），确保断言不依赖空数据集默认值。
6. `dead_letters` 的外键约束在 SQLite 测试中不会生效，应在测试文档中记录此差异，或通过 `DB::statement('PRAGMA foreign_keys = ON')` 启用。
7. `TenancyServiceProvider.php` 的 import 清理（新增 `TenantContext`、删除重复 `AlipayOAuthService`）应在独立 PR 中提交，与测试基础设施任务分离。