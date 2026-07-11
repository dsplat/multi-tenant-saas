# Scheduler Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a centralized SchedulerService that manages all scheduled tasks, wire up missing scheduled operations (data retention, SMS batch, reports), and provide CLI visibility into the schedule.

**Architecture:** A `SchedulerService` wraps Laravel's `Schedule` facade to provide task registration, status tracking, and runtime metadata. Missing commands (`schedule:process-sms`, `schedule:process-reports`) are created. All scheduled tasks are registered in `routes/console.php`. A `schedule:list` CLI command shows the full schedule.

**Tech Stack:** Laravel Schedule facade, existing Console Commands, existing Services (RetentionService, SmsService, ReportService).

## Global Constraints

- PHP ^8.3, Laravel ^13.0
- Follow existing service patterns (constructor injection, tenant isolation)
- All new commands must have `--dry-run` option
- All new commands must use `withoutOverlapping()`
- Tests must pass with `composer test`

---

### Task 1: Create SchedulerService

**Files:**
- Create: `src/Services/SchedulerService.php`
- Test: `tests/SchedulerServiceTest.php`

**Interfaces:**
- Produces: `SchedulerService::register(Schedule $schedule)`, `SchedulerService::getTasks(): array`, `SchedulerService::isEnabled(string $task): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\SchedulerService;
use Illuminate\Support\Facades\Schedule;

class SchedulerServiceTest extends TestCase
{
    protected SchedulerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SchedulerService::class);
    }

    public function test_get_tasks_returns_registered_tasks(): void
    {
        $tasks = $this->service->getTasks();
        $this->assertIsArray($tasks);
        $this->assertNotEmpty($tasks);
    }

    public function test_each_task_has_required_fields(): void
    {
        $tasks = $this->service->getTasks();
        foreach ($tasks as $task) {
            $this->assertArrayHasKey('name', $task);
            $this->assertArrayHasKey('command', $task);
            $this->assertArrayHasKey('schedule', $task);
            $this->assertArrayHasKey('description', $task);
        }
    }

    public function test_is_enabled_returns_bool(): void
    {
        $tasks = $this->service->getTasks();
        $firstTask = array_key_first($tasks);
        $this->assertIsBool($this->service->isEnabled($firstTask));
    }

    public function test_is_enabled_returns_false_for_unknown_task(): void
    {
        $this->assertFalse($this->service->isEnabled('nonexistent-task'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- SchedulerServiceTest`
Expected: FAIL with "Class not found"

- [ ] **Step 3: Implement SchedulerService**

```php
<?php

namespace MultiTenantSaas\Services;

use Illuminate\Console\Scheduling\Schedule;

class SchedulerService
{
    /** @var array<string, array> Registered task definitions */
    protected array $tasks = [];

    /**
     * Register all scheduled tasks on the given Schedule instance.
     */
    public function register(Schedule $schedule): void
    {
        $this->defineTasks($schedule);
    }

    /**
     * Get all registered task metadata.
     *
     * @return array<string, array>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * Check if a named task is enabled (not disabled via config).
     */
    public function isEnabled(string $name): bool
    {
        if (! isset($this->tasks[$name])) {
            return false;
        }

        return config("tenancy.scheduler.{$name}", true);
    }

    /**
     * Define all scheduled tasks.
     */
    protected function defineTasks(Schedule $schedule): void
    {
        // 订阅处理：到期提醒 + 过期降级 + 自动续费 + 催收
        $this->addTask($schedule, 'subscriptions', [
            'command' => 'subscriptions:process',
            'schedule' => 'dailyAt:08:00',
            'description' => '订阅到期提醒、过期降级、自动续费、催收重试',
            'withoutOverlapping' => true,
        ]);

        // 积分过期：清理过期积分 + 低余额预警
        $this->addTask($schedule, 'credits', [
            'command' => 'credits:process-expiry',
            'schedule' => 'dailyAt:00:30',
            'description' => '积分过期清理、低余额预警、自动充值',
            'withoutOverlapping' => true,
        ]);

        // 数据保留：GDPR 合规清理
        $this->addTask($schedule, 'retention', [
            'command' => 'data:retention',
            'schedule' => 'dailyAt:03:00',
            'description' => '数据保留策略执行、过期数据清理/匿名化',
            'withoutOverlapping' => true,
        ]);

        // SMS 定时发送
        $this->addTask($schedule, 'sms-batch', [
            'command' => 'sms:process-batch',
            'schedule' => 'everyFifteenMinutes',
            'description' => '处理定时短信批量任务',
            'withoutOverlapping' => true,
        ]);

        // 定时报表
        $this->addTask($schedule, 'reports', [
            'command' => 'reports:send-scheduled',
            'schedule' => 'hourly',
            'description' => '发送定时报表（按 CustomReport.frequency）',
            'withoutOverlapping' => true,
        ]);

        // 内存清理
        $this->addTask($schedule, 'memory-cleanup', [
            'command' => 'memory:cleanup',
            'schedule' => 'dailyAt:04:00',
            'description' => '清理过期内存数据',
            'withoutOverlapping' => true,
        ]);

        // 内存衰减
        $this->addTask($schedule, 'memory-decay', [
            'command' => 'memory:decay',
            'schedule' => 'dailyAt:04:30',
            'description' => '执行内存衰减处理',
            'withoutOverlapping' => true,
        ]);
    }

    /**
     * Register a single task on the schedule.
     *
     * @param  array{command: string, schedule: string, description: string, withoutOverlapping?: bool}  $config
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

        // 解析 schedule
        $this->applySchedule($event, $config['schedule']);

        if ($config['withoutOverlapping'] ?? false) {
            $event->withoutOverlapping();
        }
    }

    /**
     * Apply schedule string to the event.
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- SchedulerServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/SchedulerService.php tests/SchedulerServiceTest.php
git commit -m "feat: add SchedulerService with task registration and status tracking"
```

