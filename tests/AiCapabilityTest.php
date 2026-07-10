<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiTextService;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\CodeGeneration;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\CodeReview;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\Conversation;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\TextClassification;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\TextCompletion;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\TextGeneration;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\TextSummarization;
use MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities\TextTranslation;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\ChannelModule;

class AiCapabilityTest extends TestCase
{
    protected array $uses = [AiModule::class, ChannelModule::class];

    private function createMockTextService(): AiTextService
    {
        $mock = $this->createMock(AiTextService::class);
        $mock->method('chat')->willReturn(new AiResponse(
            content: 'Test response',
            usage: ['total_tokens' => 100],
        ));

        return $mock;
    }

    public function test_text_generation_name(): void
    {
        $capability = new TextGeneration($this->createMockTextService());
        $this->assertSame('text_generation', $capability->name());
    }

    public function test_text_generation_execute(): void
    {
        $capability = new TextGeneration($this->createMockTextService());
        $result = $capability->execute(['prompt' => 'Hello']);

        $this->assertSame('text_generation', $result->capability);
        $this->assertSame('Test response', $result->output);
        $this->assertTrue($result->isSuccess());
    }

    public function test_text_completion_name(): void
    {
        $capability = new TextCompletion($this->createMockTextService());
        $this->assertSame('text_completion', $capability->name());
    }

    public function test_text_completion_execute(): void
    {
        $capability = new TextCompletion($this->createMockTextService());
        $result = $capability->execute(['text' => 'Once upon a time']);

        $this->assertSame('text_completion', $result->capability);
        $this->assertTrue($result->isSuccess());
    }

    public function test_text_summarization_name(): void
    {
        $capability = new TextSummarization($this->createMockTextService());
        $this->assertSame('text_summarization', $capability->name());
    }

    public function test_text_summarization_execute(): void
    {
        $capability = new TextSummarization($this->createMockTextService());
        $result = $capability->execute(['text' => 'Long text here...']);

        $this->assertSame('text_summarization', $result->capability);
        $this->assertTrue($result->isSuccess());
    }

    public function test_text_translation_name(): void
    {
        $capability = new TextTranslation($this->createMockTextService());
        $this->assertSame('text_translation', $capability->name());
    }

    public function test_text_translation_execute(): void
    {
        $capability = new TextTranslation($this->createMockTextService());
        $result = $capability->execute(['text' => 'Hello', 'target_language' => 'zh']);

        $this->assertSame('text_translation', $result->capability);
        $this->assertTrue($result->isSuccess());
    }

    public function test_text_classification_name(): void
    {
        $capability = new TextClassification($this->createMockTextService());
        $this->assertSame('text_classification', $capability->name());
    }

    public function test_text_classification_execute(): void
    {
        $capability = new TextClassification($this->createMockTextService());
        $result = $capability->execute([
            'text' => 'This is great!',
            'categories' => ['positive', 'negative'],
        ]);

        $this->assertSame('text_classification', $result->capability);
    }

    public function test_code_generation_name(): void
    {
        $capability = new CodeGeneration($this->createMockTextService());
        $this->assertSame('code_generation', $capability->name());
    }

    public function test_code_generation_execute(): void
    {
        $capability = new CodeGeneration($this->createMockTextService());
        $result = $capability->execute([
            'description' => 'Create a hello world function',
            'language' => 'php',
        ]);

        $this->assertSame('code_generation', $result->capability);
        $this->assertTrue($result->isSuccess());
    }

    public function test_code_review_name(): void
    {
        $capability = new CodeReview($this->createMockTextService());
        $this->assertSame('code_review', $capability->name());
    }

    public function test_code_review_execute(): void
    {
        $capability = new CodeReview($this->createMockTextService());
        $result = $capability->execute(['code' => 'echo "hello";']);

        $this->assertSame('code_review', $result->capability);
        $this->assertTrue($result->isSuccess());
    }

    public function test_conversation_name(): void
    {
        $capability = new Conversation($this->createMockTextService());
        $this->assertSame('conversation', $capability->name());
    }

    public function test_conversation_execute(): void
    {
        $capability = new Conversation($this->createMockTextService());
        $result = $capability->execute([
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ]);

        $this->assertSame('conversation', $result->capability);
        $this->assertTrue($result->isSuccess());
    }

    public function test_capability_result_confidence(): void
    {
        $capability = new TextGeneration($this->createMockTextService());
        $result = $capability->execute(['prompt' => 'Test']);

        $this->assertGreaterThan(0.0, $result->confidence);
        $this->assertSame(100, $result->tokenUsage);
    }
}
