<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Workflow\Services\Nodes\ConfirmNode;
use MultiTenantSaas\Tests\Schema\WorkflowModule;

class ConfirmNodeTest extends TestCase
{
    protected array $uses = [WorkflowModule::class];

    private ConfirmNode $confirmNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmNode = new ConfirmNode;
    }

    public function test_execute_with_no_confirmation_sets_pending(): void
    {
        $node = [
            'name' => 'Approval',
            'type' => 'confirm',
            'config' => [
                'confirm_field' => 'approved',
                'default_action' => 'reject',
            ],
        ];

        $result = $this->confirmNode->execute($node, []);

        $this->assertTrue($result['_confirm_pending']);
        $this->assertSame('approved', $result['_confirm_field']);
        $this->assertSame('reject', $result['_confirm_default']);
    }

    public function test_execute_with_confirmation_sets_result(): void
    {
        $node = [
            'name' => 'Approval',
            'type' => 'confirm',
            'config' => ['confirm_field' => 'approved'],
        ];

        $result = $this->confirmNode->execute($node, ['approved' => true]);

        $this->assertTrue($result['_confirm_result']);
        $this->assertArrayNotHasKey('_confirm_pending', $result);
    }

    public function test_execute_with_rejection_sets_result(): void
    {
        $node = [
            'name' => 'Approval',
            'type' => 'confirm',
            'config' => ['confirm_field' => 'approved'],
        ];

        $result = $this->confirmNode->execute($node, ['approved' => false]);

        $this->assertFalse($result['_confirm_result']);
    }

    public function test_execute_with_default_confirm_field(): void
    {
        $node = [
            'name' => 'Approval',
            'type' => 'confirm',
            'config' => [],
        ];

        $result = $this->confirmNode->execute($node, ['_confirmed' => true]);

        $this->assertTrue($result['_confirm_result']);
    }

    public function test_confirm_approve(): void
    {
        $context = [
            '_confirm_pending' => true,
            '_confirm_field' => 'manager_approval',
            '_confirm_default' => 'reject',
        ];

        $result = $this->confirmNode->confirm($context, true);

        $this->assertTrue($result['manager_approval']);
        $this->assertTrue($result['_confirm_result']);
        $this->assertArrayNotHasKey('_confirm_pending', $result);
        $this->assertArrayNotHasKey('_confirm_field', $result);
        $this->assertArrayNotHasKey('_confirm_default', $result);
    }

    public function test_confirm_reject(): void
    {
        $context = [
            '_confirm_pending' => true,
            '_confirm_field' => 'manager_approval',
        ];

        $result = $this->confirmNode->confirm($context, false);

        $this->assertFalse($result['manager_approval']);
        $this->assertFalse($result['_confirm_result']);
    }

    public function test_confirm_preserves_other_context(): void
    {
        $context = [
            '_confirm_pending' => true,
            '_confirm_field' => 'approved',
            'existing_key' => 'existing_value',
        ];

        $result = $this->confirmNode->confirm($context, true);

        $this->assertSame('existing_value', $result['existing_key']);
    }
}
