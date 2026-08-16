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
     * AgentRuntime（非流式兜底）与 AiStreaming Resolve（Node 流式链路）共用。
     */
    public function effectiveSystemPrompt(): string
    {
        $dbPrompt = (string) ($this->system_prompt ?? '');

        $metadata = (array) ($this->metadata ?? []);
        if (! empty($metadata['prompt_customized'])) {
            return $this->withRuntimeContext($dbPrompt);
        }

        $template = AgentTemplateRegistry::findByKey($this->role ?? '');
        $templatePrompt = (string) ($template['system_prompt'] ?? '');

        return $this->withRuntimeContext($templatePrompt !== '' ? $templatePrompt : $dbPrompt);
    }

    /**
     * 运行时上下文附录：注入当前日期时间
     *
     * LLM 无法自行感知当前时间，不注入时提议的活动日期会幻觉出
     * 过期年份（生产已出现 2024 年方案）。每次 resolve/运行实时拼接。
     */
    private function withRuntimeContext(string $prompt): string
    {
        if ($prompt === '') {
            return $prompt;
        }

        $now = now()->tz('Asia/Shanghai');

        return $prompt . "\n\n[运行时信息] 当前日期：{$now->format('Y-m-d')}（{$now->translatedFormat('l')}），当前时间：{$now->format('H:i')}（北京时间）。涉及活动、排期、截止时间等内容时，必须以当前日期为基准，禁止使用已过去的日期。";
    }

    /**
     * 有效工具调用步数上限
     *
     * 系统小秘书强制走平台级 config('ai.secretary.max_tool_calls')（与
     * resolveModelConfig 口径一致，存量租户 DB 快照的旧值 5 不再生效，
     * thread_review→kb_search→draft 多步推理链不触顶）；其余 Agent 仍用
     * 租户维护的 model_config。
     *
     * AgentRuntime（非流式）与 AiStreaming Resolve（Node 流式链路）共用。
     */
    public function effectiveMaxToolCalls(int $default = 5): int
    {
        if (($this->role ?? '') === 'system_secretary') {
            return (int) config('ai.secretary.max_tool_calls', 10);
        }

        return (int) ($this->model_config['max_tool_calls'] ?? $default);
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
