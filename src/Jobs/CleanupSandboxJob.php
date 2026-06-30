<?php

namespace MultiTenantSaas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MultiTenantSaas\Services\SandboxService;

/**
 * 沙箱自动清理任务
 *
 * 在沙箱 TTL 到期后执行清理操作，删除沙箱租户与沙箱记录。
 */
class CleanupSandboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $sandboxId
    ) {
        $this->onQueue('default');
    }

    public function handle(SandboxService $sandboxService): void
    {
        $sandboxService->cleanup($this->sandboxId);
    }
}
