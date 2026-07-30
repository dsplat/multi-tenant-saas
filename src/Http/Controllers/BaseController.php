<?php

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Http\Concerns\ApiResponse;

/**
 * 框架级控制器基类
 *
 * 统一提供 successResponse() / errorResponse() / paginatedResponse()
 * 等 API 响应格式方法（见 ApiResponse trait），新控制器应继承本类
 * 而非直接继承 Illuminate\Routing\Controller。
 */
class BaseController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;
    use ValidatesRequests;
}
