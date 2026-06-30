<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use MultiTenantSaas\Models\AgentTool;
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

class FrameworkToolSeeder extends Seeder
{
    public function run(): void
    {
        $tools = [
            [
                'slug' => 'llm_call',
                'name' => 'LLM Call',
                'description' => 'Send a prompt to the AI model and get a response',
                'category' => 'ai',
                'handler_class' => LlmCallTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'The prompt to send to the AI model'],
                        'system_prompt' => ['type' => 'string', 'description' => 'Optional system prompt'],
                        'model' => ['type' => 'string', 'description' => 'Model name override'],
                        'temperature' => ['type' => 'number', 'description' => 'Sampling temperature'],
                        'max_tokens' => ['type' => 'integer', 'description' => 'Max output tokens'],
                    ],
                    'required' => ['prompt'],
                ],
            ],
            [
                'slug' => 'http_request',
                'name' => 'HTTP Request',
                'description' => 'Make an HTTP request to an external API',
                'category' => 'core',
                'handler_class' => HttpRequestTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Request URL'],
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                        'headers' => ['type' => 'object', 'description' => 'Request headers'],
                        'body' => ['type' => 'object', 'description' => 'Request body'],
                        'timeout' => ['type' => 'integer', 'description' => 'Timeout in seconds'],
                    ],
                    'required' => ['url'],
                ],
            ],
            [
                'slug' => 'webhook_trigger',
                'name' => 'Webhook Trigger',
                'description' => 'Trigger an external webhook with a payload',
                'category' => 'channel',
                'handler_class' => WebhookTriggerTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Webhook URL'],
                        'payload' => ['type' => 'object', 'description' => 'Webhook payload'],
                        'headers' => ['type' => 'object', 'description' => 'Additional headers'],
                        'secret' => ['type' => 'string', 'description' => 'HMAC signing secret'],
                    ],
                    'required' => ['url'],
                ],
            ],
            [
                'slug' => 'email_send',
                'name' => 'Email Send',
                'description' => 'Send an email message',
                'category' => 'core',
                'handler_class' => EmailSendTool::class,
                'parameters_schema' => [
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
                ],
            ],
            [
                'slug' => 'file_read',
                'name' => 'File Read',
                'description' => 'Read a file from tenant storage',
                'category' => 'storage',
                'handler_class' => FileReadTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path relative to tenant storage'],
                        'disk' => ['type' => 'string', 'description' => 'Storage disk name'],
                        'encoding' => ['type' => 'string', 'enum' => ['utf-8', 'binary']],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'slug' => 'file_write',
                'name' => 'File Write',
                'description' => 'Write a file to tenant storage',
                'category' => 'storage',
                'handler_class' => FileWriteTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path relative to tenant storage'],
                        'content' => ['type' => 'string', 'description' => 'File content'],
                        'disk' => ['type' => 'string', 'description' => 'Storage disk name'],
                        'encoding' => ['type' => 'string', 'enum' => ['utf-8', 'base64']],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
            [
                'slug' => 'cache_get',
                'name' => 'Cache Get',
                'description' => 'Get a value from the cache',
                'category' => 'core',
                'handler_class' => CacheGetTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Cache key'],
                        'default' => ['description' => 'Default value if key not found'],
                    ],
                    'required' => ['key'],
                ],
            ],
            [
                'slug' => 'cache_set',
                'name' => 'Cache Set',
                'description' => 'Set a value in the cache',
                'category' => 'core',
                'handler_class' => CacheSetTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Cache key'],
                        'value' => ['description' => 'Value to cache'],
                        'ttl' => ['type' => 'integer', 'description' => 'TTL in seconds'],
                    ],
                    'required' => ['key', 'value'],
                ],
            ],
            [
                'slug' => 'ocr_recognize',
                'name' => 'OCR Recognize',
                'description' => 'Recognize text from images using OCR',
                'category' => 'ai',
                'handler_class' => OcrRecognizeTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string', 'description' => 'Image URL'],
                        'image_base64' => ['type' => 'string', 'description' => 'Base64 image data'],
                        'language' => ['type' => 'string', 'description' => 'OCR language'],
                    ],
                ],
            ],
            [
                'slug' => 'vector_search',
                'name' => 'Vector Search',
                'description' => 'Search using vector similarity',
                'category' => 'kb',
                'handler_class' => VectorSearchTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query'],
                        'top_k' => ['type' => 'integer', 'description' => 'Number of results'],
                        'collection' => ['type' => 'string', 'description' => 'Vector collection name'],
                        'filters' => ['type' => 'object', 'description' => 'Filter conditions'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'slug' => 'embedding_generate',
                'name' => 'Embedding Generate',
                'description' => 'Generate vector embeddings from text',
                'category' => 'ai',
                'handler_class' => EmbeddingGenerateTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'Text to embed'],
                        'model' => ['type' => 'string', 'description' => 'Embedding model'],
                    ],
                    'required' => ['text'],
                ],
            ],
            [
                'slug' => 'knowledge_search',
                'name' => 'Knowledge Search',
                'description' => 'Search the knowledge base',
                'category' => 'kb',
                'handler_class' => KnowledgeSearchTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query'],
                        'knowledge_base_id' => ['type' => 'string', 'description' => 'Knowledge base ID'],
                        'top_k' => ['type' => 'integer', 'description' => 'Number of results'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'slug' => 'document_parse',
                'name' => 'Document Parse',
                'description' => 'Parse a document and extract structured data',
                'category' => 'storage',
                'handler_class' => DocumentParseTool::class,
                'parameters_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'file_id' => ['type' => 'string', 'description' => 'File ID'],
                        'file_url' => ['type' => 'string', 'description' => 'File URL'],
                        'format' => ['type' => 'string', 'description' => 'Expected format'],
                    ],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            AgentTool::updateOrCreate(
                ['slug' => $tool['slug'], 'tenant_id' => 0],
                array_merge($tool, [
                    'enabled' => true,
                ]),
            );
        }

        $this->command->info('Framework tools seeded: '.count($tools).' tools');
    }
}
