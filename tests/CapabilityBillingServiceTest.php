<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\Capability\CapabilityBillingService;

class CapabilityBillingServiceTest extends TestCase
{
    private CapabilityBillingService $billingService;
    private Tenant $tenant;
    private User $user;
    private CreditAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billingService = new CapabilityBillingService();

        $this->tenant = Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            $this->user = User::create([
                'user_id' => 2001,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId((string) $this->tenant->tenant_id);

        $this->account = CreditAccount::create([
            'tenant_id' => $this->tenant->tenant_id,
            'user_id' => $this->user->user_id,
            'account_type' => 'personal',
            'balance' => 10000,
            'gift_balance' => 0,
            'recharge_balance' => 10000,
            'total_recharged' => 10000,
            'total_consumed' => 0,
        ]);
    }

    public function test_calculate_cost_text_generation(): void
    {
        $cost = $this->billingService->calculateCost('text_generation', 100);

        $this->assertSame(110, $cost); // 10 base + 100 tokens
    }

    public function test_calculate_cost_image_generation(): void
    {
        $cost = $this->billingService->calculateCost('image_generation');

        $this->assertSame(100, $cost); // 100 base, no token cost
    }

    public function test_calculate_cost_embedding(): void
    {
        $cost = $this->billingService->calculateCost('embedding', 500);

        $this->assertSame(502, $cost); // 2 base + 500 tokens
    }

    public function test_calculate_cost_unknown_capability_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->billingService->calculateCost('unknown_capability');
    }

    public function test_get_pricing(): void
    {
        $pricing = $this->billingService->getPricing('text_generation');

        $this->assertSame(10, $pricing['base_cost']);
        $this->assertSame(1, $pricing['per_token']);
    }

    public function test_get_pricing_unknown_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->billingService->getPricing('unknown');
    }

    public function test_get_all_pricing(): void
    {
        $pricing = $this->billingService->getAllPricing();

        $this->assertCount(13, $pricing);
        $this->assertArrayHasKey('text_generation', $pricing);
        $this->assertArrayHasKey('image_generation', $pricing);
        $this->assertArrayHasKey('video_generation', $pricing);
    }

    public function test_can_afford_true(): void
    {
        $this->assertTrue($this->billingService->canAfford($this->account, 'text_generation', 100));
    }

    public function test_can_afford_false(): void
    {
        $this->account->update(['balance' => 50]);

        $this->assertFalse($this->billingService->canAfford($this->account, 'video_generation'));
    }

    public function test_charge_success(): void
    {
        $result = new CapabilityResult(
            capability: 'text_generation',
            output: 'Hello',
            confidence: 1.0,
            tokenUsage: 100,
            durationMs: 500,
        );

        $chargeResult = $this->billingService->charge($this->account, $result);

        $this->assertTrue($chargeResult['success']);
        $this->assertSame(110, $chargeResult['cost']);
        $this->assertSame(9890, $chargeResult['balance']); // 10000 - 110
    }

    public function test_charge_insufficient_balance(): void
    {
        $this->account->update(['balance' => 50]);

        $result = new CapabilityResult(
            capability: 'video_generation',
            output: 'video.mp4',
            confidence: 1.0,
            tokenUsage: 0,
            durationMs: 5000,
        );

        $chargeResult = $this->billingService->charge($this->account, $result);

        $this->assertFalse($chargeResult['success']);
        $this->assertSame('Insufficient balance', $chargeResult['error']);
    }

    public function test_estimate_cost(): void
    {
        $estimate = $this->billingService->estimateCost('text_generation', 200);

        $this->assertSame('text_generation', $estimate['capability']);
        $this->assertSame(10, $estimate['base_cost']);
        $this->assertSame(1, $estimate['per_token']);
        $this->assertSame(200, $estimate['estimated_tokens']);
        $this->assertSame(210, $estimate['estimated_cost']);
    }

    public function test_all_capabilities_have_pricing(): void
    {
        $capabilities = [
            'text_generation', 'text_completion', 'text_summarization',
            'text_translation', 'text_classification', 'image_generation',
            'image_variation', 'image_editing', 'video_generation',
            'code_generation', 'code_review', 'conversation', 'embedding',
        ];

        foreach ($capabilities as $capability) {
            $pricing = $this->billingService->getPricing($capability);
            $this->assertIsArray($pricing);
            $this->assertArrayHasKey('base_cost', $pricing);
            $this->assertArrayHasKey('per_token', $pricing);
        }
    }
}
