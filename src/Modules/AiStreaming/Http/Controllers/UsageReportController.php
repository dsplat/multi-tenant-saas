<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;

class UsageReportController extends AiStreamingController
{
    public function __construct(
        TenantContextContract $tenantContext,
        AgentServiceContract $agentService,
        private AiUsageService $usageService,
    ) {
        parent::__construct($tenantContext, $agentService);
    }

    /**
     * @OA\Post(
     *     path="/v1/ai-streaming/usage/report",
     *     summary="上报流式会话用量（Node 引擎回调）",
     *     description="流结束（onFinish）时 Node 引擎回调本端点，由 PHP 完成 token 用量结算（配额扣减 + 用量记录）。",
     *     tags={"AI 流式网关"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"model", "input_tokens", "output_tokens"},
     *
     *         @OA\Property(property="agent_id", type="integer", nullable=true, example=1),
     *         @OA\Property(property="model", type="string", example="qwen-plus"),
     *         @OA\Property(property="input_tokens", type="integer", example=350),
     *         @OA\Property(property="output_tokens", type="integer", example=1200),
     *         @OA\Property(property="metadata", type="object", nullable=true, description="附加信息（tool_calls 次数、finish_reason 等）")
     *     )),
     *
     *     @OA\Response(response=200, description="结算成功"),
     *     @OA\Response(response=422, description="参数校验失败")
     * )
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'agent_id' => ['sometimes', 'integer'],
            'model' => ['required', 'string', 'max:100'],
            'input_tokens' => ['required', 'integer', 'min:0'],
            'output_tokens' => ['required', 'integer', 'min:0'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $tenantId = $this->resolveTenantId();

        $metadata = (array) ($data['metadata'] ?? []);
        $metadata['source'] = 'ai-streaming';

        // agent_id 提供时校验归属并写入结算元数据
        if (isset($data['agent_id'])) {
            $agent = $this->ensureAgentForTenant((int) $data['agent_id'], $tenantId);
            $metadata['agent_id'] = (int) $agent->agent_id;
        }

        $quota = $this->usageService->recordTextUsage(
            $data['model'],
            (int) $data['input_tokens'],
            (int) $data['output_tokens'],
            $metadata
        );

        return response()->json([
            'success' => true,
            'data' => [
                'recorded' => true,
                'tokens_used' => (int) $data['input_tokens'] + (int) $data['output_tokens'],
                'quota_used' => $quota->tokens_used ?? null,
            ],
        ]);
    }
}
