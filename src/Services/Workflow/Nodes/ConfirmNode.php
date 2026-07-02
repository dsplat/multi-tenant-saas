<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow\Nodes;

class ConfirmNode
{
    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(array $node, array $context): array
    {
        $config = $node['config'] ?? [];
        $confirmField = $config['confirm_field'] ?? '_confirmed';
        $defaultAction = $config['default_action'] ?? 'reject';

        $confirmed = $context[$confirmField] ?? null;

        if ($confirmed === null) {
            $context['_confirm_pending'] = true;
            $context['_confirm_field'] = $confirmField;
            $context['_confirm_default'] = $defaultAction;
        } else {
            $context['_confirm_result'] = (bool) $confirmed;
            unset($context['_confirm_pending']);
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function confirm(array $context, bool $approved): array
    {
        $confirmField = $context['_confirm_field'] ?? '_confirmed';
        $context[$confirmField] = $approved;
        $context['_confirm_result'] = $approved;
        unset($context['_confirm_pending'], $context['_confirm_field'], $context['_confirm_default']);

        return $context;
    }
}
