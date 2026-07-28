<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\KbSuggestion;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\KbSuggestionService;
use MultiTenantSaas\Modules\Ai\Services\Tool\SuggestKbUpdateTool;
use MultiTenantSaas\Tests\Schema\KbSuggestionModule;

/**
 * 知识库提案（AI 自学习回流通道）单元测试
 *
 * 覆盖：提案提交/幂等去重、状态流转、跨租户收割清单、
 * suggest_kb_update 工具参数校验与委托、harvest 命令 dry-run 与 reject
 */
class KbSuggestionTest extends TestCase
{
    protected array $uses = [KbSuggestionModule::class];

    private KbSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId('1001');
        $this->service = app(KbSuggestionService::class);
    }

    // ---------- KbSuggestionService ----------

    public function test_submit_creates_pending_suggestion(): void
    {
        $suggestion = $this->service->submit(1001, [
            'target_module' => 'customer',
            'target_doc' => 'customer/usage.md',
            'trigger_query' => '客户标签怎么批量导入？',
            'suggested_content' => '客户管理页支持 Excel 批量导入标签。',
        ]);

        $this->assertSame(KbSuggestion::STATUS_PENDING, $suggestion->status);
        $this->assertSame(1001, $suggestion->tenant_id);
        $this->assertNotEmpty($suggestion->suggestion_id);
        $this->assertDatabaseHas('kb_suggestions', [
            'suggestion_id' => $suggestion->suggestion_id,
            'target_doc' => 'customer/usage.md',
            'status' => 'pending',
        ]);
    }

    public function test_submit_is_idempotent_for_same_trigger_and_target(): void
    {
        $first = $this->service->submit(1001, [
            'target_doc' => 'customer/usage.md',
            'trigger_query' => '客户标签怎么批量导入？',
            'suggested_content' => '初版内容',
        ]);

        $second = $this->service->submit(1001, [
            'target_doc' => 'customer/usage.md',
            'trigger_query' => '客户标签怎么批量导入？',
            'suggested_content' => '修订后的内容',
        ]);

        $this->assertSame($first->suggestion_id, $second->suggestion_id);
        $this->assertSame(1, KbSuggestion::count());
        $this->assertSame('修订后的内容', $second->fresh()->suggested_content);
    }

    public function test_mark_adopted_only_touches_pending(): void
    {
        $pending = $this->service->submit(1001, [
            'trigger_query' => 'Q1',
            'suggested_content' => 'C1',
        ]);
        $rejected = $this->service->submit(1001, [
            'trigger_query' => 'Q2',
            'suggested_content' => 'C2',
        ]);
        $this->service->markRejected([(int) $rejected->suggestion_id]);

        $count = $this->service->markAdopted([
            (int) $pending->suggestion_id,
            (int) $rejected->suggestion_id,
        ]);

        $this->assertSame(1, $count);
        $this->assertSame(KbSuggestion::STATUS_ADOPTED, $pending->fresh()->status);
        $this->assertSame(KbSuggestion::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertNotNull($pending->fresh()->resolved_at);
    }

    public function test_list_pending_is_cross_tenant(): void
    {
        $this->service->submit(1001, ['trigger_query' => 'Q1', 'suggested_content' => 'C1']);

        TenantContext::setTenantId('2002');
        $this->service->submit(2002, ['trigger_query' => 'Q2', 'suggested_content' => 'C2']);

        TenantContext::clear();
        $pending = $this->service->listPending();

        $this->assertCount(2, $pending);
    }

    // ---------- suggest_kb_update 工具 ----------

    public function test_tool_rejects_missing_required_arguments(): void
    {
        $tool = new SuggestKbUpdateTool($this->service);

        $result = $tool(['trigger_query' => '只有问题没有内容'], 1001);

        $this->assertTrue($result['error']);
        $this->assertSame(0, KbSuggestion::count());
    }

    public function test_tool_submits_suggestion_and_returns_id(): void
    {
        $tool = new SuggestKbUpdateTool($this->service);

        $result = $tool([
            'trigger_query' => '优惠券怎么定向发放？',
            'suggested_content' => '营销中心支持按标签圈选定向发券。',
            'target_module' => 'coupon',
            'target_doc' => 'coupon/usage.md',
        ], 1001);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('pending', $result['status']);
        $this->assertDatabaseHas('kb_suggestions', [
            'suggestion_id' => $result['suggestion_id'],
            'target_module' => 'coupon',
        ]);
    }

    // ---------- secretary:kb:harvest 命令 ----------

    public function test_harvest_dry_run_lists_without_touching_status(): void
    {
        $this->service->submit(1001, [
            'target_doc' => 'nonexistent/never.md',
            'trigger_query' => '不存在文档的提案',
            'suggested_content' => '内容',
        ]);

        $this->artisan('secretary:kb:harvest', ['--dry-run' => true])
            ->expectsOutputToContain('待裁决')
            ->assertExitCode(0);

        $this->assertSame(1, KbSuggestion::where('status', 'pending')->count());
    }

    public function test_harvest_reject_marks_suggestion_rejected(): void
    {
        $suggestion = $this->service->submit(1001, [
            'trigger_query' => '要被拒绝的提案',
            'suggested_content' => '内容',
        ]);

        $this->artisan('secretary:kb:harvest', ['--reject' => [(string) $suggestion->suggestion_id]])
            ->assertExitCode(0);

        $this->assertSame(KbSuggestion::STATUS_REJECTED, $suggestion->fresh()->status);
    }
}
