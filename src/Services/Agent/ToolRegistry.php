<?php

namespace MultiTenantSaas\Services\Agent;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Models\AgentTool;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Services\Agent\Dto\Tool;

/**
 * 工具注册表实现
 *
 * 双源合并：agent_tools 表（持久化）+ 运行时注册（内存）。
 * 运行时注册的工具优先级高于数据库中的同名工具。
 *
 * execute 通过容器实例化 handler_class，调用 __invoke 并显式传递 tenantId。
 */
class ToolRegistry implements ToolRegistryContract
{
    /**
     * 运行时注册的工具 [slug => Tool]
     */
    private array $runtimeTools = [];

    public function __construct(
        private Container $container
    ) {}

    /**
     * 注册工具到运行时注册表
     *
     * @param  string  $slug          工具唯一标识
     * @param  string  $handlerClass  工具处理器类名（FQCN，须实现 ToolHandlerContract）
     * @param  array  $schema         JSON Schema 格式的参数定义
     */
    public function register(string $slug, string $handlerClass, array $schema): void
    {
        $this->runtimeTools[$slug] = new Tool(
            slug: $slug,
            name: $slug,
            description: '',
            parametersSchema: $schema,
            handlerClass: $handlerClass,
        );
    }

    /**
     * 获取所有工具（运行时 + 数据库，运行时优先）
     */
    public function all(): Collection
    {
        $dbTools = $this->loadDbTools();

        $merged = $dbTools->keyBy('slug')->toArray();

        // 运行时工具覆盖同名数据库工具
        foreach ($this->runtimeTools as $slug => $tool) {
            $merged[$slug] = $tool;
        }

        return collect(array_values($merged));
    }

    /**
     * 获取指定工具（运行时优先，数据库兜底）
     */
    public function get(string $slug): ?Tool
    {
        // 运行时优先
        if (isset($this->runtimeTools[$slug])) {
            return $this->runtimeTools[$slug];
        }

        // 数据库查找
        $dbTool = $this->findDbTool($slug);

        return $dbTool;
    }

    /**
     * 获取 Function Calling 格式的工具定义
     *
     * 跳过不存在的 slug，仅返回已注册工具的定义。
     *
     * @param  array  $slugs  工具标识列表
     * @return array Function Calling 格式的工具定义数组
     */
    public function getToolDefinitions(array $slugs): array
    {
        $definitions = [];

        foreach ($slugs as $slug) {
            $tool = $this->get($slug);

            if ($tool !== null) {
                $definitions[] = $tool->toFunctionCalling();
            }
        }

        return $definitions;
    }

    /**
     * 执行工具
     *
     * 通过容器实例化 handler_class，校验 ToolHandlerContract 后调用 __invoke。
     * tenantId 显式传入，不依赖 TenantContext。
     *
     * 运行时处理器异常被捕获并封装为结构化错误数组（而非抛出），
     * 由调用方决定如何处理。仅基础设施错误（工具未注册/类不存在）抛出异常。
     *
     * @param  string  $slug       工具标识
     * @param  array  $arguments   工具参数
     * @param  int  $tenantId      租户 ID
     * @return mixed 工具执行结果；失败时返回 ['error' => true, 'message' => string, 'slug' => string]
     *
     * @throws \RuntimeException 工具未注册或 handler 类不存在时抛出
     */
    public function execute(string $slug, array $arguments, int $tenantId): mixed
    {
        $tool = $this->get($slug);

        if ($tool === null) {
            throw new \RuntimeException("工具 [{$slug}] 未注册");
        }

        $handlerClass = $tool->handlerClass;

        if (empty($handlerClass) || ! class_exists($handlerClass)) {
            throw new \RuntimeException("工具 [{$slug}] 的处理器类 [{$handlerClass}] 不存在");
        }

        $handler = $this->container->make($handlerClass);

        if (! $handler instanceof ToolHandlerContract) {
            throw new \RuntimeException(
                "工具 [{$slug}] 的处理器类 [{$handlerClass}] 必须实现 ToolHandlerContract 接口"
            );
        }

        try {
            return $handler($arguments, $tenantId);
        } catch (\Throwable $e) {
            // 运行时处理器异常封装为结构化错误，不中断 ReAct 循环
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'slug' => $slug,
            ];
        }
    }

    /**
     * 工具是否可用
     *
     * 运行时注册的工具始终可用；数据库工具需在指定租户下启用且存在。
     *
     * @param  string  $slug      工具标识
     * @param  int  $tenantId     租户 ID
     * @return bool
     */
    public function isAvailable(string $slug, int $tenantId): bool
    {
        // 运行时注册的工具始终可用
        if (isset($this->runtimeTools[$slug])) {
            return true;
        }

        // 数据库查询：检查工具是否存在且启用（租户私有或全局）
        $dbTool = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('enabled', true)
            ->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                    ->orWhere('tenant_id', 0);
            })
            ->first();

        return $dbTool !== null;
    }

    /**
     * 从数据库加载当前租户可用的所有工具（含全局工具 tenant_id=0）
     *
     * @return Collection<Tool>
     */
    private function loadDbTools(): Collection
    {
        $models = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('enabled', true)
            ->where(function ($query) {
                // 包含全局工具（tenant_id=0）和当前租户上下文中的工具
                $tenantId = \MultiTenantSaas\Context\TenantContext::getId();
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId)
                        ->orWhere('tenant_id', 0);
                } else {
                    $query->where('tenant_id', 0);
                }
            })
            ->get();

        return $models->map(function (AgentTool $model) {
            return Tool::fromArray([
                'slug' => $model->slug,
                'name' => $model->name,
                'description' => $model->description,
                'parameters_schema' => $model->parameters_schema,
                'handler_class' => $model->handler_class,
            ]);
        });
    }

    /**
     * 从数据库查找指定 slug 的工具（租户私有 + 全局）
     */
    private function findDbTool(string $slug): ?Tool
    {
        $model = AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('slug', $slug)
            ->where('enabled', true)
            ->where(function ($query) {
                $tenantId = \MultiTenantSaas\Context\TenantContext::getId();
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId)
                        ->orWhere('tenant_id', 0);
                } else {
                    $query->where('tenant_id', 0);
                }
            })
            ->first();

        if ($model === null) {
            return null;
        }

        return Tool::fromArray([
            'slug' => $model->slug,
            'name' => $model->name,
            'description' => $model->description,
            'parameters_schema' => $model->parameters_schema,
            'handler_class' => $model->handler_class,
        ]);
    }
}
