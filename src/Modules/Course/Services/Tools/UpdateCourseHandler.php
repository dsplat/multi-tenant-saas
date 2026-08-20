<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Course\Services\CourseService;

class UpdateCourseHandler implements ToolHandlerContract
{
    public function __construct(private readonly CourseService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $courseId = (int) $arguments['course_id'];

        if (isset($arguments['action'])) {
            return match ($arguments['action']) {
                'publish' => $this->service->publish($tenantId, $courseId),
                'offline' => $this->service->offline($tenantId, $courseId),
                default   => throw new \InvalidArgumentException("不支持的 action: {$arguments['action']}(仅 publish/offline)"),
            };
        }

        return $this->service->update($tenantId, $courseId, array_filter([
            'title'                    => $arguments['title'] ?? null,
            'cover'                    => $arguments['cover'] ?? null,
            'description'              => $arguments['description'] ?? null,
            'price'                    => $arguments['price'] ?? null,
            'points_price'             => $arguments['points_price'] ?? null,
            'sale_mode'                => $arguments['sale_mode'] ?? null,
            'completion_reward_points' => $arguments['completion_reward_points'] ?? null,
        ], fn ($v) => $v !== null));
    }
}
