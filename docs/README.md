# Multi-Tenant SaaS Framework — Documentation

**English** | [中文](zh/README.md)

---

## English

- [Quickstart](en/guides/quickstart.md)
- [User Manual](en/user-manual.md) (partial — translations in progress)

## 中文

- [快速开始](zh/guides/quickstart.md)
- [用户手册](zh/user-manual.md)
- [系统架构概览](zh/architecture/system-overview.md)
- [部署指南](zh/deployment/deployment-guide.md)
- [API 参考](zh/api/api-overview.md)
- [安全审计报告](zh/security/security-audit.md)

## Full Index

### Architecture (架构设计)
| Document | 中文 | English |
|---|---|---|
| System Overview | [zh](zh/architecture/system-overview.md) | — |
| **Design Principles & Pitfalls** | [zh](zh/architecture/design-principles.md) | — |
| **Framework Internals** | [zh](zh/architecture/framework-internals.md) | — |
| **SPA Architecture** | [zh](spa-architecture.md) | — |
| AI Module Architecture | [zh](zh/architecture/ai-module.md) | — |
| Multi-Domain Architecture | 已删除，见 [tenant.md](tenant.md) 第二节 | — |
| Tenant Isolation | [zh](zh/architecture/tenant-isolation.md) | — |
| Data Model Design | [zh](zh/architecture/data-model.md) | — |
| Design Decisions | [zh](zh/architecture/design-decisions.md) | — |

### Deployment (部署运维)
| Document | 中文 | English |
|---|---|---|
| Deployment Guide | [zh](zh/deployment/deployment-guide.md) | — |
| Operations Manual | [zh](zh/deployment/operations-manual.md) | — |
| Release Checklist | [zh](zh/deployment/release-checklist.md) | — |
| Backup & Restore | [zh](zh/deployment/backup-restore.md) | — |
| Incident Response | [zh](zh/deployment/incident-response.md) | — |
| Monitoring & Alerting | [zh](zh/deployment/monitoring-alerting.md) | — |
| Nginx Configuration | [zh](zh/deployment/nginx-guide.md) | — |

### Development (开发)
| Document | 中文 | English |
|---|---|---|
| Local Development Setup | [zh](zh/development/local-setup.md) | — |
| Coding Standards | [zh](zh/development/coding-standards.md) | — |

### Guides (使用指南)
| Document | 中文 | English |
|---|---|---|
| Quickstart | [zh](zh/guides/quickstart.md) | [en](en/guides/quickstart.md) |
| Four-Layer Access | [zh](zh/guides/four-layer-access.md) | — |
| Domain Configuration | 已删除，见 [nginx-guide.md](zh/deployment/nginx-guide.md) | — |
| RBAC & Permissions | [zh](zh/guides/rbac-guide.md) | — |
| AI Module Guide | [zh](zh/guides/ai-module-guide.md) | — |
| Billing Configuration | [zh](zh/guides/billing-config.md) | — |
| OAuth SDK Integration | [zh](zh/guides/oauth-sdk-guide.md) | — |
| Payment SDK Integration | [zh](zh/guides/payment-sdk-guide.md) | — |
| SaaS Module Extension | [zh](zh/guides/saas-extension-guide.md) | — |
| Auth Guide | [zh](auth.md) | — |

### API Reference
| Document | 中文 | English |
|---|---|---|
| API Overview | [zh](zh/api/api-overview.md) | — |
| AI Module API | [zh](zh/api/ai-module-api.md) | — |
| Core API | [zh](zh/api/core-api.md) | — |
| Middleware API | [zh](zh/api/middleware-api.md) | — |
| Service Layer API | [zh](zh/api/service-layer-api.md) | — |
| OpenAPI Spec | [spec](zh/api/openapi.yaml) | — |

### Security (安全)
| Document | 中文 | English |
|---|---|---|
| Security Audit Report | [zh](zh/security/security-audit.md) | — |

### Requirements (需求规划)
| Document | 中文 | English |
|---|---|---|
| Framework Upgrade Plan | [zh](zh/requirements/framework-upgrade-plan.md) | — |
| Upgrade Effort Estimate | [zh](zh/requirements/framework-upgrade-plan-effort.md) | — |

### Examples (示例)
| Document | 中文 | English |
|---|---|---|
| PHP SDK Quickstart | [zh](zh/examples/php-sdk-quickstart.md) | — |
| REST API Examples | [zh](zh/examples/rest-api-examples.md) | — |

### Code Review (代码审查)
| Document | 说明 |
|---|---|
| [Review Bugs](review_bugs.md) | 四轮深度审查记录（22 个问题，21 已修复） |
| [Known Bugs](bugs.md) | Auth/Operator 模块已知问题（30 个，11 已修复） |

### Meta (元数据)
| Document | 说明 |
|---|---|
| [manifest.json](manifest.json) | 文档版本追踪（文件 ↔ 代码信号映射） |

---

**Version**: v2.9.0 | **Last Updated**: 2026-08-01
