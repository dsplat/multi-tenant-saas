<?php

namespace MultiTenantSaas\Modules\Platform;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'platform';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadModuleViews();
    }

    protected function loadModuleViews(): void
    {
        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $viewsDir = $moduleDir . '/resources/views';

        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'module.' . $this->moduleName);
        }
    }
}
