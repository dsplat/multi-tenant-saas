<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Sms\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

class SmsCreateTemplateHandler implements ToolHandlerContract
{
    public function __construct(private readonly SmsService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->createTemplate($arguments['name'], $arguments['content'], $arguments['variables'] ?? [], $arguments['type'] ?? null);
    }
}
