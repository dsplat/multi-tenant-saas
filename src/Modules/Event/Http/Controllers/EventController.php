<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Event\Http\Controllers;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\Infrastructure\Services\EventBusService;

/**
 * 租户端：事件查询
 */
class EventController extends Controller
{
    public function index()
    {
        $service = app(EventBusService::class);

        return response()->json(['success' => true, 'data' => $service->getRecentEvents(100)]);
    }
}
