<?php

namespace MultiTenantSaas\Services;

use Illuminate\Console\Scheduling\Schedule;

/**
 * 定时任务调度服务
 *
 * 集中管理所有定时任务的注册、状态查询和调度配置。
 * 任务可通过 config/tenancy.php 的 scheduler 配置单独禁用。
 */
class SchedulerService
{
    /** @var array<string, array> 已注册的任务定义 */
    protected array $tasks = [];

    /**
     * 注册所有定时任务到 Schedule 实例。
     */
    public function register(Schedule $schedule): void
    {
        $this->defineTasks($schedule);
    }

    /**
     * 获取所有已注册任务的元数据。
     *
     * @return array<string, array>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * 检查指定任务是否启用。
     */
    public function isEnabled(string $name): bool
    {
        if (! isset($this->tasks[$name])) {
            return false;
        }

        return config("tenancy.scheduler.{$name}", true);
    }

    /**
     * 定义所有定时任务。
     */
    protected function defineTasks(Schedule $schedule): void
    {
        $this->addTask($schedule, 'subscriptions', [
            'command' => 'subscriptions:process',
            'schedule' => 'dailyAt:08:00',
            'description' => '订阅到期提醒、过期降级、自动续费、催收重试',
        ]);

        $this->addTask($schedule, 'credits', [
            'command' => 'credits:process-expiry',
            'schedule' => 'dailyAt:00:30',
            'description' => '积分过期清理、低余额预警、自动充值',
        ]);

        $this->addTask($schedule, 'retention', [
            'command' => 'data:retention',
            'schedule' => 'dailyAt:03:00',
            'description' => '数据保留策略执行、过期数据清理/匿名化',
        ]);

        $this->addTask($schedule, 'sms-batch', [
            'command' => 'sms:process-batch',
            'schedule' => 'everyFifteenMinutes',
            'description' => '处理定时短信批量任务',
        ]);

        $this->addTask($schedule, 'reports', [
            'command' => 'reports:send-scheduled',
            'schedule' => 'hourly',
            'description' => '发送定时报表（按 CustomReport.frequency）',
        ]);

        $this->addTask($schedule, 'memory-cleanup', [
            'command' => 'memory:cleanup',
            'schedule' => 'dailyAt:04:00',
            'description' => '清理过期内存数据',
        ]);

        $this->addTask($schedule, 'memory-decay', [
            'command' => 'memory:decay',
            'schedule' => 'dailyAt:04:30',
            'description' => '执行内存衰减处理',
        ]);

        $this->addTask($schedule, 'mailer-health', [
            'command' => 'mailer:health-check',
            'schedule' => 'dailyAt:05:00',
            'description' => '检查邮件服务健康状态',
        ]);
    }

    /**
     * 注册单个任务。
     *
     * @param  array{command: string, schedule: string, description: string}  $config
     */
    protected function addTask(Schedule $schedule, string $name, array $config): void
    {
        $this->tasks[$name] = [
            'name' => $name,
            'command' => $config['command'],
            'schedule' => $config['schedule'],
            'description' => $config['description'],
        ];

        if (! $this->isEnabled($name)) {
            return;
        }

        $event = $schedule->command($config['command']);
        $this->applySchedule($event, $config['schedule']);
        $event->withoutOverlapping();
    }

    /**
     * 根据调度字符串设置调度规则。
     */
    protected function applySchedule($event, string $schedule): void
    {
        if (str_starts_with($schedule, 'dailyAt:')) {
            $event->dailyAt(substr($schedule, 8));
        } elseif ($schedule === 'daily') {
            $event->daily();
        } elseif ($schedule === 'hourly') {
            $event->hourly();
        } elseif ($schedule === 'everyFifteenMinutes') {
            $event->everyFifteenMinutes();
        } elseif ($schedule === 'everyMinute') {
            $event->everyMinute();
        } elseif (str_starts_with($schedule, 'cron:')) {
            $event->cron(substr($schedule, 5));
        } else {
            $event->daily();
        }
    }
}
