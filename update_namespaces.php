<?php

$baseDir = __DIR__ . '/src';

// Models mapping
$modelsMap = [
    'MultiTenantSaas\\Models\\User' => 'MultiTenantSaas\\Modules\\Auth\\Models\\User',
    'MultiTenantSaas\\Models\\Tenant' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\Tenant',
    'MultiTenantSaas\\Models\\TenantUser' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\TenantUser',
    'MultiTenantSaas\\Models\\TenantSetting' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\TenantSetting',
    'MultiTenantSaas\\Models\\Permission' => 'MultiTenantSaas\\Modules\\Auth\\Models\\Permission',
    'MultiTenantSaas\\Models\\Role' => 'MultiTenantSaas\\Modules\\Auth\\Models\\Role',
    'MultiTenantSaas\\Models\\MfaDevice' => 'MultiTenantSaas\\Modules\\Auth\\Models\\MfaDevice',
    'MultiTenantSaas\\Models\\MfaRecoveryCode' => 'MultiTenantSaas\\Modules\\Auth\\Models\\MfaRecoveryCode',
    'MultiTenantSaas\\Models\\PasswordHistory' => 'MultiTenantSaas\\Modules\\Auth\\Models\\PasswordHistory',
    'MultiTenantSaas\\Models\\TrustedDevice' => 'MultiTenantSaas\\Modules\\Auth\\Models\\TrustedDevice',
    'MultiTenantSaas\\Models\\UserSession' => 'MultiTenantSaas\\Modules\\Auth\\Models\\UserSession',
    'MultiTenantSaas\\Models\\OauthAccount' => 'MultiTenantSaas\\Modules\\Auth\\Models\\OauthAccount',
    'MultiTenantSaas\\Models\\SsoProvider' => 'MultiTenantSaas\\Modules\\Auth\\Models\\SsoProvider',
    'MultiTenantSaas\\Models\\Operator' => 'MultiTenantSaas\\Modules\\Operator\\Models\\Operator',
    'MultiTenantSaas\\Models\\OperatorTenant' => 'MultiTenantSaas\\Modules\\Operator\\Models\\OperatorTenant',
    'MultiTenantSaas\\Models\\Message' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\Message',
    'MultiTenantSaas\\Models\\Conversation' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\Conversation',
    'MultiTenantSaas\\Models\\ConversationSession' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\ConversationSession',
    'MultiTenantSaas\\Models\\ConversationTag' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\ConversationTag',
    'MultiTenantSaas\\Models\\ArchivedMessage' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\ArchivedMessage',
    'MultiTenantSaas\\Models\\Mention' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\Mention',
    'MultiTenantSaas\\Models\\Participant' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\Participant',
    'MultiTenantSaas\\Models\\ReadState' => 'MultiTenantSaas\\Modules\\Conversation\\Models\\ReadState',
    'MultiTenantSaas\\Models\\Invoice' => 'MultiTenantSaas\\Modules\\Billing\\Models\\Invoice',
    'MultiTenantSaas\\Models\\PaymentOrder' => 'MultiTenantSaas\\Modules\\Billing\\Models\\PaymentOrder',
    'MultiTenantSaas\\Models\\CreditAccount' => 'MultiTenantSaas\\Modules\\Billing\\Models\\CreditAccount',
    'MultiTenantSaas\\Models\\CreditTransaction' => 'MultiTenantSaas\\Modules\\Billing\\Models\\CreditTransaction',
    'MultiTenantSaas\\Models\\FinancialRecord' => 'MultiTenantSaas\\Modules\\Billing\\Models\\FinancialRecord',
    'MultiTenantSaas\\Models\\SubscriptionHistory' => 'MultiTenantSaas\\Modules\\Billing\\Models\\SubscriptionHistory',
    'MultiTenantSaas\\Models\\SubscriptionPlan' => 'MultiTenantSaas\\Modules\\Billing\\Models\\SubscriptionPlan',
    'MultiTenantSaas\\Models\\CostAllocation' => 'MultiTenantSaas\\Modules\\Billing\\Models\\CostAllocation',
    'MultiTenantSaas\\Models\\TaxRule' => 'MultiTenantSaas\\Modules\\Billing\\Models\\TaxRule',
    'MultiTenantSaas\\Models\\UsageRecord' => 'MultiTenantSaas\\Modules\\Billing\\Models\\UsageRecord',
    'MultiTenantSaas\\Models\\FileUpload' => 'MultiTenantSaas\\Modules\\Storage\\Models\\FileUpload',
    'MultiTenantSaas\\Models\\Form' => 'MultiTenantSaas\\Modules\\Form\\Models\\Form',
    'MultiTenantSaas\\Models\\Lottery' => 'MultiTenantSaas\\Modules\\Lottery\\Models\\Lottery',
    'MultiTenantSaas\\Models\\AuditLog' => 'MultiTenantSaas\\Modules\\Logging\\Models\\AuditLog',
    'MultiTenantSaas\\Models\\McpClient' => 'MultiTenantSaas\\Modules\\ApiToken\\Models\\McpClient',
    'MultiTenantSaas\\Models\\McpTool' => 'MultiTenantSaas\\Modules\\ApiToken\\Models\\McpTool',
    'MultiTenantSaas\\Models\\Webhook' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\Webhook',
    'MultiTenantSaas\\Models\\WebhookDelivery' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\WebhookDelivery',
    'MultiTenantSaas\\Models\\SystemSetting' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\SystemSetting',
    'MultiTenantSaas\\Models\\FeatureFlag' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\FeatureFlag',
    'MultiTenantSaas\\Models\\BrandingConfig' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\BrandingConfig',
    'MultiTenantSaas\\Models\\Consent' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\Consent',
    'MultiTenantSaas\\Models\\DataRetentionPolicy' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\DataRetentionPolicy',
    'MultiTenantSaas\\Models\\IpWhitelist' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\IpWhitelist',
    'MultiTenantSaas\\Models\\SandboxEnvironment' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\SandboxEnvironment',
    'MultiTenantSaas\\Models\\TenantHierarchy' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\TenantHierarchy',
    'MultiTenantSaas\\Models\\TenantKey' => 'MultiTenantSaas\\Modules\\Infrastructure\\Models\\TenantKey',
    'MultiTenantSaas\\Models\\InAppNotification' => 'MultiTenantSaas\\Modules\\Notification\\Models\\InAppNotification',
    'MultiTenantSaas\\Models\\MailTemplate' => 'MultiTenantSaas\\Modules\\Notification\\Models\\MailTemplate',
    'MultiTenantSaas\\Models\\NotificationPreference' => 'MultiTenantSaas\\Modules\\Notification\\Models\\NotificationPreference',
    'MultiTenantSaas\\Models\\SmsBatchTask' => 'MultiTenantSaas\\Modules\\Sms\\Models\\SmsBatchTask',
    'MultiTenantSaas\\Models\\SmsTemplate' => 'MultiTenantSaas\\Modules\\Sms\\Models\\SmsTemplate',
    'MultiTenantSaas\\Models\\WorkflowExecution' => 'MultiTenantSaas\\Modules\\Workflow\\Models\\WorkflowExecution',
    'MultiTenantSaas\\Models\\CustomReport' => 'MultiTenantSaas\\Modules\\Monitoring\\Models\\CustomReport',
    'MultiTenantSaas\\Models\\DeadLetter' => 'MultiTenantSaas\\Modules\\Monitoring\\Models\\DeadLetter',
    'MultiTenantSaas\\Models\\MetricsSnapshot' => 'MultiTenantSaas\\Modules\\Monitoring\\Models\\MetricsSnapshot',
    'MultiTenantSaas\\Models\\SlaEvent' => 'MultiTenantSaas\\Modules\\Monitoring\\Models\\SlaEvent',
    'MultiTenantSaas\\Models\\BroadcastEvent' => 'MultiTenantSaas\\Modules\\Event\\Models\\BroadcastEvent',
    'MultiTenantSaas\\Models\\EventSubscription' => 'MultiTenantSaas\\Modules\\Event\\Models\\EventSubscription',
    // Capability sub-namespace
    'MultiTenantSaas\\Models\\Capability\\CapabilityResult' => 'MultiTenantSaas\\Models\\Capability\\CapabilityResult', // Keep as-is, not moved
    // Memory sub-namespace
    'MultiTenantSaas\\Models\\Memory\\EntityMemory' => 'MultiTenantSaas\\Models\\Memory\\EntityMemory', // Keep as-is, not moved
    'MultiTenantSaas\\Models\\Memory\\TenantMemory' => 'MultiTenantSaas\\Models\\Memory\\TenantMemory', // Keep as-is, not moved
];

