<?php

namespace MultiTenantSaas\Modules\Coupon;

use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponActivateHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponBulkDistributeHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponCalculateDiscountHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponCreateHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponCreateTemplateHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponDeactivateHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponGenerateCodesHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponGetStatisticsHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponGetUsagesHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponRedeemHandler;
use MultiTenantSaas\Modules\Coupon\Services\Tools\CouponUpdateTemplateHandler;

class CouponServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'coupon';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(CouponService::class, fn ($app) => new CouponService($app->make(TenantContextContract::class)));
    }

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('coupon_create', 'Coupon Create', 'Create', CouponCreateHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '优惠券名称'], 'type' => ['type' => 'string', 'description' => '类型'], 'value' => ['type' => 'number', 'description' => '面值'], 'threshold' => ['type' => 'number', 'description' => '使用门槛']], 'required' => ['name', 'type', 'value']], 'coupon', 'L2');
        $registry->register('coupon_create_template', 'Coupon Create Template', 'Create template', CouponCreateTemplateHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '模板名称'], 'type' => ['type' => 'string', 'description' => '类型'], 'value' => ['type' => 'number', 'description' => '面值']], 'required' => ['name', 'type', 'value']], 'coupon', 'L2');
        $registry->register('coupon_update_template', 'Coupon Update Template', 'Update template', CouponUpdateTemplateHandler::class, ['type' => 'object', 'properties' => ['template_id' => ['type' => 'integer', 'description' => '模板ID'], 'name' => ['type' => 'string', 'description' => '名称'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['template_id']], 'coupon', 'L2');
        $registry->register('coupon_activate', 'Coupon Activate', 'Activate', CouponActivateHandler::class, ['type' => 'object', 'properties' => ['coupon_id' => ['type' => 'integer', 'description' => '优惠券ID']], 'required' => ['coupon_id']], 'coupon', 'L2');
        $registry->register('coupon_deactivate', 'Coupon Deactivate', 'Deactivate', CouponDeactivateHandler::class, ['type' => 'object', 'properties' => ['coupon_id' => ['type' => 'integer', 'description' => '优惠券ID']], 'required' => ['coupon_id']], 'coupon', 'L2');
        $registry->register('coupon_redeem', 'Coupon Redeem', 'Redeem', CouponRedeemHandler::class, ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'description' => '兑换码']], 'required' => ['code']], 'coupon', 'L2');
        $registry->register('coupon_generate_codes', 'Coupon Generate Codes', 'Generate codes', CouponGenerateCodesHandler::class, ['type' => 'object', 'properties' => ['prefix' => ['type' => 'string', 'description' => '前缀'], 'quantity' => ['type' => 'integer', 'description' => '数量']], 'required' => ['prefix', 'quantity']], 'coupon', 'L2');
        $registry->register('coupon_get_statistics', 'Coupon Get Statistics', 'Get statistics', CouponGetStatisticsHandler::class, ['type' => 'object', 'properties' => ['coupon_id' => ['type' => 'integer', 'description' => '优惠券ID']], 'required' => ['coupon_id']], 'coupon', 'L1');
        $registry->register('coupon_get_usages', 'Coupon Get Usages', 'Get usages', CouponGetUsagesHandler::class, ['type' => 'object', 'properties' => ['coupon_id' => ['type' => 'integer', 'description' => '优惠券ID']], 'required' => ['coupon_id']], 'coupon', 'L1');
        $registry->register('coupon_calculate_discount', 'Coupon Calculate Discount', 'Calculate discount', CouponCalculateDiscountHandler::class, ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'description' => '优惠券码'], 'amount' => ['type' => 'number', 'description' => '订单金额']], 'required' => ['code', 'amount']], 'coupon', 'L1');
        $registry->register('coupon_bulk_distribute', 'Coupon Bulk Distribute', 'Bulk distribute', CouponBulkDistributeHandler::class, ['type' => 'object', 'properties' => ['coupon_id' => ['type' => 'integer', 'description' => '优惠券ID'], 'user_ids' => ['type' => 'array', 'description' => '用户ID列表']], 'required' => ['coupon_id', 'user_ids']], 'coupon', 'L2');
    }
}