---

### Task 2: Create SMS Batch Processing Command

**Files:**
- Create: `src/Console/Commands/ProcessSmsBatch.php`
- Test: `tests/ProcessSmsBatchTest.php`

**Interfaces:**
- Consumes: `SmsService::processPendingBatchTasks()` (to be added)
- Produces: Artisan command `sms:process-batch`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Console\Commands\ProcessSmsBatch;
use Illuminate\Support\Facades\Artisan;

class ProcessSmsBatchTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(ProcessSmsBatch::class));
    }

    public function test_command_has_dry_run_option(): void
    {
        $command = new ProcessSmsBatch();
        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- ProcessSmsBatchTest`
Expected: FAIL

- [ ] **Step 3: Implement ProcessSmsBatch command**

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSmsBatch extends Command
{
    protected $signature = 'sms:process-batch
        {--dry-run : 只显示待处理任务，不实际发送}';

    protected $description = '处理定时短信批量任务（scheduled_at <= now 且 status=pending）';

    public function handle(): int
    {
        $pendingTasks = DB::table('sms_batch_tasks')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get();

        if ($pendingTasks->isEmpty()) {
            $this->info('没有待处理的短信任务');

            return self::SUCCESS;
        }

        $this->info("找到 {$pendingTasks->count()} 个待处理任务");

        if ($this->option('dry-run')) {
            foreach ($pendingTasks as $task) {
                $this->line("  [DRY] Task #{$task->id}: {$task->template_code} → scheduled_at={$task->scheduled_at}");
            }

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($pendingTasks as $task) {
            try {
                DB::table('sms_batch_tasks')
                    ->where('id', $task->id)
                    ->update(['status' => 'processing', 'updated_at' => now()]);

                // 实际发送逻辑委托给 SmsService
                $result = app(\MultiTenantSaas\Modules\Sms\Services\SmsService::class)
                    ->executeBatchTask($task->id);

                if ($result) {
                    DB::table('sms_batch_tasks')
                        ->where('id', $task->id)
                        ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
                    $processed++;
                } else {
                    DB::table('sms_batch_tasks')
                        ->where('id', $task->id)
                        ->update(['status' => 'failed', 'updated_at' => now()]);
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('[ProcessSmsBatch] Task failed', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
                DB::table('sms_batch_tasks')
                    ->where('id', $task->id)
                    ->update(['status' => 'failed', 'updated_at' => now()]);
                $failed++;
            }
        }

        $this->info("处理完成: {$processed} 成功, {$failed} 失败");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- ProcessSmsBatchTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Console/Commands/ProcessSmsBatch.php tests/ProcessSmsBatchTest.php
git commit -m "feat: add sms:process-batch command for scheduled SMS tasks"
```

---

### Task 3: Create Scheduled Reports Command

**Files:**
- Create: `src/Console/Commands/ProcessScheduledReports.php`
- Test: `tests/ProcessScheduledReportsTest.php`

**Interfaces:**
- Consumes: `ReportService::sendReport()`, `CustomReport` model
- Produces: Artisan command `reports:send-scheduled`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Console\Commands\ProcessScheduledReports;

class ProcessScheduledReportsTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(ProcessScheduledReports::class));
    }

    public function test_command_has_dry_run_option(): void
    {
        $command = new ProcessScheduledReports();
        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- ProcessScheduledReportsTest`
Expected: FAIL

- [ ] **Step 3: Implement ProcessScheduledReports command**

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\CustomReport;
use MultiTenantSaas\Services\ReportService;

class ProcessScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled
        {--dry-run : 只显示待发送报表，不实际发送}';

    protected $description = '发送定时报表（按 CustomReport.frequency 和 next_send_at）';

    public function handle(ReportService $reportService): int
    {
        $dueReports = CustomReport::query()
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now())
            ->where('status', 'active')
            ->get();

        if ($dueReports->isEmpty()) {
            $this->info('没有待发送的报表');

            return self::SUCCESS;
        }

        $this->info("找到 {$dueReports->count()} 个待发送报表");

        if ($this->option('dry-run')) {
            foreach ($dueReports as $report) {
                $this->line("  [DRY] Report #{$report->id}: {$report->name} → next_send_at={$report->next_send_at}");
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($dueReports as $report) {
            try {
                $reportService->sendReport($report);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('[ProcessScheduledReports] Report failed', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->info("发送完成: {$sent} 成功, {$failed} 失败");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- ProcessScheduledReportsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Console/Commands/ProcessScheduledReports.php tests/ProcessScheduledReportsTest.php
git commit -m "feat: add reports:send-scheduled command for scheduled reports"
```

---

### Task 4: Wire Up Scheduler in routes/console.php

**Files:**
- Modify: `routes/console.php`
- Modify: `src/TenancyServiceProvider.php` (register SchedulerService)

**Interfaces:**
- Consumes: `SchedulerService::register(Schedule $schedule)`

- [ ] **Step 1: Update routes/console.php**

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use MultiTenantSaas\Services\SchedulerService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 通过 SchedulerService 统一注册所有定时任务
app(SchedulerService::class)->register(Schedule::getFacadeRoot());
```

- [ ] **Step 2: Register SchedulerService in TenancyServiceProvider**

Add to `register()` method:

```php
$this->app->singleton(SchedulerService::class);
```

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: All 2269+ tests pass

- [ ] **Step 4: Commit**

```bash
git add routes/console.php src/TenancyServiceProvider.php
git commit -m "feat: wire up SchedulerService in routes/console.php"
```

---

### Task 5: Add schedule:list CLI Command

**Files:**
- Create: `src/Console/Commands/ScheduleListCommand.php`
- Test: `tests/ScheduleListCommandTest.php`

**Interfaces:**
- Consumes: `SchedulerService::getTasks()`
- Produces: Artisan command `schedule:list`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Console\Commands\ScheduleListCommand;

class ScheduleListCommandTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(ScheduleListCommand::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- ScheduleListCommandTest`
Expected: FAIL

- [ ] **Step 3: Implement ScheduleListCommand**

```php
<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\SchedulerService;

class ScheduleListCommand extends Command
{
    protected $signature = 'schedule:list
        {--json : 输出 JSON 格式}';

    protected $description = '列出所有定时任务及其状态';

    public function handle(SchedulerService $scheduler): int
    {
        $tasks = $scheduler->getTasks();

        if ($this->option('json')) {
            $this->line(json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $enabled = $scheduler->isEnabled($task['name']);
            $rows[] = [
                $task['name'],
                $task['command'],
                $task['schedule'],
                $enabled ? '✅' : '❌',
                $task['description'],
            ];
        }

        $this->table(['名称', '命令', '调度', '状态', '说明'], $rows);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register command in TenancyServiceProvider**

Add to the commands array in `boot()`:

```php
\ScheduleListCommand::class,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test:filter -- ScheduleListCommandTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/ScheduleListCommand.php tests/ScheduleListCommandTest.php src/TenancyServiceProvider.php
git commit -m "feat: add schedule:list command to show all scheduled tasks"
```

---

### Task 6: Add Scheduler Config

**Files:**
- Modify: `config/tenancy.php`

- [ ] **Step 1: Add scheduler config section**

Add to `config/tenancy.php` before the closing `];`:

```php
    // 定时任务开关 (key = 任务名, value = true/false)
    'scheduler' => [
        'subscriptions' => true,
        'credits' => true,
        'retention' => true,
        'sms-batch' => true,
        'reports' => true,
        'memory-cleanup' => true,
        'memory-decay' => true,
    ],
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add config/tenancy.php
git commit -m "feat: add scheduler config for task enable/disable"
```

---

### Task 7: Update Tests and Documentation

**Files:**
- Modify: `docs/zh/user-manual.md` (add scheduler section)
- Modify: `docs/en/user-manual.md` (add scheduler placeholder)

- [ ] **Step 1: Add scheduler section to user manual**

Add to `docs/zh/user-manual.md` after the Module Management section:

```markdown
## 定时任务