// Services mapping
$servicesMap = [
    'MultiTenantSaas\\Services\\RbacService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\RbacService',
    'MultiTenantSaas\\Services\\MfaService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\MfaService',
    'MultiTenantSaas\\Services\\PasswordPolicyService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\PasswordPolicyService',
    'MultiTenantSaas\\Services\\SessionService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\SessionService',
    'MultiTenantSaas\\Services\\SsoService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\SsoService',
    'MultiTenantSaas\\Services\\SocialiteService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\SocialiteService',
    'MultiTenantSaas\\Services\\AlipayOAuthService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\AlipayOAuthService',
    'MultiTenantSaas\\Services\\PasswordService' => 'MultiTenantSaas\\Modules\\Auth\\Services\\PasswordService',
    'MultiTenantSaas\\Services\\TenantService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\TenantService',
    'MultiTenantSaas\\Services\\TenantMemberService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\TenantMemberService',
    'MultiTenantSaas\\Services\\TenantProfileService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\TenantProfileService',
    'MultiTenantSaas\\Services\\TenantOnboardingService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\TenantOnboardingService',
    'MultiTenantSaas\\Services\\TenantSettingService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\TenantSettingService',
    'MultiTenantSaas\\Services\\BackupService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\BackupService',
    'MultiTenantSaas\\Services\\HealthService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\HealthService',
    'MultiTenantSaas\\Services\\MailerService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\MailerService',
    'MultiTenantSaas\\Services\\ModuleBootstrapper' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\ModuleBootstrapper',
    'MultiTenantSaas\\Services\\ModuleManager' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\ModuleManager',
    'MultiTenantSaas\\Services\\ModuleRegistry' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\ModuleRegistry',
    'MultiTenantSaas\\Services\\IdGenerator' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\IdGenerator',
    'MultiTenantSaas\\Services\\SchedulerService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\SchedulerService',
    'MultiTenantSaas\\Services\\SearchService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\SearchService',
    'MultiTenantSaas\\Services\\ImageService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\ImageService',
    'MultiTenantSaas\\Services\\WebhookService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\WebhookService',
    'MultiTenantSaas\\Services\\RetentionService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\RetentionService',
    'MultiTenantSaas\\Services\\QuotaService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\QuotaService',
    'MultiTenantSaas\\Services\\MetricsService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\MetricsService',
    'MultiTenantSaas\\Services\\EventBusService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\EventBusService',
    'MultiTenantSaas\\Services\\AlertService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\AlertService',
    'MultiTenantSaas\\Services\\IpWhitelistService' => 'MultiTenantSaas\\Modules\\Infrastructure\\Services\\IpWhitelistService',
    'MultiTenantSaas\\Services\\NotificationService' => 'MultiTenantSaas\\Modules\\Notification\\Services\\NotificationService',
    'MultiTenantSaas\\Services\\MailTemplateService' => 'MultiTenantSaas\\Modules\\Notification\\Services\\MailTemplateService',
    'MultiTenantSaas\\Services\\InAppNotificationService' => 'MultiTenantSaas\\Modules\\Notification\\Services\\InAppNotificationService',
    'MultiTenantSaas\\Services\\AuditService' => 'MultiTenantSaas\\Modules\\Logging\\Services\\AuditService',
    'MultiTenantSaas\\Services\\FileService' => 'MultiTenantSaas\\Modules\\Storage\\Services\\FileService',
    'MultiTenantSaas\\Services\\InvoiceService' => 'MultiTenantSaas\\Modules\\Billing\\Services\\InvoiceService',
    'MultiTenantSaas\\Services\\PayService' => 'MultiTenantSaas\\Modules\\Billing\\Services\\PayService',
    'MultiTenantSaas\\Services\\SubscriptionService' => 'MultiTenantSaas\\Modules\\Billing\\Services\\SubscriptionService',
    'MultiTenantSaas\\Services\\DunningService' => 'MultiTenantSaas\\Modules\\Billing\\Services\\DunningService',
    'MultiTenantSaas\\Services\\RefundService' => 'MultiTenantSaas\\Modules\\Billing\\Services\\RefundService',
    'MultiTenantSaas\\Services\\UserService' => 'MultiTenantSaas\\Modules\\User\\Services\\UserService',
    'MultiTenantSaas\\Services\\OperatorService' => 'MultiTenantSaas\\Modules\\Operator\\Services\\OperatorService',
    'MultiTenantSaas\\Services\\LotteryService' => 'MultiTenantSaas\\Modules\\Lottery\\Services\\LotteryService',
    'MultiTenantSaas\\Services\\SandboxService' => 'MultiTenantSaas\\Modules\\DeveloperPortal\\Services\\SandboxService',
    'MultiTenantSaas\\Services\\FeatureFlagService' => 'MultiTenantSaas\\Modules\\Platform\\Services\\FeatureFlagService',
    'MultiTenantSaas\\Services\\ReportService' => 'MultiTenantSaas\\Modules\\Monitoring\\Services\\ReportService',
    'MultiTenantSaas\\Services\\TrialService' => 'MultiTenantSaas\\Modules\\Monitoring\\Services\\TrialService',
    'MultiTenantSaas\\Services\\PluginService' => 'MultiTenantSaas\\Modules\\Plugin\\Services\\PluginService',
    'MultiTenantSaas\\Services\\Agent\\AgentService' => 'MultiTenantSaas\\Modules\\Ai\\Services\\Agent\\AgentService',
    'MultiTenantSaas\\Services\\Mcp\\McpClientRegistry' => 'MultiTenantSaas\\Modules\\Ai\\Mcp\\McpClientRegistry',
];

