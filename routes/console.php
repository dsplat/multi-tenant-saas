<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use MultiTenantSaas\Modules\Infrastructure\Services\SchedulerService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 通过 SchedulerService 统一注册所有定时任务
app(SchedulerService::class)->register(Schedule::getFacadeRoot());
