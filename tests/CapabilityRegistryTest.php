<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Modules\Ai\Services\Capability\CapabilityRegistry;

class CapabilityRegistryTest extends TestCase
{
    private CapabilityRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new CapabilityRegistry;
    }

    private function createMockCapability(string $name, ?CapabilityResult $result = null): CapabilityContract
    {
        $mock = $this->createMock(CapabilityContract::class);
        $mock->method('name')->willReturn($name);
        if ($result !== null) {
            $mock->method('execute')->willReturn($result);
        }

        return $mock;
    }

    public function test_register_and_get(): void
    {
        $capability = $this->createMockCapability('text_generation');
        $this->registry->register('text_generation', $capability);

        $this->assertSame($capability, $this->registry->get('text_generation'));
    }

    public function test_get_returns_null_for_unknown(): void
    {
        $this->assertNull($this->registry->get('unknown'));
    }

    public function test_has_returns_true(): void
    {
        $capability = $this->createMockCapability('text_generation');
        $this->registry->register('text_generation', $capability);

        $this->assertTrue($this->registry->has('text_generation'));
    }

    public function test_has_returns_false(): void
    {
        $this->assertFalse($this->registry->has('unknown'));
    }

    public function test_all_returns_registered_capabilities(): void
    {
        $cap1 = $this->createMockCapability('text_generation');
        $cap2 = $this->createMockCapability('image_generation');

        $this->registry->register('text_generation', $cap1);
        $this->registry->register('image_generation', $cap2);

        $all = $this->registry->all();

        $this->assertCount(2, $all);
        $this->assertSame($cap1, $all['text_generation']);
        $this->assertSame($cap2, $all['image_generation']);
    }

    public function test_all_returns_empty_when_none_registered(): void
    {
        $this->assertSame([], $this->registry->all());
    }

    public function test_names_returns_registered_names(): void
    {
        $this->registry->register('text_generation', $this->createMockCapability('text_generation'));
        $this->registry->register('image_generation', $this->createMockCapability('image_generation'));

        $names = $this->registry->names();

        $this->assertCount(2, $names);
        $this->assertContains('text_generation', $names);
        $this->assertContains('image_generation', $names);
    }

    public function test_names_returns_empty_when_none_registered(): void
    {
        $this->assertSame([], $this->registry->names());
    }

    public function test_find_by_prefix(): void
    {
        $this->registry->register('text_generation', $this->createMockCapability('text_generation'));
        $this->registry->register('text_completion', $this->createMockCapability('text_completion'));
        $this->registry->register('image_generation', $this->createMockCapability('image_generation'));
        $this->registry->register('image_variation', $this->createMockCapability('image_variation'));

        $textCapabilities = $this->registry->findByPrefix('text_');

        $this->assertCount(2, $textCapabilities);
        $this->assertArrayHasKey('text_generation', $textCapabilities);
        $this->assertArrayHasKey('text_completion', $textCapabilities);
    }

    public function test_find_by_prefix_no_match(): void
    {
        $this->registry->register('text_generation', $this->createMockCapability('text_generation'));

        $result = $this->registry->findByPrefix('image_');

        $this->assertSame([], $result);
    }

    public function test_find_by_prefix_empty_registry(): void
    {
        $this->assertSame([], $this->registry->findByPrefix('text_'));
    }

    public function test_discover(): void
    {
        $cap1 = $this->createMockCapability('text_generation');
        $cap2 = $this->createMockCapability('image_generation');

        $this->registry->register('text_generation', $cap1);
        $this->registry->register('image_generation', $cap2);

        $discovered = $this->registry->discover();

        $this->assertCount(2, $discovered);
        $this->assertSame('text_generation', $discovered[0]['name']);
        $this->assertSame(get_class($cap1), $discovered[0]['class']);
        $this->assertSame('image_generation', $discovered[1]['name']);
        $this->assertSame(get_class($cap2), $discovered[1]['class']);
    }

    public function test_discover_empty_registry(): void
    {
        $this->assertSame([], $this->registry->discover());
    }

    public function test_execute_success(): void
    {
        $result = new CapabilityResult(
            capability: 'text_generation',
            output: 'Hello World',
            confidence: 1.0,
            tokenUsage: 50,
            durationMs: 100,
        );

        $capability = $this->createMockCapability('text_generation', $result);
        $this->registry->register('text_generation', $capability);

        $executionResult = $this->registry->execute('text_generation', ['prompt' => 'Hello']);

        $this->assertSame('text_generation', $executionResult->capability);
        $this->assertSame('Hello World', $executionResult->output);
        $this->assertSame(1.0, $executionResult->confidence);
        $this->assertSame(50, $executionResult->tokenUsage);
        $this->assertGreaterThanOrEqual(0, $executionResult->durationMs);
    }

    public function test_execute_unknown_capability(): void
    {
        $result = $this->registry->execute('unknown', []);

        $this->assertSame('unknown', $result->capability);
        $this->assertNull($result->output);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame(0, $result->tokenUsage);
        $this->assertSame(0, $result->durationMs);
    }

    public function test_execute_measures_duration(): void
    {
        $result = new CapabilityResult(
            capability: 'fast',
            output: 'done',
            confidence: 1.0,
            tokenUsage: 0,
            durationMs: 0,
        );

        $capability = $this->createMockCapability('fast', $result);
        $this->registry->register('fast', $capability);

        $executionResult = $this->registry->execute('fast', []);

        $this->assertGreaterThanOrEqual(0, $executionResult->durationMs);
    }

    public function test_register_overwrites_existing(): void
    {
        $cap1 = $this->createMockCapability('text_generation');
        $cap2 = $this->createMockCapability('text_generation');

        $this->registry->register('text_generation', $cap1);
        $this->registry->register('text_generation', $cap2);

        $this->assertSame($cap2, $this->registry->get('text_generation'));
    }
}
