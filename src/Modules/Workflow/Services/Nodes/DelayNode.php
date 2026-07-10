<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services\Nodes;

use Carbon\Carbon;

class DelayNode
{
    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(array $node, array $context): array
    {
        $config = $node['config'] ?? [];
        $duration = (int) ($config['duration'] ?? 0);
        $unit = $config['unit'] ?? 'seconds';
        $untilField = $config['until_field'] ?? null;

        if ($untilField !== null && isset($context[$untilField])) {
            $targetTime = Carbon::parse($context[$untilField]);
        } else {
            $targetTime = $this->calculateTargetTime($duration, $unit);
        }

        $now = Carbon::now();

        if ($now->lt($targetTime)) {
            $context['_delay_pending'] = true;
            $context['_delay_until'] = $targetTime->toDateTimeString();
            $context['_delay_seconds'] = $now->diffInSeconds($targetTime);
        } else {
            $context['_delay_pending'] = false;
            unset($context['_delay_until'], $context['_delay_seconds']);
        }

        return $context;
    }

    public function calculateTargetTime(int $duration, string $unit): Carbon
    {
        return match ($unit) {
            'minutes' => Carbon::now()->addMinutes($duration),
            'hours' => Carbon::now()->addHours($duration),
            'days' => Carbon::now()->addDays($duration),
            default => Carbon::now()->addSeconds($duration),
        };
    }

    public function isDelayExpired(array $context): bool
    {
        if (! isset($context['_delay_until'])) {
            return true;
        }

        return Carbon::now()->gte(Carbon::parse($context['_delay_until']));
    }
}
