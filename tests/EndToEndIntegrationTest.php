<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\Memory\EntityMemory;
use MultiTenantSaas\Models\Message;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\Workflow;
use MultiTenantSaas\Models\WorkflowExecution;
use MultiTenantSaas\Models\WorkflowNode;
use MultiTenantSaas\Services\Capability\CapabilityBillingService;
use MultiTenantSaas\Services\Conversation\ConversationService;
use MultiTenantSaas\Services\Conversation\MessageService;
use MultiTenantSaas\Services\Memory\MemoryService;
use MultiTenantSaas\Services\Workflow\WorkflowEngine;
use MultiTenantSaas\Services\Workflow\WorkflowService;
use MultiTenantSaas\Tests\Stubs\FakeToolRegistry;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\ChannelModule;
use MultiTenantSaas\Tests\Schema\WorkflowModule;
use MultiTenantSaas\Tests\Schema\MemoryModule;

class EndToEndIntegrationTest extends TestCase
{
    protected array $uses = [AgentModule::class, BillingModule::class, ChannelModule::class, MemoryModule::class, WorkflowModule::class];

    private Tenant $tenant;
    private User $user;
    private int $tenantId = 1001;
    private int $userId = 2001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'E2E Tenant',
            'slug' => 'e2e-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            $this->user = User::create([
                'user_id' => $this->userId,
                'name' => 'E2E User',
                'email' => 'e2e@example.com',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId((string) $this->tenantId);
    }

    // --- Conversation + Messaging Flow ---

    public function test_conversation_full_lifecycle(): void
    {
        $conversationService = $this->app->make(ConversationService::class);
        $messageService = $this->app->make(MessageService::class);

        // Create conversation with participants
        $conversation = $conversationService->createConversation(
            $this->tenantId,
            'support',
            [$this->userId],
        );

        $this->assertNotNull($conversation->conversation_id);
        $this->assertSame('active', $conversation->status);

        // Send messages
        $msg1 = $messageService->sendMessage(
            $this->tenantId,
            (string) $conversation->conversation_id,
            $this->userId,
            'Hello, I need help',
        );

        $msg2 = $messageService->sendMessage(
            $this->tenantId,
            (string) $conversation->conversation_id,
            $this->userId,
            'It is urgent',
        );

        // Verify message count updated
        $conversation->refresh();
        $this->assertSame(2, $conversation->message_count);

        // Search messages
        $results = $messageService->searchMessages($this->tenantId, 'urgent');
        $this->assertCount(1, $results);

        // Get conversation
        $found = $conversationService->getConversation($this->tenantId, (string) $conversation->conversation_id);
        $this->assertSame($conversation->conversation_id, $found->conversation_id);

        // Delete (archive) conversation
        $conversationService->deleteConversation($this->tenantId, (string) $conversation->conversation_id);
        $conversation->refresh();
        $this->assertSame('archived', $conversation->status);
    }

    // --- Workflow Execution Flow ---

    public function test_workflow_execution_flow(): void
    {
        $toolRegistry = new FakeToolRegistry();
        $toolRegistry->register('process_order', 'OrderHandler', []);

        $tenantContext = $this->app->make(\MultiTenantSaas\Contracts\TenantContextContract::class);
        $engine = new WorkflowEngine($tenantContext, $toolRegistry);
        $service = new WorkflowService($tenantContext, $engine);

        // Create workflow
        $workflow = $service->create([
            'name' => 'Order Processing',
            'type' => 'sequential',
            'status' => 'active',
        ]);

        // Create nodes
        WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'Start',
            'type' => 'start',
            'order' => 0,
        ]);

        $actionNode = WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'Process Order',
            'type' => 'action',
            'config' => [
                'tool' => 'process_order',
                'arguments' => ['order_id' => '$.order_id'],
                'output' => 'result',
            ],
            'order' => 1,
        ]);

        WorkflowNode::where('node_id', $actionNode->node_id - 1)
            ->update(['next_node_id' => $actionNode->node_id]);

        WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'End',
            'type' => 'end',
            'next_node_id' => null,
            'order' => 2,
        ]);

        WorkflowNode::where('node_id', $actionNode->node_id)
            ->update(['next_node_id' => $actionNode->node_id + 1]);

        // Execute workflow
        $execution = $service->startExecution((string) $workflow->workflow_id, ['order_id' => 'ORD-001']);

        $this->assertSame('completed', $execution->status);
        $this->assertNotNull($execution->completed_at);
        $this->assertSame('ORD-001', $execution->context['order_id']);
    }

    // --- Memory Service Flow ---

    public function test_memory_service_flow(): void
    {
        $memoryService = new MemoryService(0.95, 5);

        // Write memories
        $memoryService->write('agent', 100, 'user_preference', ['theme' => 'dark', 'lang' => 'zh']);
        $memoryService->write('agent', 100, 'conversation_count', 42);
        $memoryService->write('agent', 100, 'last_topic', 'billing');

        // Read memory
        $preference = $memoryService->read('agent', 100, 'user_preference');
        $this->assertSame(['theme' => 'dark', 'lang' => 'zh'], $preference);

        // Update memory
        $memoryService->write('agent', 100, 'conversation_count', 43);
        $this->assertSame(43, $memoryService->read('agent', 100, 'conversation_count'));

        // Tenant memory
        $memoryService->writeTenantMemory($this->tenantId, 'config', 'max_agents', 10);
        $this->assertSame(10, $memoryService->readTenantMemory($this->tenantId, 'config', 'max_agents'));

        // Decay
        $memoryService->decay(0.1);

        // Memories with weight > 0.1 should still exist
        $this->assertNotNull($memoryService->read('agent', 100, 'user_preference'));
    }

    // --- Capability Billing Flow ---

    public function test_capability_billing_flow(): void
    {
        $billingService = new CapabilityBillingService();

        // Create credit account
        $account = CreditAccount::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'account_type' => 'personal',
            'balance' => 10000,
            'gift_balance' => 0,
            'recharge_balance' => 10000,
            'total_recharged' => 10000,
            'total_consumed' => 0,
        ]);

        // Check pricing
        $pricing = $billingService->getPricing('text_generation');
        $this->assertSame(10, $pricing['base_cost']);

        // Estimate cost
        $estimate = $billingService->estimateCost('text_generation', 100);
        $this->assertSame(110, $estimate['estimated_cost']);

        // Check affordability
        $this->assertTrue($billingService->canAfford($account, 'text_generation', 100));

        // Charge
        $result = $billingService->charge($account, new \MultiTenantSaas\Models\Capability\CapabilityResult(
            capability: 'text_generation',
            output: 'Generated text',
            confidence: 1.0,
            tokenUsage: 100,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(110, $result['cost']);

        // Verify balance updated
        $account->refresh();
        $this->assertSame(9890, $account->balance);
    }

    // --- Cache Tool Flow ---

    public function test_cache_tool_flow(): void
    {
        $tool = new \MultiTenantSaas\Services\Agent\Tools\CacheTool();

        // Set
        $setResult = $tool->execute(['action' => 'set', 'key' => 'e2e_key', 'value' => 'e2e_value']);
        $this->assertTrue($setResult['success']);

        // Get
        $getResult = $tool->execute(['action' => 'get', 'key' => 'e2e_key']);
        $this->assertSame('e2e_value', $getResult['value']);

        // Has
        $hasResult = $tool->execute(['action' => 'has', 'key' => 'e2e_key']);
        $this->assertTrue($hasResult['exists']);

        // Delete
        $delResult = $tool->execute(['action' => 'delete', 'key' => 'e2e_key']);
        $this->assertTrue($delResult['success']);

        // Verify deleted
        $hasResult2 = $tool->execute(['action' => 'has', 'key' => 'e2e_key']);
        $this->assertFalse($hasResult2['exists']);
    }

    // --- Encryption Tool Flow ---

    public function test_encryption_tool_flow(): void
    {
        $tool = new \MultiTenantSaas\Services\Agent\Tools\EncryptionTool();

        $original = 'Sensitive data: API_KEY=sk-12345';

        // Encrypt
        $encResult = $tool->execute(['action' => 'encrypt', 'data' => $original]);
        $this->assertNotEmpty($encResult['result']);
        $this->assertNotSame($original, $encResult['result']);

        // Decrypt
        $decResult = $tool->execute(['action' => 'decrypt', 'data' => $encResult['result']]);
        $this->assertSame($original, $decResult['result']);

        // Hash
        $hashResult = $tool->execute(['action' => 'hash', 'data' => $original]);
        $this->assertNotEmpty($hashResult['result']);
        $this->assertNotSame($original, $hashResult['result']);
    }

    // --- Validation Tool Flow ---

    public function test_validation_tool_flow(): void
    {
        $tool = new \MultiTenantSaas\Services\Agent\Tools\ValidationTool();

        // Valid data
        $validResult = $tool->execute([
            'data' => ['name' => 'John', 'email' => 'john@example.com', 'age' => 25],
            'rules' => [
                'name' => 'required|string|max:255',
                'email' => 'required|email',
                'age' => 'required|integer|min:18',
            ],
        ]);
        $this->assertTrue($validResult['valid']);

        // Invalid data
        $invalidResult = $tool->execute([
            'data' => ['name' => '', 'email' => 'not-an-email', 'age' => 10],
            'rules' => [
                'name' => 'required|string|max:255',
                'email' => 'required|email',
                'age' => 'required|integer|min:18',
            ],
        ]);
        $this->assertFalse($invalidResult['valid']);
        $this->assertNotEmpty($invalidResult['errors']);
    }

    // --- JSON Tool Flow ---

    public function test_json_tool_flow(): void
    {
        $tool = new \MultiTenantSaas\Services\Agent\Tools\JsonTool();

        $data = ['users' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]];

        // Encode
        $encodeResult = $tool->execute(['action' => 'encode', 'data' => $data]);
        $this->assertIsString($encodeResult['result']);

        // Decode
        $decodeResult = $tool->execute(['action' => 'decode', 'data' => $encodeResult['result']]);
        $this->assertSame($data, $decodeResult['result']);

        // Validate
        $validResult = $tool->execute(['action' => 'validate', 'data' => $encodeResult['result']]);
        $this->assertTrue($validResult['valid']);
    }

    // --- DateTime Tool Flow ---

    public function test_datetime_tool_flow(): void
    {
        $tool = new \MultiTenantSaas\Services\Agent\Tools\DateTimeTool();

        // Now
        $nowResult = $tool->execute(['action' => 'now']);
        $this->assertNotEmpty($nowResult['result']);

        // Format
        $formatResult = $tool->execute(['action' => 'format', 'date' => '2026-07-02 10:30:00', 'format' => 'Y-m-d']);
        $this->assertSame('2026-07-02', $formatResult['result']);

        // Diff
        $diffResult = $tool->execute(['action' => 'diff', 'date' => '2026-07-01']);
        $this->assertNotEmpty($diffResult['result']);
    }

    // --- Multi-Module Integration ---

    public function test_conversation_with_memory(): void
    {
        $conversationService = $this->app->make(ConversationService::class);
        $messageService = $this->app->make(MessageService::class);
        $memoryService = new MemoryService();

        // Create conversation
        $conversation = $conversationService->createConversation(
            $this->tenantId,
            'support',
            [$this->userId],
        );

        // Send message
        $messageService->sendMessage(
            $this->tenantId,
            (string) $conversation->conversation_id,
            $this->userId,
            'I prefer dark theme',
        );

        // Store preference in memory
        $memoryService->write('user', $this->userId, 'theme', 'dark');

        // Verify memory
        $this->assertSame('dark', $memoryService->read('user', $this->userId, 'theme'));

        // Verify conversation exists
        $recent = $conversationService->getRecentConversations($this->tenantId, $this->userId);
        $this->assertCount(1, $recent);
    }

    public function test_workflow_with_billing(): void
    {
        $billingService = new CapabilityBillingService();
        $tenantContext = $this->app->make(\MultiTenantSaas\Contracts\TenantContextContract::class);
        $toolRegistry = new FakeToolRegistry();
        $engine = new WorkflowEngine($tenantContext, $toolRegistry);

        // Create account
        $account = CreditAccount::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'account_type' => 'personal',
            'balance' => 10000,
            'gift_balance' => 0,
            'recharge_balance' => 10000,
            'total_recharged' => 10000,
            'total_consumed' => 0,
        ]);

        // Create workflow
        $workflow = Workflow::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Billable Workflow',
            'type' => 'sequential',
            'status' => 'active',
            'enabled' => true,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'Start',
            'type' => 'start',
            'order' => 0,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'End',
            'type' => 'end',
            'order' => 1,
        ]);

        // Execute workflow
        $execution = $engine->execute($workflow, ['input' => 'test']);
        $this->assertSame('completed', $execution->status);

        // Bill for capability usage
        $result = $billingService->charge($account, new \MultiTenantSaas\Models\Capability\CapabilityResult(
            capability: 'conversation',
            output: 'Response',
            confidence: 1.0,
            tokenUsage: 50,
        ));

        $this->assertTrue($result['success']);
        $account->refresh();
        $this->assertLessThan(10000, $account->balance);
    }
}
