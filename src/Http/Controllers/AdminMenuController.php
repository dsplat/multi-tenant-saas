<?php

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminMenuController extends Controller
{
    /**
     * 默认管理后台菜单配置。
     * 项目侧可通过 config('tenancy.admin_menu') 覆盖或追加。
     */
    protected array $defaultMenu = [
        [
            'name' => '仪表盘',
            'path' => '/admin/dashboard',
            'icon' => 'dashboard',
            'order' => 1,
            'permission' => null,
        ],
        [
            'name' => '租户管理',
            'path' => '/admin/tenants',
            'icon' => 'tenants',
            'order' => 10,
            'permission' => null,
        ],
        [
            'name' => '用户管理',
            'path' => '/admin/users',
            'icon' => 'users',
            'order' => 20,
            'permission' => null,
        ],
        [
            'name' => '域名管理',
            'path' => '/admin/domains',
            'icon' => 'domains',
            'order' => 30,
            'permission' => null,
        ],
        [
            'name' => '第三方登录',
            'path' => '/admin/oauth',
            'icon' => 'oauth',
            'order' => 40,
            'permission' => null,
        ],
        [
            'name' => '审计日志',
            'path' => '/admin/audit-logs',
            'icon' => 'audit',
            'order' => 50,
            'permission' => null,
        ],
        [
            'name' => '短信配置',
            'path' => '/admin/sms',
            'icon' => 'sms',
            'order' => 60,
            'permission' => null,
        ],
        [
            'name' => '支付订单',
            'path' => '/admin/payments',
            'icon' => 'payments',
            'order' => 70,
            'permission' => null,
        ],
        [
            'name' => 'API Token',
            'path' => '/admin/api-tokens',
            'icon' => 'api-tokens',
            'order' => 80,
            'permission' => null,
        ],
        [
            'name' => '配额管理',
            'path' => '/admin/quotas',
            'icon' => 'quotas',
            'order' => 90,
            'permission' => null,
        ],
        [
            'name' => '系统设置',
            'path' => '/admin/settings',
            'icon' => 'settings',
            'order' => 100,
            'permission' => null,
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $menu = $this->getMenu();

        return response()->json([
            'success' => true,
            'data' => $menu,
        ]);
    }

    protected function getMenu(): array
    {
        // 合并默认菜单和项目侧配置
        $configMenu = config('tenancy.admin_menu', []);
        $menu = array_merge($this->defaultMenu, $configMenu);

        // 按 order 排序
        usort($menu, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));

        // 过滤无权限的菜单项
        $user = auth()->user();
        $menu = array_filter($menu, function ($item) use ($user) {
            if (empty($item['permission'])) {
                return true;
            }

            return $user && method_exists($user, 'hasPermission')
                ? $user->hasPermission($item['permission'])
                : true;
        });

        // 重新索引
        return array_values($menu);
    }
}
