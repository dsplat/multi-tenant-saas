<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;

/**
 * Agent 模型（数字员工）
 */
class Agent extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'agent_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'role',
        'avatar',
        'system_prompt',
        'description',
        'tools',
        'kb_ids',
        'feature_keys',
        'model_config',
        'enabled',
        'is_builtin',
        'metadata',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'kb_ids' => 'array',
            'feature_keys' => 'array',
            'model_config' => 'array',
            'metadata' => 'array',
            'enabled' => 'boolean',
            'is_builtin' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class, 'agent_id', 'agent_id');
    }

    /**
     * 有效工具列表（DB 快照 ∪ 模板最新工具，去重保序）
     *
     * Agent 创建时从模板 clone tools 到 DB，之后模板新增工具不会自动同步。
     * 此方法在运行时将模板工具合并进来（只增不减），模板查找走
     * AgentTemplateRegistry（框架模板 + 下游 extra_template_classes）。
     *
     * AgentRuntime（非流式）与 AiStreaming Resolve/ToolExecute（Node 流式链路）
     * 均以此为唯一事实源，避免工具可见性不一致。
     *
     * @return list<string>
     */
    public function effectiveTools(): array
    {
        $dbTools = $this->tools ?? [];

        $template = AgentTemplateRegistry::findByKey($this->role ?? '');
        $templateTools = $template['tools'] ?? [];

        return array_values(array_unique(array_merge($dbTools, $templateTools)));
    }

    /**
     * 有效系统提示词（模板优先，除非用户自定义过）
     *
     * Agent 创建时从模板 clone system_prompt 到 DB，之后模板迭代（如新增
     * navigate 指令）不会自动同步。此方法运行时优先取模板最新 prompt；
     * 仅当用户显式改过 prompt（metadata.prompt_customized）时尊重 DB 快照。
     *
     * AgentRuntime（非流式兑底）与 AiStreaming Resolve（Node 流式链路）共用。
     */
    public function effectiveSystemPrompt(): string
    {
        $dbPrompt = (string) ($this->system_prompt ?? '');

        $metadata = (array) ($this->metadata ?? []);
        if (! empty($metadata['prompt_customized'])) {
            return $dbPrompt;
        }

        $template = AgentTemplateRegistry::findByKey($this->role ?? '');
        $templatePrompt = (string) ($template['system_prompt'] ?? '');

        return $templatePrompt !== '' ? $templatePrompt : $dbPrompt;
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(
            AgentConversationMessage::class,
            AgentConversation::class,
            'agent_id',
            'conversation_id',
            'agent_id',
            'conversation_id'
        );
    }

    public function workflows(): BelongsToMany
    {
        return $this->belongsToMany(
            Workflow::class,
            'agent_workflows',
            'agent_id',
            'workflow_id',
            'agent_id',
            'workflow_id'
        )->using(AgentWorkflow::class)
            ->withPivot(['is_primary', 'sort_order'])
            ->orderByPivot('sort_order');
    }
}
