# Sprint-006: v0.8.0 可观测性 (Observability)

**周期：** 2026-10-01 至 2026-11-15  
**状态：** PENDING  
**目标：** 具备完整的监控、告警、成本分析和业务洞察能力

---

## 任务列表

| 任务ID | 标题 | 状态 | 依赖 | 并行波次 |
|--------|------|------|------|----------|
| TASK-023 | 实时指标与 SLA 监控 | READY | 无 | Wave 1 |
| TASK-026 | 通知中心 | READY | 无 | Wave 1 |
| TASK-024 | 租户成本与资源追踪 | READY | TASK-023, TASK-014⚠ | Wave 2 |
| TASK-025 | 错误追踪与自定义报表 | READY | TASK-023, TASK-024 | Wave 3 |

> **⚠ 跨版本依赖**: TASK-024 依赖 v0.5.0 的 TASK-014 (AiUsageService)。如果 Sprint-003 的 TASK-014 未通过，本任务将被阻塞。

---

## 并行执行计划

```
Wave 1:  TASK-023 ──┬─→ TASK-024 (⚠跨版本依赖 TASK-014) ──→ TASK-025
         TASK-026 ──┘
```

```bash
# Wave 1: TASK-023 和 TASK-026 无依赖，可并行
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-023 &
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-026 &
wait

# Wave 2: TASK-024 依赖 TASK-023 (MetricsService) + TASK-014 (AiUsageService)
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-024

# Wave 3: TASK-025 依赖 TASK-023 和 TASK-024
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-025
```

---

## Sprint 目标

1. **实时指标**: QPS/RPM、P50/P95/P99 延迟、错误率、活跃租户/用户数、API 端点分布
2. **SLA 监控**: 可用性计算、多级 SLA (99.9%/99.95%/99.99%)、违约事件记录、告警触发
3. **成本追踪**: 基础设施成本分摊、AI 用量成本、第三方服务成本、租户级盈亏分析
4. **资源监控**: 数据库连接数、队列积压、缓存命中率、存储用量、租户资源占比
5. **错误追踪**: Sentry 集成、错误聚合、影响面分析、趋势图
6. **自定义报表**: 租户自定义指标+维度+时间范围，定时发送，导出 PDF/Excel/CSV
7. **通知中心**: 站内通知、已读/未读、分类管理、WebSocket 实时推送 (Laravel Reverb)

## 成功标准

- 端到端流程: 实时指标仪表盘 → SLA 违约告警 → 租户成本报表 → AI 用量成本 → 错误追踪聚合 → 自定义报表 → 站内通知 → WebSocket 实时推送
- 数据库新增 ~6 张表（metrics_snapshots, sla_events, cost_allocations, custom_reports, in_app_notifications, broadcast_events）
- 全量测试通过（预计 ~1000 测试）

---

## 关键风险

1. **跨版本依赖**: TASK-024 依赖 TASK-014 (AiUsageService)，需 Sprint-003 先完成
2. **指标采集性能**: 每分钟采集指标快照需控制对生产性能的影响
3. **WebSocket 扩展**: Laravel Reverb 需配置 WebSocket 服务器，可能需额外基础设施

---

## 相关文档

- [完整功能规划](../../../Library/Application%20Support/Qoder/SharedClientCache/cache/plans/SaaS框架完整功能规划_task-064.md)
- [TASK-023](../tasks/TASK-023.md) ~ [TASK-026](../tasks/TASK-026.md)
