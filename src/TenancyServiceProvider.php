<?php

namespace MultiTenantSaas;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MultiTenantSaas\Console\Commands\CheckTenantIsolation;
use MultiTenantSaas\Console\Commands\MemoryCleanupCommand;
use MultiTenantSaas\Console\Commands\MemoryDecayCommand;
use MultiTenantSaas\Console\Commands\MigrateAgentToolsToWorkflows;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Contracts\ConversationServiceContract;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\MemoryContract;
use MultiTenantSaas\Contracts\MessageServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Contracts\WorkflowRegistryContract;
use MultiTenantSaas\Contracts\WorkflowServiceContract;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Events\TenantSuspended;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Listeners\LogEventListener;
use MultiTenantSaas\Services\Agent\AgentMonitor;
use MultiTenantSaas\Services\Agent\AgentRuntime;
use MultiTenantSaas\Services\Agent\AgentService;
use MultiTenantSaas\Services\Agent\MemoryCompressor;
use MultiTenantSaas\Services\Agent\ToolRegistry;
use MultiTenantSaas\Services\Ai\AiTextService;
use MultiTenantSaas\Services\Ai\Storage\TenantConversationStore;
use Laravel\Ai\Contracts\ConversationStore;
use MultiTenantSaas\Services\AlertService;
use MultiTenantSaas\Services\AlipayOAuthService;
use MultiTenantSaas\Services\ApiVersionService;
use MultiTenantSaas\Services\CacheService;
use MultiTenantSaas\Services\Capability\CapabilityRegistry;
use MultiTenantSaas\Services\Capability\CapabilityService;
use MultiTenantSaas\Services\Capability\ClassifyCapability;
use MultiTenantSaas\Services\Capability\EmbeddingCapability;
use MultiTenantSaas\Services\Capability\ExtractCapability;
use MultiTenantSaas\Services\Capability\GenerateCapability;
use MultiTenantSaas\Services\Capability\IntentCapability;
use MultiTenantSaas\Services\Capability\OcrCapability;
use MultiTenantSaas\Services\Capability\RewriteCapability;
use MultiTenantSaas\Services\Capability\SearchCapability;
use MultiTenantSaas\Services\Capability\SentimentCapability;
use MultiTenantSaas\Services\Capability\SummarizeCapability;
use MultiTenantSaas\Services\Capability\TagCapability;
use MultiTenantSaas\Services\Capability\TranslateCapability;
use MultiTenantSaas\Services\Capability\VisionCapability;
use MultiTenantSaas\Services\Channel\ChannelManager;
use MultiTenantSaas\Services\Channel\MessageRouter;
use MultiTenantSaas\Services\Conversation\ConversationService;
use MultiTenantSaas\Services\Conversation\MentionService;
use MultiTenantSaas\Services\Conversation\MessageService;
use MultiTenantSaas\Services\Conversation\ReadStateService;
use MultiTenantSaas\Services\Conversation\SessionService;
use MultiTenantSaas\Services\Conversation\TagService;
use MultiTenantSaas\Services\CostService;
use MultiTenantSaas\Services\DeveloperPortalService;
use MultiTenantSaas\Services\EventBusService;
use MultiTenantSaas\Services\ExportService;
use MultiTenantSaas\Services\FeatureFlagService;
use MultiTenantSaas\Services\HealthService;
use MultiTenantSaas\Services\IdGenerator;
use MultiTenantSaas\Services\LoginLogService;
use MultiTenantSaas\Services\Memory\EntityMemory;
use MultiTenantSaas\Services\Memory\MemoryPipeline;
use MultiTenantSaas\Services\Memory\TenantMemory;
use MultiTenantSaas\Services\MetricsService;
use MultiTenantSaas\Services\PaymentSecurityService;
use MultiTenantSaas\Services\PerformanceService;
use MultiTenantSaas\Services\PluginService;
use MultiTenantSaas\Services\QueueService;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Services\ResourceService;
use MultiTenantSaas\Services\SandboxService;
use MultiTenantSaas\Services\SlaService;
use MultiTenantSaas\Services\SocialiteService;
use MultiTenantSaas\Services\StructuredLogService;
use MultiTenantSaas\Services\SubscriptionService;
use MultiTenantSaas\Services\TenantProfileService;
use MultiTenantSaas\Services\Tool\CacheGetTool;
use MultiTenantSaas\Services\Tool\CacheSetTool;
use MultiTenantSaas\Services\Tool\DocumentParseTool;
use MultiTenantSaas\Services\Tool\EmailSendTool;
use MultiTenantSaas\Services\Tool\EmbeddingGenerateTool;
use MultiTenantSaas\Services\Tool\FileReadTool;
use MultiTenantSaas\Services\Tool\FileWriteTool;
use MultiTenantSaas\Services\Tool\HttpRequestTool;
use MultiTenantSaas\Services\Tool\KnowledgeSearchTool;
use MultiTenantSaas\Services\Tool\LlmCallTool;
use MultiTenantSaas\Services\Tool\OcrRecognizeTool;
use MultiTenantSaas\Services\Tool\VectorSearchTool;
use MultiTenantSaas\Services\Tool\WebhookTriggerTool;
use MultiTenantSaas\Services\UserPreferenceService;
use MultiTenantSaas\Services\UserProfileService;
use MultiTenantSaas\Services\Workflow\WorkflowEngine;
use MultiTenantSaas\Services\Workflow\WorkflowRegistry;
use MultiTenantSaas\Services\Workflow\WorkflowService;

