<?php

namespace MultiTenantSaas\Modules\Ai;

use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\ConversationStore;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Contracts\McpToolRegistryContract;
use MultiTenantSaas\Contracts\MemoryContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Modules\Ai\Mcp\McpClientRegistry;
use MultiTenantSaas\Modules\Ai\Mcp\McpRouteMacro;
use MultiTenantSaas\Modules\Ai\Mcp\McpSkillGenerator;
use MultiTenantSaas\Modules\Ai\Mcp\McpToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentMonitor;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentService;
use MultiTenantSaas\Modules\Ai\Services\Agent\MemoryCompressor;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiGatewayService;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiTextService;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiVideoService;
use MultiTenantSaas\Modules\Ai\Services\Ai\Storage\TenantConversationStore;
use MultiTenantSaas\Modules\Ai\Services\Capability\CapabilityRegistry;
use MultiTenantSaas\Modules\Ai\Services\Capability\CapabilityService;
use MultiTenantSaas\Modules\Ai\Services\Capability\ClassifyCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\EmbeddingCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\ExtractCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\GenerateCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\IntentCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\OcrCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\RewriteCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\SearchCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\SentimentCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\SummarizeCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\TagCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\TranslateCapability;
use MultiTenantSaas\Modules\Ai\Services\Capability\VisionCapability;
use MultiTenantSaas\Modules\Ai\Services\AiOptional;
use MultiTenantSaas\Modules\Ai\Services\AiConfigService;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Ai\Services\IntentRouter;
use MultiTenantSaas\Modules\Ai\Services\Memory\EntityMemory;
use MultiTenantSaas\Modules\Ai\Services\Memory\MemoryPipeline;
use MultiTenantSaas\Modules\Ai\Services\Memory\TenantMemory;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbEmbedder;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbIndexer;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbRegistry;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbSearchService;
use MultiTenantSaas\Modules\Ai\Services\Tool\CacheGetTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\CacheSetTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\DataDictionaryTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\DelegateToAgentTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\DocumentParseTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\EmailSendTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\EmbeddingGenerateTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\FileReadTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\FileWriteTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\HttpRequestTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\KnowledgeSearchTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\ListAgentsTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\LlmCallTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\NavigateTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\OcrRecognizeTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\SystemKbSearchTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\VectorSearchTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\WebhookTriggerTool;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class AiServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ai';

    protected function bootModule(): void
    {
        McpRouteMacro::register();
    }

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(AiTextServiceContract::class, fn () => new AiTextService);
        $this->app->alias(AiTextServiceContract::class, AiTextService::class);

        $this->app->singleton(ConversationStore::class, fn () => new TenantConversationStore(config('ai.conversations.connection')));
        $this->app->singleton(AgentServiceContract::class, fn ($app) => new AgentService($app->make(TenantContextContract::class)));
        $this->app->alias(AgentServiceContract::class, AgentService::class);
        $this->app->singleton(AgentMonitorContract::class, fn ($app) => new AgentMonitor($app->make(TenantContextContract::class)));
        $this->app->alias(AgentMonitorContract::class, AgentMonitor::class);
        $this->app->singleton(ToolRegistryContract::class, fn ($app) => new ToolRegistry($app->make(Container::class)));
        $this->app->alias(ToolRegistryContract::class, ToolRegistry::class);
        $this->app->singleton(MemoryCompressor::class, fn ($app) => new MemoryCompressor($app->make(AiTextServiceContract::class), $app->make(TenantContextContract::class)));
        $this->app->singleton(AgentRuntimeContract::class, fn ($app) => new AgentRuntime($app->make(AiTextServiceContract::class), $app->make(ToolRegistryContract::class), $app->make(AgentMonitorContract::class), $app->make(TenantContextContract::class), $app->bound(WorkflowEngineContract::class) ? $app->make(WorkflowEngineContract::class) : null, $app->bound(MemoryCompressor::class) ? $app->make(MemoryCompressor::class) : null));
        $this->app->alias(AgentRuntimeContract::class, AgentRuntime::class);
        $this->app->singleton(McpToolRegistryContract::class, fn ($app) => new McpToolRegistry($app->make(Container::class)));
        $this->app->alias(McpToolRegistryContract::class, McpToolRegistry::class);
        $this->app->singleton(McpClientRegistry::class);
        $this->app->singleton(McpSkillGenerator::class, fn ($app) => new McpSkillGenerator($app->make(McpToolRegistryContract::class), $app->make(McpClientRegistry::class)));
        $this->app->singleton(CapabilityService::class, fn ($app) => new CapabilityService($app->make(CapabilityContract::class)));
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
        $this->app->singleton(MemoryPipeline::class, fn ($app) => new MemoryPipeline($app->make(TenantContextContract::class)));
        $this->app->bind(TenantMemory::class, fn ($app) => new TenantMemory((int) $app->make(TenantContextContract::class)->resolveId()));
        $this->app->alias(TenantMemory::class, MemoryContract::class);
        $this->app->bind(EntityMemory::class, fn ($app) => new EntityMemory((int) $app->make(TenantContextContract::class)->resolveId()));
        $this->app->singleton(AiGatewayService::class);
        $this->app->singleton(AiVideoService::class);

        // F1: AiOptional 可选性包装器
        $this->app->singleton(AiOptional::class, fn ($app) => new AiOptional(
            $app->make(AiConfigService::class),
            $app->make(AiUsageService::class),
            $app->bound(AgentMonitorContract::class) ? $app->make(AgentMonitorContract::class) : null,
        ));

        // F3: IntentRouter 意图路由器
        $this->app->singleton(IntentRouter::class);

        // 系统小秘书：知识库底座（平台级，无租户隔离）
        $this->app->singleton(SystemKbRegistry::class, fn () => new SystemKbRegistry);
        $this->app->singleton(SystemKbEmbedder::class, fn () => new SystemKbEmbedder);
        $this->app->singleton(SystemKbIndexer::class, fn ($app) => new SystemKbIndexer(
            $app->make(SystemKbRegistry::class),
            $app->make(SystemKbEmbedder::class),
        ));
        $this->app->singleton(SystemKbSearchService::class, fn ($app) => new SystemKbSearchService(
            $app->make(SystemKbEmbedder::class),
        ));

        $this->registerFrameworkTools();
    }

    private function registerFrameworkTools(): void
    {
        $registry = $this->app->make(ToolRegistryContract::class);
        $registry->register('llm_call', 'LLM Call', 'Send a prompt to an AI language model', LlmCallTool::class, ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string']], 'required' => ['prompt']], 'ai');
        $registry->register('http_request', 'HTTP Request', 'Make an HTTP request', HttpRequestTool::class, ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']], 'core');
        $registry->register('webhook_trigger', 'Webhook Trigger', 'Send a webhook', WebhookTriggerTool::class, ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']], 'channel');
        $registry->register('email_send', 'Send Email', 'Send an email', EmailSendTool::class, ['type' => 'object', 'properties' => ['to' => ['type' => 'string']], 'required' => ['to', 'subject', 'body']], 'core');
        $registry->register('file_read', 'Read File', 'Read a file', FileReadTool::class, ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']], 'storage');
        $registry->register('file_write', 'Write File', 'Write a file', FileWriteTool::class, ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path', 'content']], 'storage');
        $registry->register('cache_get', 'Get Cache', 'Get cache value', CacheGetTool::class, ['type' => 'object', 'properties' => ['key' => ['type' => 'string']], 'required' => ['key']], 'core');
        $registry->register('cache_set', 'Set Cache', 'Set cache value', CacheSetTool::class, ['type' => 'object', 'properties' => ['key' => ['type' => 'string']], 'required' => ['key', 'value']], 'core');
        $registry->register('ocr_recognize', 'OCR Recognize', 'Extract text from image', OcrRecognizeTool::class, ['type' => 'object', 'properties' => ['image_url' => ['type' => 'string']]], 'ai');
        $registry->register('vector_search', 'Vector Search', 'Search similar content', VectorSearchTool::class, ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']], 'kb');
        $registry->register('embedding_generate', 'Generate Embedding', 'Generate embeddings', EmbeddingGenerateTool::class, ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']], 'ai');
        $registry->register('knowledge_search', 'Knowledge Search', 'Search knowledge bases', KnowledgeSearchTool::class, ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']], 'kb');
        $registry->register('document_parse', 'Parse Document', 'Parse a document', DocumentParseTool::class, ['type' => 'object', 'properties' => ['file_id' => ['type' => 'string']]], 'storage');

        // 系统小秘书专属工具（category=secretary）
        $registry->register('system_kb_search', 'System KB Search', 'Search the platform system knowledge base for how-to guides, feature locations and business flows', SystemKbSearchTool::class, ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => '检索问题（自然语言）'], 'top_k' => ['type' => 'integer', 'description' => '返回片段数 1-10，默认 5']], 'required' => ['query']], 'secretary');
        $registry->register('get_data_dictionary', 'Data Dictionary', 'Look up database tables and columns; pass table for column details or keyword to filter the table list', DataDictionaryTool::class, ['type' => 'object', 'properties' => ['table' => ['type' => 'string', 'description' => '表名（可选，传则返回字段明细）'], 'keyword' => ['type' => 'string', 'description' => '表清单过滤关键词（可选）']]], 'secretary');
        $registry->register('navigate', 'Navigate', 'Return an in-app navigation instruction {route_path,label} for the frontend to jump to a page', NavigateTool::class, ['type' => 'object', 'properties' => ['route_path' => ['type' => 'string', 'description' => '站内路由路径，以 / 开头'], 'label' => ['type' => 'string', 'description' => '按钮文案（可选）']], 'required' => ['route_path']], 'secretary');
        $registry->register('list_agents', 'List Agents', 'List enabled digital employees of current tenant with their roles and specialities', ListAgentsTool::class, ['type' => 'object', 'properties' => []], 'secretary');
        $registry->register('delegate_to_agent', 'Delegate To Agent', 'Hand the conversation off to a specialised digital employee; returns a delegate instruction for the frontend', DelegateToAgentTool::class, ['type' => 'object', 'properties' => ['agent_id' => ['type' => 'string', 'description' => '目标员工 agent_id（先用 list_agents 查询）'], 'reason' => ['type' => 'string', 'description' => '转派原因'], 'handoff_message' => ['type' => 'string', 'description' => '带给目标员工的开场消息']], 'required' => ['agent_id']], 'secretary');
    }
}
