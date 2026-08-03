<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Services;

use MultiTenantSaas\Contracts\SupplyProvisionerContract;
use MultiTenantSaas\Exceptions\DomainException;

/**
 * 供给落地器注册表
 *
 * 框架不内置 Provisioner（无具体业务产物），由下游项目（如 scrm）
 * 在 boot 阶段 register() 注入实现。参照 CommerceHandlerRegistry 模式。
 */
class SupplyProvisionerRegistry
{
    /** @var class-string<SupplyProvisionerContract>|null */
    private ?string $provisionerClass = null;

    /**
     * @param  class-string<SupplyProvisionerContract>  $provisionerClass
     */
    public function register(string $provisionerClass): void
    {
        $this->provisionerClass = $provisionerClass;
    }

    public function has(): bool
    {
        return $this->provisionerClass !== null;
    }

    public function resolve(): SupplyProvisionerContract
    {
        if (! $this->has()) {
            throw new DomainException('未注册 SupplyProvisioner（供给类 SKU 需下游项目落地实现）');
        }

        return app($this->provisionerClass);
    }
}
