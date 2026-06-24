# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/lang/zh-CN/).

## [Unreleased]

### Added
- API Resource 层（UserResource, TenantResource, TenantUserResource, CreditAccountResource, TenantSettingResource）
- 安全 HTTP 头中间件（AddSecurityHeaders）
- 审计日志集成（AuditService）
- 编码规范文档（docs/development/coding-standards.md）

### Fixed
- AuthController 缺少 `use DB` 和 `use Str` import
- config/tenancy.php 移除硬编码 admin.lyt.com
- CORS 配置改用环境变量
- README 添加 TenantScope 方法使用说明

### Security
- 数据脱敏：手机号、邮箱、配置密钥自动 mask
- 密码策略增强：min(8)+mixedCase+numbers
- 支付日志脱敏：移除签名参数

## [1.0.0] - 2026-06-24

### Added
- 多租户 SaaS 框架基座
- 租户隔离（TenantScope + BelongsToTenant）
- 权限控制（四重访问架构）
- 配额管理
- 审计日志模型
- 8 种 UI 框架支持
- Domain 模块（域名管理 + Nginx 配置生成）
- SSL 模块（证书管理）
- 32 个 API 路由
- 46 个测试用例

### Security
- Sanctum 认证
- 租户数据隔离
- OAuth Token 加密存储
- 批量赋值防护
- 速率限制（认证端点）
