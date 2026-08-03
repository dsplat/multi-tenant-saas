<?php

namespace MultiTenantSaas\Modules\Payment;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Payment\Services\Tools\PaymentCreateOrderHandler;
use MultiTenantSaas\Modules\Payment\Services\Tools\PaymentGetPackagesHandler;
use MultiTenantSaas\Modules\Payment\Services\Tools\PaymentQueryOrderHandler;

class PaymentServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'payment';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->registerTools();
        $this->loadAdminTenantRoutes();
        $this->loadModuleViews();
    }

    protected function loadAdminTenantRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());

        // tenant.php 由基类统一挂 api/v1 前缀 + tenant.identify
        foreach (['admin.php', 'api.php'] as $file) {
            $path = $moduleDir . '/Routes/' . $file;
            if (file_exists($path)) {
                $middleware = ['auth:sanctum', 'throttle:api'];
                if ($file !== 'admin.php') {
                    $middleware[] = 'tenant.identify';
                }
                Route::middleware($middleware)
                    ->prefix('api/v1')
                    ->group($path);
            }
        }
    }

    protected function loadModuleViews(): void
    {
        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $viewsDir = $moduleDir . '/resources/views';

        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'module.' . $this->moduleName);
        }
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('payment_create_order', 'Payment Create Order', 'Create order', PaymentCreateOrderHandler::class, ['type' => 'object', 'properties' => ['amount' => ['type' => 'number', 'description' => '金额'], 'description' => ['type' => 'string', 'description' => '描述'], 'channel' => ['type' => 'string', 'description' => '支付渠道']], 'required' => ['amount']], 'payment', 'L2');
        $registry->register('payment_query_order', 'Payment Query Order', 'Query order', PaymentQueryOrderHandler::class, ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string', 'description' => '订单号']], 'required' => ['order_id']], 'payment', 'L1');
        $registry->register('payment_get_packages', 'Payment Get Packages', 'Get packages', PaymentGetPackagesHandler::class, ['type' => 'object', 'properties' => []], 'payment', 'L1');
    }
}
