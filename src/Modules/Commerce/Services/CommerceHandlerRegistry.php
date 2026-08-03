<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Services;

use MultiTenantSaas\Contracts\CommerceFulfillmentHandler;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Commerce\Handlers\ContentPackFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\CreditPackFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\MallSupplyFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\ModuleFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\PlanFulfillmentHandler;

/**
 * 履约 Handler 注册表
 *
 * 按 commerce_skus.fulfill_handler 标识路由到具体 Handler。
 * 参照 ToolRegistry 注册模式：框架内置消费类 Handler，
 * 下游项目可在 boot 阶段 register() 追加（如供给类落地实现）。
 */
class CommerceHandlerRegistry
{
    /** @var array<string, class-string<CommerceFulfillmentHandler>> */
    private array $handlers = [
        'credit_pack' => CreditPackFulfillmentHandler::class,
        'module' => ModuleFulfillmentHandler::class,
        'plan' => PlanFulfillmentHandler::class,
        'content_pack' => ContentPackFulfillmentHandler::class,
        'mall_supply' => MallSupplyFulfillmentHandler::class,
    ];

    /**
     * @param  class-string<CommerceFulfillmentHandler>  $handlerClass
     */
    public function register(string $name, string $handlerClass): void
    {
        $this->handlers[$name] = $handlerClass;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @return array<string, class-string<CommerceFulfillmentHandler>>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    public function resolve(string $name): CommerceFulfillmentHandler
    {
        if (! $this->has($name)) {
            throw new DomainException("未注册的履约 Handler [{$name}]");
        }

        return app($this->handlers[$name]);
    }
}
