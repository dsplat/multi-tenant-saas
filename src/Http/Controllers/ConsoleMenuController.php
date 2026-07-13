<?php

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConsoleMenuController extends Controller
{
    /**
     * 默认租户后台菜单配置。
     * 项目侧可通过 config('tenancy.console_menu') 覆盖或追加。
     */
    protected array $defaultMenu = [
        [
            'name' => '工作台',
            'path' => '/console/dashboard',
            'icon' => 'dashboard',
            'order' => 1,
            'permission' => null,
        ],
        [
            'name' => '成员管理',
            'path' => '/console/members',
            'icon' => 'members',
            'order' => 10,
            'permission' => null,
        ],
        [
            'name' => '积分管理',
            'path' => '/console/credits',
            'icon' => 'credits',
            'order' => 20,
            'permission' => null,
        ],
        [
            'name' => '第三方登录',
            'path' => '/console/oauth',
            'icon' => 'oauth',
            'order' => 30,
            'permission' => null,
        ],
        [
            'name' => '支付配置',
            'path' => '/console/payment',
            'icon' => 'payment',
            'order' => 40,
            'permission' => null,
        ],
        [
            'name' => '短信配置',
            'path' => '/console/sms',
            'icon' => 'sms',
            'order' => 50,
            'permission' => null,
        ],
        [
            'name' => 'API Token',
            'path' => '/console/api-tokens',
            'icon' => 'api-tokens',
            'order' => 60,
            'permission' => null,
        ],
        [
            'name' => '邮件/认证/注册',
            'path' => '/console/tenant-settings',
            'icon' => 'settings',
            'order' => 70,
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
        $configMenu = config('tenancy.console_menu', []);
        $menu = array_merge($this->defaultMenu, $configMenu);

        usort($menu, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));

        $user = auth()->user();
        $menu = array_filter($menu, function ($item) use ($user) {
            if (empty($item['permission'])) {
                return true;
            }

            return $user && method_exists($user, 'hasPermission')
                ? $user->hasPermission($item['permission'])
                : true;
        });

        return array_values($menu);
    }
}