// Middleware mapping
$middlewareMap = [
    'MultiTenantSaas\\Middleware\\IdentifyDomain' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\IdentifyDomain',
    'MultiTenantSaas\\Middleware\\IdentifyTenant' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\IdentifyTenant',
    'MultiTenantSaas\\Middleware\\EnsureTenantContext' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\EnsureTenantContext',
    'MultiTenantSaas\\Middleware\\SetLocale' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\SetLocale',
    'MultiTenantSaas\\Middleware\\CheckFeatureFlag' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\CheckFeatureFlag',
    'MultiTenantSaas\\Middleware\\CheckIpWhitelist' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\CheckIpWhitelist',
    'MultiTenantSaas\\Middleware\\McpMiddleware' => 'MultiTenantSaas\\Modules\\Infrastructure\\Http\\Middleware\\McpMiddleware',
    'MultiTenantSaas\\Middleware\\CheckPermission' => 'MultiTenantSaas\\Modules\\Auth\\Http\\Middleware\\CheckPermission',
    'MultiTenantSaas\\Middleware\\CheckRbacPermission' => 'MultiTenantSaas\\Modules\\Auth\\Http\\Middleware\\CheckRbacPermission',
];

// Merge all maps
$allMaps = array_merge($modelsMap, $servicesMap, $middlewareMap);

// Find all PHP files
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}

echo 'Found ' . count($files) . " PHP files to process\n";

$updatedFiles = 0;
$totalReplacements = 0;

foreach ($files as $filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    $fileReplacements = 0;

    foreach ($allMaps as $oldNamespace => $newNamespace) {
        // Skip no-op mappings
        if ($oldNamespace === $newNamespace) {
            continue;
        }

        $count = 0;
        $content = str_replace($oldNamespace, $newNamespace, $content, $count);
        $fileReplacements += $count;
    }

    if ($content !== $originalContent) {
        file_put_contents($filePath, $content);
        $updatedFiles++;
        $totalReplacements += $fileReplacements;
        echo "Updated: $filePath ($fileReplacements replacements)\n";
    }
}

echo "\n=== Summary ===\n";
echo 'Files processed: ' . count($files) . "\n";
echo "Files updated: $updatedFiles\n";
echo "Total replacements: $totalReplacements\n";
