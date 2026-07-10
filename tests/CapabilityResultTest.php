<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\Capability\CapabilityResult;

class CapabilityResultTest extends TestCase
{
    public function test_constructor_with_all_properties(): void
    {
        $result = new CapabilityResult(
            capability: 'text_generation',
            output: 'Hello World',
            confidence: 0.95,
            tokenUsage: 150,
            durationMs: 320,
        );

        $this->assertSame('text_generation', $result->capability);
        $this->assertSame('Hello World', $result->output);
        $this->assertSame(0.95, $result->confidence);
        $this->assertSame(150, $result->tokenUsage);
        $this->assertSame(320, $result->durationMs);
    }

    public function test_constructor_with_default_values(): void
    {
        $result = new CapabilityResult(capability: 'empty');

        $this->assertSame('empty', $result->capability);
        $this->assertNull($result->output);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame(0, $result->tokenUsage);
        $this->assertSame(0, $result->durationMs);
    }

    public function test_is_success_returns_true_when_confidence_positive(): void
    {
        $result = new CapabilityResult(
            capability: 'test',
            output: 'result',
            confidence: 0.5,
            tokenUsage: 10,
            durationMs: 5,
        );

        $this->assertTrue($result->isSuccess());
    }

    public function test_is_success_returns_true_when_confidence_is_one(): void
    {
        $result = new CapabilityResult(
            capability: 'test',
            confidence: 1.0,
        );

        $this->assertTrue($result->isSuccess());
    }

    public function test_is_success_returns_false_when_confidence_is_zero(): void
    {
        $result = new CapabilityResult(capability: 'test');

        $this->assertFalse($result->isSuccess());
    }

    public function test_is_success_returns_false_when_confidence_is_negative(): void
    {
        $result = new CapabilityResult(
            capability: 'test',
            confidence: -0.1,
        );

        $this->assertFalse($result->isSuccess());
    }

    public function test_output_can_be_null(): void
    {
        $result = new CapabilityResult(
            capability: 'failed',
            output: null,
            confidence: 0.0,
        );

        $this->assertNull($result->output);
        $this->assertFalse($result->isSuccess());
    }

    public function test_output_can_be_array(): void
    {
        $result = new CapabilityResult(
            capability: 'search',
            output: ['results' => ['a', 'b'], 'total' => 2],
            confidence: 0.9,
            tokenUsage: 30,
        );

        $this->assertSame(['results' => ['a', 'b'], 'total' => 2], $result->output);
        $this->assertTrue($result->isSuccess());
    }

    public function test_readonly_properties(): void
    {
        $result = new CapabilityResult(
            capability: 'test',
            output: 'out',
            confidence: 0.8,
            tokenUsage: 10,
            durationMs: 50,
        );

        $this->assertSame('test', $result->capability);
        $this->assertSame('out', $result->output);
        $this->assertSame(0.8, $result->confidence);
        $this->assertSame(10, $result->tokenUsage);
        $this->assertSame(50, $result->durationMs);
    }
}
