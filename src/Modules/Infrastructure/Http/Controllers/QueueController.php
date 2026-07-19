<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Routing\Controller;

/**
 * 队列失败任务管理控制器
 *
 * 提供失败队列任务的查看、重试、删除功能，用于管理后台。
 */
class QueueController extends Controller
{
    public function __construct(
        protected FailedJobProviderInterface $failedJobProvider
    ) {}

    /**
     * 获取失败任务列表
     *
     * GET /api/v1/admin/queue/failed
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);

        $failedJobs = $this->failedJobProvider->all();

        $total = count($failedJobs);
        $jobs = array_slice($failedJobs, ($page - 1) * $perPage, $perPage);

        $data = array_map(function ($job) {
            $payload = json_decode($job->payload, true);

            return [
                'id' => $job->id,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'name' => $payload['displayName'] ?? ($payload['job'] ?? 'Unknown'),
                'exception' => $this->truncateException($job->exception),
                'failed_at' => $job->failed_at,
                'attempts' => $payload['attempts'] ?? 0,
            ];
        }, $jobs);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $data,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * 重试指定失败任务
     *
     * POST /api/v1/admin/queue/failed/{id}/retry
     */
    public function retry(string $id): JsonResponse
    {
        $this->failedJobProvider->retry($id);

        return response()->json([
            'success' => true,
            'message' => '任务已重新加入队列',
        ]);
    }

    /**
     * 删除指定失败任务
     *
     * DELETE /api/v1/admin/queue/failed/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->failedJobProvider->forget($id);

        return response()->json([
            'success' => true,
            'message' => '失败任务已删除',
        ]);
    }

    /**
     * 重试所有失败任务
     *
     * POST /api/v1/admin/queue/failed/retry-all
     */
    public function retryAll(): JsonResponse
    {
        $failedJobs = $this->failedJobProvider->all();
        $count = 0;

        foreach ($failedJobs as $job) {
            $this->failedJobProvider->retry($job->id);
            $count++;
        }

        return response()->json([
            'success' => true,
            'message' => "已重试 {$count} 个失败任务",
            'data' => ['count' => $count],
        ]);
    }

    /**
     * 清空所有失败任务
     *
     * DELETE /api/v1/admin/queue/failed
     */
    public function flush(): JsonResponse
    {
        $this->failedJobProvider->flush();

        return response()->json([
            'success' => true,
            'message' => '所有失败任务已清空',
        ]);
    }

    /**
     * 截断异常信息
     */
    protected function truncateException(?string $exception, int $length = 500): ?string
    {
        if ($exception === null) {
            return null;
        }

        if (strlen($exception) <= $length) {
            return $exception;
        }

        return substr($exception, 0, $length) . '...';
    }
}
