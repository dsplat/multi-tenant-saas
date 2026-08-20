<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Course\Services\CourseService;

class CreateCourseHandler implements ToolHandlerContract
{
    public function __construct(private readonly CourseService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->create($tenantId, [
            'title'                    => $arguments['title'],
            'cover'                    => $arguments['cover'] ?? null,
            'description'              => $arguments['description'] ?? null,
            'price'                    => $arguments['price'] ?? 0,
            'points_price'             => $arguments['points_price'] ?? 0,
            'sale_mode'                => $arguments['sale_mode'] ?? 'cash',
            'completion_reward_points' => $arguments['completion_reward_points'] ?? 0,
        ]);
    }
}
