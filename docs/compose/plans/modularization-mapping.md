# 共享文件模块化映射方案

> 将 src/Models/、src/Services/、src/Middleware/ 中的共享文件移入各自模块

## 一、Infrastructure 模块（框架基础设施）

### Models → src/Modules/Infrastructure/Models/
- Tenant.php
- TenantUser.php
- TenantSetting.php
- TenantHierarchy.php
- TenantKey.php
- TenantKey.php
- FeatureFlag.php
- SystemSetting.php
- DataRetentionPolicy.php
- SandboxEnvironment.php
- IpWhitelist.php

### Services → src/Modules/Infrastructure/Services/
- TenantService.php
- TenantMemberService.php
- TenantSettingService.php
- TenantProfileService.php
- TenantOnboardingService.php
- TenantCloneService.php
- TenantCreditService.php
- TenantKeyService.php
- IsolationService.php
- ModuleManager.php
- ModuleRegistry.php
- ModuleBootstrapper.php
- IdGenerator.php
- SchedulerService.php
- HealthService.php
- HealthCheckService.php
- BackupService.php
- CacheService.php
- QueueService.php
- RateLimitService.php
- SearchService.php
- ImageService.php
- MailerService.php
- EventBusService.php
- AlertService.php
- PerformanceService.php
- MetricsService.php
- StructuredLogService.php
- ErrorTrackingService.php
- ResourceService.php
- ApiVersionService.php
- CrossTenantService.php
- DataResidencyService.php
- GdprService.php
- RetentionService.php
- QuotaService.php
- ExportService.php
- ExcelService.php
- PdfService.php

### Middleware → src/Modules/Infrastructure/Http/Middleware/
- IdentifyDomain.php
- IdentifyTenant.php
- EnsureTenantContext.php
- SetLocale.php
- CheckFeatureFlag.php
- CheckIpWhitelist.php

## 二、Auth 模块（已部分完成）

### Models → src/Modules/Auth/Models/（新增）
- MfaDevice.php
- MfaRecoveryCode.php
- PasswordHistory.php
- OauthAccount.php
- SsoProvider.php
- TrustedDevice.php
- UserSession.php
- Permission.php
- Role.php

### Services → src/Modules/Auth/Services/（新增）
- MfaService.php
- PasswordPolicyService.php
- PasswordService.php
- SessionService.php
- SsoService.php
- SocialiteService.php
- AlipayOAuthService.php
- LoginLogService.php
- TrustedDeviceService.php

## 三、Billing 模块

### Models → src/Modules/Billing/Models/
- Invoice.php
- InvoiceItem.php
- PaymentOrder.php
- CreditAccount.php
- CreditTransaction.php
- SubscriptionPlan.php
- SubscriptionHistory.php
- TaxRule.php
- FinancialRecord.php
- CostAllocation.php
- UsageRecord.php

### Services → src/Modules/Billing/Services/
- InvoiceService.php
- PayService.php
- RefundService.php
- SubscriptionService.php
- CostService.php
- TaxService.php
- PlanChangeService.php
- PaymentSecurityService.php
- DunningService.php
- UsageService.php

## 四、Conversation 模块

### Models → src/Modules/Conversation/Models/
- Message.php
- Conversation.php
- ConversationSession.php
- ConversationTag.php
- Participant.php
- Reaction.php
- ReadState.php
- Mention.php
- ArchivedMessage.php
- Attachment.php

## 五、Notification 模块

### Models → src/Modules/Notification/Models/
- InAppNotification.php
- NotificationPreference.php
- MailTemplate.php

### Services → src/Modules/Notification/Services/
- NotificationService.php
- InAppNotificationService.php
- MailTemplateService.php

## 六、Platform 模块

### Services → src/Modules/Platform/Services/
- SystemSettingService.php
- FeatureFlagService.php

## 七、Logging 模块

### Models → src/Modules/Logging/Models/
- AuditLog.php

### Services → src/Modules/Logging/Services/
- AuditService.php
- LoginLogService.php (已在 Auth)

## 八、Workflow 模块

### Models → src/Modules/Workflow/Models/
- WorkflowExecution.php
- WorkflowNode.php

## 九、Storage 模块

### Models → src/Modules/Storage/Models/
- FileUpload.php

### Services → src/Modules/Storage/Services/
- FileService.php

## 十、Domain 模块

### Services → src/Modules/Domain/Services/
- (无，IpWhitelist 已在 Infrastructure)

## 十一、Sms 模块

### Models → src/Modules/Sms/Models/
- SmsBatchTask.php
- SmsSendLog.php
- SmsTemplate.php

## 十二、Monitoring 模块

### Models → src/Modules/Monitoring/Models/
- MetricsSnapshot.php
- SlaEvent.php

## 十三、ApiToken 模块

### Models → src/Modules/ApiToken/Models/
- McpClient.php
- McpClientToken.php
- McpTool.php
- McpToolAccessLog.php

## 十四、DeveloperPortal 模块

### Services → src/Modules/DeveloperPortal/Services/
- DeveloperPortalService.php
- SandboxService.php

## 十五、Plugin 模块

### Services → src/Modules/Plugin/Services/
- PluginService.php

## 十六、Ai 模块

### Services → src/Modules/Ai/Services/（已有）
- AiConfigService.php
- AiGatewayService.php
- AiImageService.php
- AiTextService.php
- AiUsageService.php
- AiVideoService.php

## 十七、Event 模块

### Models → src/Modules/Event/Models/
- EventSubscription.php
- BroadcastEvent.php

### Services → src/Modules/Event/Services/
- BroadcastingService.php

## 十八、Coupon 模块

（已有自己的 Models）

## 十九、其他 Services

- WebhookService.php → src/Modules/Infrastructure/Services/
- Webhook.php → src/Modules/Infrastructure/Models/
- WebhookDelivery.php → src/Modules/Infrastructure/Models/
- UserPreferenceService.php → src/Modules/User/Services/
- UserProfileService.php → src/Modules/User/Services/
- UserService.php → src/Modules/User/Services/
- ConsentService.php → src/Modules/Infrastructure/Services/
- Consent.php → src/Modules/Infrastructure/Models/
- BrandingService.php → src/Modules/Infrastructure/Services/
- BrandingConfig.php → src/Modules/Infrastructure/Models/
- IpWhitelistService.php → src/Modules/Infrastructure/Services/
- StripeService.php → src/Modules/Billing/Services/
- PayPalService.php → src/Modules/Billing/Services/
- UnionPayService.php → src/Modules/Billing/Services/
- HorizonService.php → src/Modules/Infrastructure/Services/
- SlaService.php → src/Modules/Monitoring/Services/
