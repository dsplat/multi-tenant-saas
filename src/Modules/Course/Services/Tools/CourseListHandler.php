<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Course\Services\CourseService;

class CourseListHandler implements ToolHandlerContract
{
    public function __construct(private readonly CourseService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        if (! empty($arguments['course_id'])) {
            return $this->service->find($tenantId, (int) $arguments['course_id']);
        }

        return $this->service->getList($tenantId, [
            'status'   => $arguments['status'] ?? null,
            'search'   => $arguments['search'] ?? null,
            'page'     => $arguments['page'] ?? 1,
            'per_page' => $arguments['per_page'] ?? 20,
        ]);
    }
}
