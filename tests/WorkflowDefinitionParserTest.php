<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\DTOs\WorkflowDefinition;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Modules\Workflow\Models\Workflow;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowDefinitionParser;
use MultiTenantSaas\Tests\Schema\WorkflowModule;

class WorkflowDefinitionParserTest extends TestCase
{
    protected array $uses = [WorkflowModule::class];

    private WorkflowDefinitionParser $parser;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId((string) $tenant->tenant_id);
        $this->tenantId = $tenant->tenant_id;
        $this->parser = new WorkflowDefinitionParser();
    }

    private function getValidDefinition(): array
    {
        return [
            'name' => 'Test Workflow',
            'type' => 'sequential',
            'nodes' => [
                ['id' => 'start', 'name' => 'Start', 'type' => 'start', 'order' => 0],
                ['id' => 'end', 'name' => 'End', 'type' => 'end', 'order' => 1],
            ],
        ];
    }

    public function test_validate_valid_definition(): void
    {
        $errors = $this->parser->validate($this->getValidDefinition());

        $this->assertEmpty($errors);
    }

    public function test_validate_missing_name(): void
    {
        $def = $this->getValidDefinition();
        unset($def['name']);

        $errors = $this->parser->validate($def);

        $this->assertContains('Missing required field: name', $errors);
    }

    public function test_validate_missing_nodes(): void
    {
        $def = $this->getValidDefinition();
        unset($def['nodes']);

        $errors = $this->parser->validate($def);

        $this->assertContains('Missing required field: nodes', $errors);
    }

    public function test_validate_name_not_string(): void
    {
        $def = $this->getValidDefinition();
        $def['name'] = 123;

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "name" must be a string', $errors);
    }

    public function test_validate_name_empty(): void
    {
        $def = $this->getValidDefinition();
        $def['name'] = '';

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "name" must not be empty', $errors);
    }

    public function test_validate_description_not_string(): void
    {
        $def = $this->getValidDefinition();
        $def['description'] = 123;

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "description" must be a string', $errors);
    }

    public function test_validate_type_not_string(): void
    {
        $def = $this->getValidDefinition();
        $def['type'] = 123;

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "type" must be a string', $errors);
    }

    public function test_validate_config_not_array(): void
    {
        $def = $this->getValidDefinition();
        $def['config'] = 'not_array';

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "config" must be an object', $errors);
    }

    public function test_validate_nodes_not_array(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'] = 'not_array';

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "nodes" must be an array', $errors);
    }

    public function test_validate_nodes_empty(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'] = [];

        $errors = $this->parser->validate($def);

        $this->assertContains('Field "nodes" must not be empty', $errors);
    }

    public function test_validate_node_missing_id(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0] = ['type' => 'start'];

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Missing required field: id', $errors);
    }

    public function test_validate_node_missing_type(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0] = ['id' => 'start'];

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Missing required field: type', $errors);
    }

    public function test_validate_node_invalid_type(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0]['type'] = 'invalid_type';

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Invalid node type: invalid_type', $errors);
    }

    public function test_validate_node_duplicate_id(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][] = ['id' => 'start', 'type' => 'action'];

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[2]: Duplicate node id: start', $errors);
    }

    public function test_validate_missing_start_node(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'] = [
            ['id' => 'action', 'name' => 'Action', 'type' => 'action', 'order' => 0],
            ['id' => 'end', 'name' => 'End', 'type' => 'end', 'order' => 1],
        ];

        $errors = $this->parser->validate($def);

        $this->assertContains('Workflow must have at least one "start" node', $errors);
    }

    public function test_validate_missing_end_node(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'] = [
            ['id' => 'start', 'name' => 'Start', 'type' => 'start', 'order' => 0],
            ['id' => 'action', 'name' => 'Action', 'type' => 'action', 'order' => 1],
        ];

        $errors = $this->parser->validate($def);

        $this->assertContains('Workflow must have at least one "end" node', $errors);
    }

    public function test_validate_node_id_not_string(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0]['id'] = 123;

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Field "id" must be a string', $errors);
    }

    public function test_validate_node_name_not_string(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0]['name'] = 123;

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Field "name" must be a string', $errors);
    }

    public function test_validate_node_type_not_string(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0]['type'] = 123;

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Field "type" must be a string', $errors);
    }

    public function test_validate_node_config_not_array(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0]['config'] = 'not_array';

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Field "config" must be an object', $errors);
    }

    public function test_validate_node_order_not_int(): void
    {
        $def = $this->getValidDefinition();
        $def['nodes'][0]['order'] = 'not_int';

        $errors = $this->parser->validate($def);

        $this->assertContains('nodes[0]: Field "order" must be an integer', $errors);
    }

    public function test_validate_edges_missing_from(): void
    {
        $def = $this->getValidDefinition();
        $def['edges'] = [['to' => 'end']];

        $errors = $this->parser->validate($def);

        $this->assertContains('edges[0]: Missing required field: from', $errors);
    }

    public function test_validate_edges_missing_to(): void
    {
        $def = $this->getValidDefinition();
        $def['edges'] = [['from' => 'start']];

        $errors = $this->parser->validate($def);

        $this->assertContains('edges[0]: Missing required field: to', $errors);
    }

    public function test_validate_edges_unknown_node(): void
    {
        $def = $this->getValidDefinition();
        $def['edges'] = [['from' => 'start', 'to' => 'unknown']];

        $errors = $this->parser->validate($def);

        $this->assertContains('edges[0]: Unknown node id: unknown', $errors);
    }

    public function test_get_json_schema(): void
    {
        $schema = $this->parser->getJsonSchema();

        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('required', $schema);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertSame('object', $schema['type']);
    }

    public function test_parse_valid_json(): void
    {
        $json = json_encode($this->getValidDefinition());

        $def = $this->parser->parse($json);

        $this->assertInstanceOf(WorkflowDefinition::class, $def);
        $this->assertSame('Test Workflow', $def->name);
        $this->assertSame('sequential', $def->type);
        $this->assertCount(2, $def->nodes);
    }

    public function test_parse_invalid_json_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->parser->parse('{invalid json}');
    }

    public function test_parse_non_object_json_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow definition must be a JSON object');

        $this->parser->parse('"just a string"');
    }

    public function test_parse_invalid_definition_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid workflow definition');

        $this->parser->parse('{}');
    }

    public function test_create_from_definition(): void
    {
        $def = new WorkflowDefinition(
            name: 'From Definition',
            description: 'Test description',
            type: 'sequential',
            config: ['key' => 'value'],
            nodes: [
                ['id' => 'start', 'name' => 'Start', 'type' => 'start', 'order' => 0],
                ['id' => 'action', 'name' => 'Action', 'type' => 'action', 'order' => 1, 'config' => ['tool' => 'test']],
                ['id' => 'end', 'name' => 'End', 'type' => 'end', 'order' => 2],
            ],
            edges: [
                ['from' => 'start', 'to' => 'action'],
                ['from' => 'action', 'to' => 'end'],
            ],
        );

        $workflow = $this->parser->createFromDefinition($this->tenantId, $def);

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('From Definition', $workflow->name);
        $this->assertSame('Test description', $workflow->description);
        $this->assertSame('sequential', $workflow->type);
        $this->assertSame(['key' => 'value'], $workflow->config);
        $this->assertSame('draft', $workflow->status);
        $this->assertSame($this->tenantId, $workflow->tenant_id);

        $this->assertCount(3, $workflow->nodes);
    }

    public function test_create_from_definition_without_edges_uses_order(): void
    {
        $def = new WorkflowDefinition(
            name: 'Sequential',
            type: 'sequential',
            nodes: [
                ['id' => 'start', 'name' => 'Start', 'type' => 'start', 'order' => 0],
                ['id' => 'action', 'name' => 'Action', 'type' => 'action', 'order' => 1],
                ['id' => 'end', 'name' => 'End', 'type' => 'end', 'order' => 2],
            ],
            edges: [],
        );

        $workflow = $this->parser->createFromDefinition($this->tenantId, $def);

        $nodes = $workflow->nodes()->orderBy('order')->get();
        $this->assertCount(3, $nodes);

        $startNode = $nodes->firstWhere('type', 'start');
        $actionNode = $nodes->firstWhere('type', 'action');
        $endNode = $nodes->firstWhere('type', 'end');

        $this->assertNotNull($startNode->next_node_id);
        $this->assertNotNull($actionNode->next_node_id);
        $this->assertNull($endNode->next_node_id);
    }

    public function test_to_json(): void
    {
        $workflow = Workflow::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Export WF',
            'description' => 'Export test',
            'type' => 'sequential',
            'status' => 'active',
            'config' => ['export' => true],
        ]);

        $startNode = \MultiTenantSaas\Modules\Workflow\Models\WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'Start',
            'type' => 'start',
            'order' => 0,
        ]);

        $endNode = \MultiTenantSaas\Modules\Workflow\Models\WorkflowNode::create([
            'workflow_id' => $workflow->workflow_id,
            'tenant_id' => $this->tenantId,
            'name' => 'End',
            'type' => 'end',
            'order' => 1,
        ]);

        $startNode->update(['next_node_id' => $endNode->node_id]);

        $json = $this->parser->toJson($workflow->fresh());

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Export WF', $decoded['name']);
        $this->assertSame('Export test', $decoded['description']);
        $this->assertCount(2, $decoded['nodes']);
        $this->assertCount(1, $decoded['edges']);
    }
}
