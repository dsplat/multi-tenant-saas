# Sprint-007: v0.9.0 高级隔离与白标 (Advanced Isolation & White-label)

**周期：** 2026-11-01 至 2026-12-15  
**状态：** PENDING  
**目标：** 满足企业级多租户隔离需求，支持租户个性化

---

## 任务列表

| 任务ID | 标题 | 状态 | 依赖 | 并行波次 |
|--------|------|------|------|----------|
| TASK-027 | 数据库级隔离 | READY | 无 | Wave 1 |
| TASK-028 | 租户加密密钥与白标 | READY | 无 | Wave 1 |
| TASK-029 | 数据驻留与租户克隆 | READY | TASK-027, TASK-028 | Wave 2 |

---

## 并行执行计划

```
Wave 1:  TASK-027 ──┬─→ TASK-029
         TASK-028 ──┘
```

> **⚠ 文件共享**: TASK-027 和 TASK-028 均追加修改 `config/tenancy.php`。建议串行执行或使用 loop-run 避免冲突。

```bash
# Wave 1: TASK-027 和 TASK-028 无依赖
# 注意: 两者都修改 config/tenancy.php，建议串行执行
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-027
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-028

# Wave 2: TASK-029 依赖 TASK-027 (IsolationService) 和 TASK-028 (TenantKeyService)
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-029
```

---

## Sprint 目标

1. **数据库级隔离**: 三种策略（共享数据库 / 独立数据库 / Schema 隔离），策略切换，迁移工具
2. **加密密钥**: 每租户独立 AES-256 密钥，密钥轮换，BYOK 支持
3. **白标定制**: 自定义 Logo、主色调、自定义域名、登录页样式、邮件品牌化
4. **数据驻留**: 区域配置（CN/US/EU/APAC），跨区域迁移，合规校验
5. **租户克隆**: 模板创建租户，配置快照导出/导入，克隆验证
6. **层级关系**: 父-子租户关系，资源共享池，层级计费

## 成功标准

- 端到端流程: 数据库级隔离 → 租户自定义品牌 → 区域数据驻留 → 租户克隆 → 父-子租户关系 → 层级计费
- 数据库新增 ~4 张表（tenant_keys, branding_configs, tenant_hierarchies, 及 Tenant 字段追加）
- 全量测试通过（预计 ~1070 测试）

---

## 关键风险

1. **文件共享**: TASK-027 和 TASK-028 共享 `config/tenancy.php`，建议串行
2. **隔离迁移**: shared → database-per-tenant 迁移涉及大量数据搬移，需严格验证
3. **Schema 隔离**: SchemaPerTenantStrategy 仅适用于 PostgreSQL，MySQL 8.0 不支持
4. **密钥安全**: 租户密钥用系统主密钥加密存储，主密钥泄露将影响所有租户

---

## 相关文档

- [完整功能规划](../../../Library/Application%20Support/Qoder/SharedClientCache/cache/plans/SaaS框架完整功能规划_task-064.md)
- [TASK-027](../tasks/TASK-027.md) ~ [TASK-029](../tasks/TASK-029.md)
