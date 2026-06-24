<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 订阅处理：每日执行，发送到期提醒 + 过期降级 + 自动续费
Schedule::command('subscriptions:process')->dailyAt('08:00')->withoutOverlapping();

// 积分过期处理：每日执行，清理过期积分 + 低余额预警
Schedule::command('credits:process-expiry')->dailyAt('00:30')->withoutOverlapping();
