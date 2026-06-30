## Architecture

Onboarding 的分步注册设计合理：token-based session → step 提交 → complete 触发初始化，流程清晰。Controller 层职责划分得当，异常分层（InvalidArgumentException → 422, RuntimeException → 400, Throwable → 500）体现了对业务异常与系统异常的区分。

**问题：** 新增的 onboarding 路由仅施加 `throttle:10,1` 限流，无任何认证。这是注册场景的合理选择，但 `TenantOnboardingService` 的实现不在本次 diff 中，无法确认 token 查找逻辑是否具备防枚举保护。此外，`TenantResource` 中 `TrialService::isInTrial($tenant)` 是单租户调用，若用于集合响应会触发 N+1 查询。

## Code Quality

命名规范，方法名 `register`/`onboardingStatus`/`onboardingStep`/`onboardingComplete` 语义清晰。异常处理层次分明。`$stepFields` 定义在方法内部，可读性好但略显冗长，可考虑提取为类常量。

`array_intersect_key($data, array_flip($allowed))` 与 `$request->validate()` 的过滤功能部分重叠——`validate()` 已只返回规则中存在的字段，额外的 `array_intersect_key` 是冗余操作。

Shell 脚本 shebang 从 `#!/bin/bash` 改为 `#!/opt/homebrew/bin/bash` **破坏了可移植性**，其他 macOS 用户或 Linux CI 环境将无法执行。

## Type Safety

`TenantResource` 中 `$tenant` 有 `@var Tenant` 注解，返回类型 `array` 声明完整。Controller 所有方法均有 `JsonResponse` 返回类型标注。`$request->validate()` 返回 `array`，类型正确。无明显类型安全问题。

## Security

密码校验 `regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/` 要求包含大小写和数字，强度合理，但 Laravel 默认的 regex 验证错误消息不够友好，应自定义错误消息。

**注册端点无认证是设计意图**（公开注册），但需确认 `TenantOnboardingService` 内部对 token 的处理是否安全——如果 token 查询仅做数据库查找而无额外验证，存在 token 枚举风险。

审计日志中记录了 `admin_email`，这是合理的操作审计。SQL 注入风险低（使用 Eloquent 验证规则）。XSS 风险低（JSON API 响应）。

## Performance

**N+1 风险：** `TenantResource::toArray()` 中调用 `TrialService::isInTrial($tenant)`——当通过 `TenantResource::collection()` 返回列表时，每个租户都会触发一次服务调用。应通过 eager loading 或在 collection 层面批量处理。

`onboardingStep` 方法中的 `$stepFields` 每次请求都重新创建数组，影响可忽略。

## Potential Bugs

1. **缺少测试文件：** TASK-009c 的核心交付物 `tests/TenantOnboardingTest.php` **在 diff 中完全缺失**。任务要求"覆盖 5 步流程、断点续填、自动初始化、试用调用、欢迎邮件等验收场景"，但无任何测试代码。

2. **`$stepFields` 仅覆盖 step 2-4，** step 1 走 `register()`，step 5 走 `onboardingComplete()`。若 route constraint 放宽（当前 `[2-4]`），调用未定义的 step 会触发 `isset` 检查返回 422，处理正确。但 step 4 的 `payment_method: 'none'` 和 `skip: true` 是否能被 service 层正确处理，无法从 diff 确认。

3. **Shell 脚本 `#!/opt/homebrew/bin/bash` 硬编码路径**，在非 Homebrew 环境（Linux、CI、其他 macOS 安装方式）下直接报 `bad interpreter` 错误。`lib.sh` 中正则 `^\*{0,2}只允许修改` 改进了 markdown 兼容性，但需确认 `\*{0,2}` 在 bash 3.2（macOS 默认）的 `[[ =~ ]]` 中是否正确工作——bash 3.2 的 regex 引擎对某些语法支持有限。

4. **`state.json` 被重置为仅含 TASK-009c**，丢失了所有历史任务状态（TASK-001 到 TASK-004），这是一个数据丢失问题，与 TASK-009c 的代码变更无关。

5. **`TestCase.php` 新增了 `onboarding_step`、`onboarding_completed`、`trial_extended`、`trial_notification_sent_at` 列**，但这些列在实际 migration 中未见（diff 中无 migration 文件）。如果 production migration 缺失，测试通过但部署会失败。

## Verdict

**FAIL**

### 【必须修复】

1. **核心交付物缺失**：`tests/TenantOnboardingTest.php` 未创建。TASK-009c 的任务目标明确要求编写覆盖 5 步流程、断点续填、自动初始化、试用调用、欢迎邮件等场景的测试用例，diff 中无此文件。

2. **严重越权修改**：TASK-009c 明确禁止"修改其他文件、新增依赖"，但 diff 实际修改了 12 个文件，包括 Controller（新增 4 个方法）、routes/api.php、TestCase.php、lang 文件、.ai/scripts/*.sh、state.json。这些变更应属于 TASK-009a/TASK-009b 的范围。

3. **`TenantResource` N+1 风险**：`TrialService::isInTrial($tenant)` 在 collection 场景下会逐个查询，需提供批量处理方案或在 Resource collection 层面优化。

4. **shebang 可移植性**：`#!/opt/homebrew/bin/bash` 硬编码路径破坏跨平台兼容性，应改回 `#!/usr/bin/env bash`。
