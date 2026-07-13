<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 白标品牌配置模型
 *
 * 租户自定义 Logo、favicon、主色调/辅助色、自定义 CSS、自定义域名、
 * 登录页样式与邮件模板品牌化配置。每个租户一条记录。
 */
class BrandingConfig extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $primaryKey = 'branding_config_id';

    protected $fillable = [
        'tenant_id',
        'logo_url',
        'favicon_url',
        'primary_color',
        'secondary_color',
        'custom_css',
        'custom_domain',
        'login_page_style',
        'email_template',
    ];

    protected $attributes = [
        'login_page_style' => 'default',
        'email_template' => 'default',
    ];

    protected function casts(): array
    {
        return [
            'branding_config_id' => 'integer',
            'tenant_id' => 'integer',
        ];
    }
}
