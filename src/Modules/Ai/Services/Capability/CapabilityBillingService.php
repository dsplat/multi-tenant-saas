<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Capability;

use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Models\CreditAccount;

class CapabilityBillingService
{
    /**
     * @var array<string, array{base_cost: int, per_token: int}>
     */
    private const PRICING = [
        'text_generation' => ['base_cost' => 10, 'per_token' => 1],
        'text_completion' => ['base_cost' => 8, 'per_token' => 1],
        'text_summarization' => ['base_cost' => 15, 'per_token' => 1],
        'text_translation' => ['base_cost' => 12, 'per_token' => 1],
        'text_classification' => ['base_cost' => 5, 'per_token' => 1],
        'image_generation' => ['base_cost' => 100, 'per_token' => 0],
        'image_variation' => ['base_cost' => 80, 'per_token' => 0],
        'image_editing' => ['base_cost' => 120, 'per_token' => 0],
        'video_generation' => ['base_cost' => 500, 'per_token' => 0],
        'code_generation' => ['base_cost' => 20, 'per_token' => 1],
        'code_review' => ['base_cost' => 15, 'per_token' => 1],
        'conversation' => ['base_cost' => 10, 'per_token' => 1],
        'embedding' => ['base_cost' => 2, 'per_token' => 1],
    ];

    public function calculateCost(string $capability, int $tokenUsage = 0): int
    {
        $pricing = self::PRICING[$capability] ?? null;

        if ($pricing === null) {
            throw new \InvalidArgumentException("Unknown capability: {$capability}");
        }

        return $pricing['base_cost'] + ($pricing['per_token'] * $tokenUsage);
    }

    public function getPricing(string $capability): array
    {
        $pricing = self::PRICING[$capability] ?? null;

        if ($pricing === null) {
            throw new \InvalidArgumentException("Unknown capability: {$capability}");
        }

        return $pricing;
    }

    public function getAllPricing(): array
    {
        return self::PRICING;
    }

    public function canAfford(CreditAccount $account, string $capability, int $tokenUsage = 0): bool
    {
        $cost = $this->calculateCost($capability, $tokenUsage);

        return $account->hasEnoughBalance($cost);
    }

    public function charge(CreditAccount $account, CapabilityResult $result): array
    {
        $cost = $this->calculateCost($result->capability, $result->tokenUsage);

        if (! $account->hasEnoughBalance($cost)) {
            return [
                'success' => false,
                'cost' => $cost,
                'balance' => $account->balance,
                'error' => 'Insufficient balance',
            ];
        }

        $transaction = $account->consume(
            $cost,
            'capability',
            $result->capability,
            "Capability usage: {$result->capability}",
        );

        return [
            'success' => true,
            'cost' => $cost,
            'balance' => $account->balance,
            'transaction_id' => $transaction->credit_transaction_id ?? null,
        ];
    }

    public function estimateCost(string $capability, int $estimatedTokens = 0): array
    {
        $pricing = $this->getPricing($capability);
        $estimatedCost = $this->calculateCost($capability, $estimatedTokens);

        return [
            'capability' => $capability,
            'base_cost' => $pricing['base_cost'],
            'per_token' => $pricing['per_token'],
            'estimated_tokens' => $estimatedTokens,
            'estimated_cost' => $estimatedCost,
        ];
    }
}