class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 发布核心配置
        $this->publishes([
            __DIR__.'/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        // 发布迁移
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'tenancy-migrations');

        // 发布模块配置
        $this->publishes([
            __DIR__.'/Modules/ApiToken/Config/apitoken.php' => config_path('apitoken.php'),
            __DIR__.'/Modules/Payment/Config/payment.php' => config_path('payment.php'),
        ], 'tenancy-modules-config');

        // 注册健康检查
        HealthService::registerChecks();

        // 注册 Artisan 命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckTenantIsolation::class,
                MemoryDecayCommand::class,
                MemoryCleanupCommand::class,
                MigrateAgentToolsToWorkflows::class,
            ]);
        }

        // 注册认证后 API 限流策略
        // 按用户 ID 限流，每分钟 60 次
        RateLimiter::for('api', function ($request) {
            $user = $request->user();

            return Limit::perMinute(60)->by(
                $user ? $user->getAuthIdentifier() : $request->ip()
            );
        });

        // 注册事件监听器（事件系统）
        Event::listen(TenantCreated::class, [LogEventListener::class, 'handleTenantCreated']);
        Event::listen(TenantSuspended::class, [LogEventListener::class, 'handleTenantSuspended']);
        Event::listen(TenantActivated::class, [LogEventListener::class, 'handleTenantActivated']);
        Event::listen(UserRegistered::class, [LogEventListener::class, 'handleUserRegistered']);
        Event::listen(UserLoggedIn::class, [LogEventListener::class, 'handleUserLoggedIn']);
    }

    public function register(): void
    {
        // 合并核心配置
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');

        // 合并模块配置
        $this->mergeConfigFrom(__DIR__.'/Modules/ApiToken/Config/apitoken.php', 'apitoken');
        $this->mergeConfigFrom(__DIR__.'/Modules/Payment/Config/payment.php', 'payment');
        $this->mergeConfigFrom(__DIR__.'/../config/channel.php', 'channel');

        // 注册ID生成器（绑定接口契约 + 具体实现）
        $this->app->singleton(IdGeneratorContract::class, function () {
            return new IdGenerator;
        });
        $this->app->alias(IdGeneratorContract::class, IdGenerator::class);

        // 注册租户上下文（绑定接口契约 + 具体实现）
        $this->app->singleton(TenantContextContract::class, function () {
            return new TenantContext;
        });
        $this->app->alias(TenantContextContract::class, TenantContext::class);

        // 注册 AI 文本推理服务（绑定接口契约 + 具体实现，AgentRuntime 推理引擎）
        $this->app->singleton(AiTextServiceContract::class, function () {
            return new AiTextService;
        });
        $this->app->alias(AiTextServiceContract::class, AiTextService::class);

        // 覆盖 laravel/ai 的 ConversationStore 绑定
        // 使用项目 IdGenerator（16位数字ID）替代 UUID7，并支持租户隔离
        $this->app->singleton(ConversationStore::class, function () {
            return new TenantConversationStore(
                config('ai.conversations.connection'),
            );
        });

        // 注册 Agent 服务（绑定接口契约 + 具体实现）
        $this->app->singleton(AgentServiceContract::class, function ($app) {
            return new AgentService(
                $app->make(TenantContextContract::class)
            );
        });
        $this->app->alias(AgentServiceContract::class, AgentService::class);

        // 注册 Agent 监控服务（绑定接口契约 + 具体实现）
        $this->app->singleton(AgentMonitorContract::class, function ($app) {
            return new AgentMonitor(
                $app->make(TenantContextContract::class)
            );
        });
        $this->app->alias(AgentMonitorContract::class, AgentMonitor::class);

        // 注册工具注册表（绑定接口契约 + 具体实现）
        $this->app->singleton(ToolRegistryContract::class, function ($app) {
            return new ToolRegistry(
                $app->make(Container::class)
            );
        });
        $this->app->alias(ToolRegistryContract::class, ToolRegistry::class);

        // 注册记忆压缩器
        $this->app->singleton(MemoryCompressor::class, function ($app) {
            return new MemoryCompressor(
                $app->make(AiTextServiceContract::class),
                $app->make(TenantContextContract::class),
            );
        });

        // 注册 Agent 运行时（绑定接口契约 + 具体实现，ReAct 循环引擎）
        $this->app->singleton(AgentRuntimeContract::class, function ($app) {
            return new AgentRuntime(
                $app->make(AiTextServiceContract::class),
                $app->make(ToolRegistryContract::class),
                $app->make(AgentMonitorContract::class),
                $app->make(TenantContextContract::class),
                $app->make(MemoryCompressor::class),
            );
        });
        $this->app->alias(AgentRuntimeContract::class, AgentRuntime::class);

        // 注册配置存储
        $this->app->singleton(TenantConfigStore::class, function () {
            return new TenantConfigStore;
        });

        // 注册 ApiToken 模块服务（仅在启用时）
        if (config('apitoken.enabled', false)) {
            $this->app->singleton(
                ApiTokenService::class
            );
        }

        // 注册 Payment 模块服务（仅在启用时）
        if (config('payment.enabled', false)) {
            $this->app->singleton(
                PaymentService::class
            );
        }

        // 注册支付宝 OAuth 服务
        $this->app->singleton(AlipayOAuthService::class);

        // 注册核心业务服务
        $this->app->singleton(UserProfileService::class);
        $this->app->singleton(UserPreferenceService::class);
        $this->app->singleton(LoginLogService::class);
        $this->app->singleton(StructuredLogService::class);
        $this->app->singleton(ApiVersionService::class);
        $this->app->singleton(ExportService::class);
        $this->app->singleton(PluginService::class);
        $this->app->singleton(RateLimitService::class);
        $this->app->singleton(AlertService::class);
        $this->app->singleton(PerformanceService::class);
        $this->app->singleton(CacheService::class);
        $this->app->singleton(PaymentSecurityService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(EventBusService::class);
        $this->app->singleton(TenantProfileService::class);
        $this->app->singleton(QueueService::class);
        $this->app->singleton(SocialiteService::class);
        $this->app->singleton(FeatureFlagService::class);
        $this->app->singleton(CostService::class);
        $this->app->singleton(ResourceService::class);

        // 注册 AI 网关服务（模型路由、提供商注册、限流、重试与请求日志）
        $this->app->singleton(AiGatewayService::class);

        // 注册 AI 视频服务（视频生成、异步任务轮询、结果存储）
        $this->app->singleton(AiVideoService::class);

        // 注册开发者门户与沙箱服务
        $this->app->singleton(DeveloperPortalService::class);
        $this->app->singleton(SandboxService::class);

        // 注册指标采集与 SLA 监控服务
        $this->app->singleton(MetricsService::class);
        $this->app->singleton(SlaService::class);

        $this->app->singleton(ConversationServiceContract::class, function ($app) {
            return new ConversationService(
                $app->make(IdGeneratorContract::class),
            );
        });
        $this->app->alias(ConversationServiceContract::class, ConversationService::class);

        $this->app->singleton(ChannelManager::class);
        $this->app->singleton(MessageRouter::class, function ($app) {
            return new MessageRouter(
                $app->make(ChannelManager::class),
                $app->make(ConversationServiceContract::class),
            );
        });

        $this->app->singleton(WorkflowEngineContract::class, function ($app) {
            return new WorkflowEngine(
                $app->make(TenantContextContract::class),
                $app->make(ToolRegistryContract::class),
            );
        });
        $this->app->alias(WorkflowEngineContract::class, WorkflowEngine::class);

        $this->app->singleton(WorkflowServiceContract::class, function ($app) {
            return new WorkflowService(
                $app->make(TenantContextContract::class),
                $app->make(WorkflowEngineContract::class),
            );
        });
        $this->app->alias(WorkflowServiceContract::class, WorkflowService::class);

        $this->app->singleton(WorkflowRegistryContract::class, function () {
            return new WorkflowRegistry;
        });
        $this->app->alias(WorkflowRegistryContract::class, WorkflowRegistry::class);

        $this->app->singleton(MessageServiceContract::class, function ($app) {
            return new MessageService(
                $app->make(IdGeneratorContract::class),
            );
        });
        $this->app->alias(MessageServiceContract::class, MessageService::class);

        $this->app->singleton(SessionService::class, function ($app) {
            return new SessionService(
                $app->make(IdGeneratorContract::class),
            );
        });

        $this->app->singleton(MentionService::class, function ($app) {
            return new MentionService(
                $app->make(IdGeneratorContract::class),
            );
        });

        $this->app->singleton(ReadStateService::class, function ($app) {
            return new ReadStateService(
                $app->make(IdGeneratorContract::class),
            );
        });

        $this->app->singleton(TagService::class, function ($app) {
            return new TagService(
                $app->make(IdGeneratorContract::class),
            );
        });

        $this->app->singleton(MemoryPipeline::class, function ($app) {
            return new MemoryPipeline(
                $app->make(TenantContextContract::class),
            );
        });

        $this->app->singleton(CapabilityService::class, function ($app) {
            return new CapabilityService(
                $app->make(CapabilityContract::class),
            );
        });

        $this->app->singleton(CapabilityContract::class, function ($app) {
            $registry = new CapabilityRegistry;
            $aiService = $app->make(AiTextServiceContract::class);
            $toolRegistry = $app->make(ToolRegistryContract::class);

            $registry->register('summarize', new SummarizeCapability($aiService));
            $registry->register('tag', new TagCapability($aiService));
            $registry->register('translate', new TranslateCapability($aiService));
            $registry->register('intent', new IntentCapability($aiService));
            $registry->register('sentiment', new SentimentCapability($aiService));
            $registry->register('extract', new ExtractCapability($aiService));
            $registry->register('classify', new ClassifyCapability($aiService));
            $registry->register('rewrite', new RewriteCapability($aiService));
            $registry->register('generate', new GenerateCapability($aiService));
            $registry->register('search', new SearchCapability($toolRegistry));
            $registry->register('ocr', new OcrCapability);
            $registry->register('vision', new VisionCapability);
            $registry->register('embedding', new EmbeddingCapability);

            return $registry;
        });
        $this->app->alias(CapabilityContract::class, CapabilityRegistry::class);

        $this->app->bind(TenantMemory::class, function ($app) {
            $tenantId = $app->make(TenantContextContract::class)->resolveId();

            return new TenantMemory((int) $tenantId);
        });
        $this->app->alias(TenantMemory::class, MemoryContract::class);

        $this->app->bind(EntityMemory::class, function ($app) {
            $tenantId = $app->make(TenantContextContract::class)->resolveId();

            return new EntityMemory((int) $tenantId);
        });

        $this->registerFrameworkTools();
    }

    private function registerFrameworkTools(): void
    {
        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register('llm_call', 'LLM Call', 'Send a prompt to an AI language model and receive a completion', LlmCallTool::class, [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string', 'description' => 'The prompt to send to the AI model'],
                'system_prompt' => ['type' => 'string', 'description' => 'Optional system prompt'],
                'model' => ['type' => 'string', 'description' => 'Model name override'],
                'temperature' => ['type' => 'number', 'description' => 'Sampling temperature'],
                'max_tokens' => ['type' => 'integer', 'description' => 'Max output tokens'],
            ],
            'required' => ['prompt'],
        ], 'ai');

        $registry->register('http_request', 'HTTP Request', 'Make an HTTP request to an external URL', HttpRequestTool::class, [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Request URL'],
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                'headers' => ['type' => 'object', 'description' => 'Request headers'],
                'body' => ['type' => 'object', 'description' => 'Request body'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout in seconds'],
            ],
            'required' => ['url'],
        ], 'core');

        $registry->register('webhook_trigger', 'Webhook Trigger', 'Send a webhook notification to an external URL', WebhookTriggerTool::class, [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Webhook URL'],
                'payload' => ['type' => 'object', 'description' => 'Webhook payload'],
                'headers' => ['type' => 'object', 'description' => 'Additional headers'],
                'secret' => ['type' => 'string', 'description' => 'HMAC signing secret'],
            ],
            'required' => ['url'],
        ], 'channel');

        $registry->register('email_send', 'Send Email', 'Send an email message to recipients', EmailSendTool::class, [
            'type' => 'object',
            'properties' => [
                'to' => ['type' => 'string', 'description' => 'Recipient email'],
                'subject' => ['type' => 'string', 'description' => 'Email subject'],
                'body' => ['type' => 'string', 'description' => 'Email body'],
                'from' => ['type' => 'string', 'description' => 'Sender email'],
                'cc' => ['type' => 'array', 'description' => 'CC recipients'],
                'bcc' => ['type' => 'array', 'description' => 'BCC recipients'],
            ],
            'required' => ['to', 'subject', 'body'],
        ], 'core');

        $registry->register('file_read', 'Read File', 'Read a file from tenant storage', FileReadTool::class, [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to tenant storage'],
                'disk' => ['type' => 'string', 'description' => 'Storage disk name'],
                'encoding' => ['type' => 'string', 'enum' => ['utf-8', 'binary']],
            ],
            'required' => ['path'],
        ], 'storage');

        $registry->register('file_write', 'Write File', 'Write content to a file in tenant storage', FileWriteTool::class, [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path relative to tenant storage'],
                'content' => ['type' => 'string', 'description' => 'File content'],
                'disk' => ['type' => 'string', 'description' => 'Storage disk name'],
                'encoding' => ['type' => 'string', 'enum' => ['utf-8', 'base64']],
            ],
            'required' => ['path', 'content'],
        ], 'storage');

        $registry->register('cache_get', 'Get Cache', 'Retrieve a value from the cache by key', CacheGetTool::class, [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Cache key'],
                'default' => ['description' => 'Default value if key not found'],
            ],
            'required' => ['key'],
        ], 'core');

        $registry->register('cache_set', 'Set Cache', 'Store a value in the cache with optional TTL', CacheSetTool::class, [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Cache key'],
                'value' => ['description' => 'Value to cache'],
                'ttl' => ['type' => 'integer', 'description' => 'TTL in seconds'],
            ],
            'required' => ['key', 'value'],
        ], 'core');

        $registry->register('ocr_recognize', 'OCR Recognize', 'Extract text from an image using OCR', OcrRecognizeTool::class, [
            'type' => 'object',
            'properties' => [
                'image_url' => ['type' => 'string', 'description' => 'Image URL'],
                'image_base64' => ['type' => 'string', 'description' => 'Base64 image data'],
                'language' => ['type' => 'string', 'description' => 'OCR language'],
            ],
        ], 'ai');

        $registry->register('vector_search', 'Vector Search', 'Search for similar content using vector embeddings', VectorSearchTool::class, [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'top_k' => ['type' => 'integer', 'description' => 'Number of results'],
                'collection' => ['type' => 'string', 'description' => 'Vector collection name'],
                'filters' => ['type' => 'object', 'description' => 'Filter conditions'],
            ],
            'required' => ['query'],
        ], 'kb');

        $registry->register('embedding_generate', 'Generate Embedding', 'Generate vector embeddings for text', EmbeddingGenerateTool::class, [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Text to embed'],
                'model' => ['type' => 'string', 'description' => 'Embedding model'],
            ],
            'required' => ['text'],
        ], 'ai');

        $registry->register('knowledge_search', 'Knowledge Search', 'Search knowledge bases for relevant information', KnowledgeSearchTool::class, [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'knowledge_base_id' => ['type' => 'string', 'description' => 'Knowledge base ID'],
                'top_k' => ['type' => 'integer', 'description' => 'Number of results'],
            ],
            'required' => ['query'],
        ], 'kb');

        $registry->register('document_parse', 'Parse Document', 'Parse and extract content from a document', DocumentParseTool::class, [
            'type' => 'object',
            'properties' => [
                'file_id' => ['type' => 'string', 'description' => 'File ID'],
                'file_url' => ['type' => 'string', 'description' => 'File URL'],
                'format' => ['type' => 'string', 'description' => 'Expected format'],
            ],
        ], 'storage');
    }
}
