# Sprint-002: v0.4.0 计费闭环 (Billing Closure)

**周期：** 2026-06-28 至 2026-07-28  
**状态：** 进行中  
**目标：** 框架具备完整的商业化能力，可以收取用户费用

---

## 任务列表

| 任务ID | 标题 | 状态 | 依赖 | 并行波次 |
|--------|------|------|------|----------|
| TASK-005 | 发票与税务 | READY | 无 | Wave 1 |
| TASK-006 | 优惠券与试用管理 | READY | TASK-005 | Wave 2 |
| TASK-007 | 催款与按量计费 | READY | TASK-005, TASK-006 | Wave 3 |
| TASK-008 | 邮件模板系统 | READY | 无 | Wave 1 |
| TASK-009 | 租户引导式注册 | READY | TASK-006, TASK-008 | Wave 3 |

---

## 并行执行计划

```
Wave 1:  TASK-005 ──┬─→ TASK-006 ──┬─→ TASK-007
         TASK-008 ──┘              └─→ TASK-009
```

```bash
# Wave 1: TASK-005 和 TASK-008 无依赖，可并行
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-005 &
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-008 &
wait

# Wave 2: TASK-006 依赖 TASK-005
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-006

# Wave 3: TASK-007 和 TASK-009 可并行（各自依赖已满足）
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-007 &
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-009 &
wait
```

---

## Sprint 目标

1. **计费闭环**: 发票 → 税务 → 优惠券 → 试用 → 催款 → 按量计费
2. **邮件系统**: 可定制品牌化邮件模板
3. **注册向导**: 多步骤注册 + 自动初始化

## 成功标准

- 端到端流程: 注册向导 → 选择套餐(含试用) → 按量计费 → 使用优惠券 → 生成发票 → 税费计算 → 付款失败 → 催款 → 暂停 → 邮件通知
- 数据库新增 ~9 张表
- 全量测试通过

---

## 相关文档

- [完整功能规划](../../../Library/Application%20Support/Qoder/SharedClientCache/cache/plans/SaaS框架完整功能规划_task-064.md)
- [TASK-005](../tasks/TASK-005.md) ~ [TASK-009](../tasks/TASK-009.md)
