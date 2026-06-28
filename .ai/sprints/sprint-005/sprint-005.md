# Sprint-005: v0.7.0 集成能力 (Integration Capabilities)

**周期：** 2026-09-01 至 2026-10-15  
**状态：** PENDING  
**目标：** 框架具备完善的 API 生态和对外集成能力

---

## 任务列表

| 任务ID | 标题 | 状态 | 依赖 | 并行波次 |
|--------|------|------|------|----------|
| TASK-019 | Webhook 系统 | READY | 无 | Wave 1 |
| TASK-022 | 功能开关系统 | READY | 无 | Wave 1 |
| TASK-020 | 事件总线 | READY | TASK-019 | Wave 2 |
| TASK-021 | PHP SDK 与开发者门户 | READY | TASK-019, TASK-020 | Wave 3 |

---

## 并行执行计划

```
Wave 1:  TASK-019 ──┬─→ TASK-020 ──→ TASK-021
         TASK-022 ──┘
```

> **⚠ 文件共享**: TASK-019 和 TASK-022 均追加修改 `config/tenancy.php`。建议串行执行或使用 loop-run 避免冲突。

```bash
# Wave 1: TASK-019 和 TASK-022 无依赖
# 注意: 两者都修改 config/tenancy.php，建议串行执行
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-019
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-022

# Wave 2: TASK-020 依赖 TASK-019
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-020

# Wave 3: TASK-021 依赖 TASK-019 和 TASK-020
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-021
```

---

## Sprint 目标

1. **Webhook 系统**: 事件注册、HMAC-SHA256 签名验证、指数退避重试、交付日志
2. **事件总线**: 发布/订阅、内部+外部订阅、异步分发、死信队列
3. **PHP SDK**: 封装租户/支付/AI API 调用，链式调用，类型安全
4. **开发者门户**: API Key 管理、使用统计、沙箱环境（24h TTL 自动清理）
5. **功能开关**: 全局/租户/用户级开关、灰度发布、A/B 测试分组

## 成功标准

- 端到端流程: 注册 Webhook → 事件总线分发 → 外部 Webhook 交付 → SDK 调用 API → 开发者门户管理 Key → 沙箱测试 → 功能开关灰度
- 数据库新增 ~6 张表（webhooks, webhook_deliveries, event_subscriptions, dead_letters, sandbox_environments, feature_flags）
- 全量测试通过（预计 ~900 测试）

---

## 关键风险

1. **文件共享**: TASK-019 和 TASK-022 共享 `config/tenancy.php`，建议串行
2. **Webhook 可靠性**: 重试机制需保证至少一次交付，幂等性需调用方保证
3. **沙箱隔离**: 沙箱环境数据需与生产数据完全隔离

---

## 相关文档

- [完整功能规划](../../../Library/Application%20Support/Qoder/SharedClientCache/cache/plans/SaaS框架完整功能规划_task-064.md)
- [TASK-019](../tasks/TASK-019.md) ~ [TASK-022](../tasks/TASK-022.md)
