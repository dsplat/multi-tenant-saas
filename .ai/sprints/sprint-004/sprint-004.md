# Sprint-004: v0.6.0 安全加固 (Security Hardening)

**周期：** 2026-08-01 至 2026-09-15  
**状态：** PENDING  
**目标：** 达到企业级安全标准，支撑 SOC2/ISO27001 合规审计

---

## 任务列表

| 任务ID | 标题 | 状态 | 依赖 | 并行波次 |
|--------|------|------|------|----------|
| TASK-015 | 多因素认证(MFA)与会话管理 | READY | 无 | Wave 1 |
| TASK-018 | GDPR 合规与数据保留 | READY | 无 | Wave 1 |
| TASK-016 | 密码策略与 SSO/SAML | READY | TASK-015 | Wave 2 |
| TASK-017 | IP 白名单与设备信任 | READY | TASK-015 | Wave 2 |

---

## 并行执行计划

```
Wave 1:  TASK-015 ──┬─→ TASK-016 (串行，共享 AuthController + routes/api.php)
         TASK-018 ──┘   └─→ TASK-017 (依赖 TASK-015 的 SessionService)
```

> **⚠ 文件共享警告**: TASK-015 和 TASK-016 共享 `app/Http/Controllers/Api/AuthController.php` 和 `routes/api.php`。**TASK-016 必须在 TASK-015 之后串行执行**。

```bash
# Wave 1: TASK-015 和 TASK-018 无依赖，可并行
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-015 &
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-018 &
wait

# Wave 2: TASK-016 串行（共享 AuthController + routes/api.php）
#         TASK-017 可与 TASK-016 并行（不共享文件，但都依赖 TASK-015）
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-016 &
AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-017 &
wait
```

---

## Sprint 目标

1. **MFA 认证**: TOTP/Email/SMS 多因素认证，恢复码，设备管理
2. **会话管理**: 活跃会话列表，设备指纹，强制下线，异常登录检测
3. **密码策略**: 复杂度要求，过期，历史禁止重复，暴力破解锁定
4. **SSO/SAML**: SAML 2.0 Service Provider，OIDC，租户级 IdP 配置
5. **IP 白名单**: 租户级 IP/CIDR 白名单，生效范围控制，设备信任
6. **GDPR 合规**: 数据导出/擦除，处理活动记录，数据可移植性
7. **数据保留**: 保留期限配置，自动清理，豁免标记

## 成功标准

- 端到端流程: MFA 登录 → 密码策略强制 → IP 白名单拦截 → SSO 集成 → GDPR 数据导出/擦除 → 自动保留清理 → 同意管理
- 数据库新增 ~9 张表（mfa_devices, mfa_recovery_codes, user_sessions, password_histories, sso_providers, ip_whitelists, trusted_devices, consents, data_retention_policies）
- 全量测试通过（预计 ~800 测试）

---

## 关键风险

1. **文件冲突**: TASK-015 和 TASK-016 共享 `AuthController.php` + `routes/api.php`，强制串行
2. **SAML 复杂度**: SAML 2.0 协议复杂，需 IdP 元数据管理、证书处理
3. **GDPR 擦除**: 数据擦除需处理所有关联表，可能影响大量外键关系

---

## 相关文档

- [完整功能规划](../../../Library/Application%20Support/Qoder/SharedClientCache/cache/plans/SaaS框架完整功能规划_task-064.md)
- [TASK-015](../tasks/TASK-015.md) ~ [TASK-018](../tasks/TASK-018.md)