框架通过 `SchedulerService` 统一管理所有定时任务。

### 查看定时任务

```bash
php artisan schedule:list
```

### 定时任务列表

| 任务 | 命令 | 调度 | 说明 |
|------|------|------|------|
| subscriptions | `subscriptions:process` | 每日 08:00 | 订阅到期提醒、过期降级、自动续费 |
| credits | `credits:process-expiry` | 每日 00:30 | 积分过期清理、低余额预警 |
| retention | `data:retention` | 每日 03:00 | 数据保留策略执行 |
| sms-batch | `sms:process-batch` | 每15分钟 | 定时短信批量任务 |
| reports | `reports:send-scheduled` | 每小时 | 定时报表发送 |
| memory-cleanup | `memory:cleanup` | 每日 04:00 | 内存数据清理 |
| memory-decay | `memory:decay` | 每日 04:30 | 内存衰减处理 |

### 禁用定时任务

在 `config/tenancy.php` 的 `scheduler` 配置中设置对应任务为 `false`：

```php
'scheduler' => [
    'sms-batch' => false,  // 禁用短信批量任务
],
```

### 运行调度器

```bash
# 生产环境
php artisan schedule:run

# Laravel 11+ 自动调度
# 在 bootstrap/app.php 中已配置 commands 路由
```
```

- [ ] **Step 2: Run final test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add docs/zh/user-manual.md docs/en/user-manual.md
git commit -m "docs: add scheduler section to user manual"
```
