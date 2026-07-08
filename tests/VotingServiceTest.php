<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\Vote;
use MultiTenantSaas\Models\VoteOption;
use MultiTenantSaas\Models\VoteRecord;
use MultiTenantSaas\Services\VotingService;
use MultiTenantSaas\Tests\Schema\VotingModule;

class VotingServiceTest extends TestCase
{
    protected array $uses = [VotingModule::class];

    protected VotingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VotingService();

        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'Voting Tenant A',
            'slug' => 'voting-tenant-a',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 2002,
            'name' => 'Voting Tenant B',
            'slug' => 'voting-tenant-b',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(2001);
    }

    // ---------- 活动管理 ----------

    public function test_create_vote(): void
    {
        $vote = $this->service->createVote([
            'title' => '最佳员工评选',
            'description' => '评选年度最佳员工',
            'vote_type' => 'single',
            'status' => 'draft',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(7),
            'options' => [
                ['title' => '候选人A', 'sort_order' => 0],
                ['title' => '候选人B', 'sort_order' => 1],
                ['title' => '候选人C', 'sort_order' => 2],
            ],
        ], 2001);

        $this->assertNotNull($vote->vote_id);
        $this->assertEquals('最佳员工评选', $vote->title);
        $this->assertEquals('single', $vote->vote_type);
        $this->assertEquals(3, $vote->options->count());
    }

    public function test_update_vote(): void
    {
        $vote = $this->service->createVote([
            'title' => '原标题',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], 2001);

        $updated = $this->service->updateVote($vote, [
            'title' => '新标题',
        ]);

        $this->assertEquals('新标题', $updated->title);
    }

    public function test_update_vote_options(): void
    {
        $vote = $this->service->createVote([
            'title' => '测试投票',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], 2001);

        $updated = $this->service->updateVote($vote, [
            'options' => [
                ['title' => '新选项X'],
                ['title' => '新选项Y'],
                ['title' => '新选项Z'],
            ],
        ]);

        $this->assertEquals(3, $updated->options->count());
        $this->assertEquals('新选项X', $updated->options->first()->title);
    }

    // ---------- 投票执行 ----------

    public function test_cast_single_vote(): void
    {
        $vote = $this->createActiveVote();
        $optionId = $vote->options->first()->vote_option_id;

        $records = $this->service->castVote(
            $vote->vote_id,
            [$optionId],
            1001,
            2001,
            '127.0.0.1',
            'Test Agent'
        );

        $this->assertCount(1, $records);
        $this->assertEquals($optionId, $records->first()->vote_option_id);

        // 验证计数增加
        $option = VoteOption::find($optionId);
        $this->assertEquals(1, $option->vote_count);

        $vote->refresh();
        $this->assertEquals(1, $vote->total_votes);
    }

    public function test_cast_multiple_vote(): void
    {
        $vote = $this->createActiveVote([
            'vote_type' => 'multiple',
        ]);

        $optionIds = $vote->options->pluck('vote_option_id')->toArray();

        $records = $this->service->castVote(
            $vote->vote_id,
            array_slice($optionIds, 0, 2),
            1001,
            2001
        );

        $this->assertCount(2, $records);
    }

    public function test_cast_vote_inactive_throws(): void
    {
        $vote = $this->service->createVote([
            'title' => '测试投票',
            'status' => 'draft',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(7),
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], 2001);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('投票活动未开启');

        $this->service->castVote($vote->vote_id, [1], 1001, 2001);
    }

    public function test_cast_vote_expired_throws(): void
    {
        $vote = $this->service->createVote([
            'title' => '测试投票',
            'status' => 'active',
            'start_at' => now()->subDays(7),
            'end_at' => now()->subDay(),
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], 2001);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('投票已结束');

        $this->service->castVote($vote->vote_id, [1], 1001, 2001);
    }

    public function test_cast_vote_not_started_throws(): void
    {
        $vote = $this->service->createVote([
            'title' => '测试投票',
            'status' => 'active',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(7),
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], 2001);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('投票尚未开始');

        $this->service->castVote($vote->vote_id, [1], 1001, 2001);
    }

    public function test_cast_single_vote_multiple_options_throws(): void
    {
        $vote = $this->createActiveVote([
            'vote_type' => 'single',
        ]);

        $optionIds = $vote->options->pluck('vote_option_id')->toArray();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('单选投票只能选择一个选项');

        $this->service->castVote($vote->vote_id, $optionIds, 1001, 2001);
    }

    public function test_cast_vote_user_daily_limit(): void
    {
        $vote = $this->createActiveVote([
            'daily_limit_per_user' => 1,
        ]);

        $optionId = $vote->options->first()->vote_option_id;

        // 第一次投票成功
        $this->service->castVote($vote->vote_id, [$optionId], 1001, 2001);

        // 第二次投票失败
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('您今日投票次数已达上限');

        $this->service->castVote($vote->vote_id, [$optionId], 1001, 2001);
    }

    public function test_cast_vote_invalid_option_throws(): void
    {
        $vote = $this->createActiveVote();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('无效的投票选项');

        $this->service->castVote($vote->vote_id, [999999], 1001, 2001);
    }

    // ---------- 排行榜与统计 ----------

    public function test_get_ranking(): void
    {
        $vote = $this->createActiveVote();
        $optionIds = $vote->options->pluck('vote_option_id')->toArray();

        // 给不同选项投票
        $this->service->castVote($vote->vote_id, [$optionIds[0]], 1001, 2001);
        $this->service->castVote($vote->vote_id, [$optionIds[0]], 1002, 2001);
        $this->service->castVote($vote->vote_id, [$optionIds[1]], 1003, 2001);

        $ranking = $this->service->getRanking($vote->vote_id);

        $this->assertEquals(3, $ranking['total_votes']);
        $this->assertCount(2, $ranking['ranking']);
        $this->assertEquals(1, $ranking['ranking'][0]['rank']);
        $this->assertEquals(2, $ranking['ranking'][0]['vote_count']);
    }

    public function test_get_statistics(): void
    {
        $vote = $this->createActiveVote();
        $optionId = $vote->options->first()->vote_option_id;

        $this->service->castVote($vote->vote_id, [$optionId], 1001, 2001);

        $stats = $this->service->getStatistics($vote->vote_id);

        $this->assertEquals(1, $stats['total_votes']);
        $this->assertEquals(1, $stats['today_votes']);
        $this->assertNotEmpty($stats['options']);
    }

    public function test_get_records(): void
    {
        $vote = $this->createActiveVote();
        $optionId = $vote->options->first()->vote_option_id;

        $this->service->castVote($vote->vote_id, [$optionId], 1001, 2001);
        $this->service->castVote($vote->vote_id, [$optionId], 1002, 2001);

        $records = $this->service->getRecords($vote->vote_id);

        $this->assertCount(2, $records);
    }

    public function test_get_records_filter_by_user(): void
    {
        $vote = $this->createActiveVote();
        $optionId = $vote->options->first()->vote_option_id;

        $this->service->castVote($vote->vote_id, [$optionId], 1001, 2001);
        $this->service->castVote($vote->vote_id, [$optionId], 1002, 2001);

        $records = $this->service->getRecords($vote->vote_id, ['user_id' => 1001]);

        $this->assertCount(1, $records);
    }

    // ---------- 设备指纹防刷 ----------

    public function test_cast_vote_with_fingerprint(): void
    {
        $vote = $this->createActiveVote();
        $optionId = $vote->options->first()->vote_option_id;

        $records = $this->service->castVote(
            $vote->vote_id,
            [$optionId],
            1001,
            2001,
            '127.0.0.1',
            'Test Agent',
            'abc123fingerprint'
        );

        $this->assertCount(1, $records);
        $this->assertEquals('abc123fingerprint', $records->first()->fingerprint);
    }

    public function test_cast_vote_fingerprint_rate_limit(): void
    {
        $vote = $this->createActiveVote();
        $optionId = $vote->options->first()->vote_option_id;

        // 同一指纹短时间内投票5次
        for ($i = 0; $i < 5; $i++) {
            $this->service->castVote(
                $vote->vote_id,
                [$optionId],
                1000 + $i,
                2001,
                '127.0.0.1',
                'Test Agent',
                'same_fingerprint'
            );
        }

        // 第6次应该失败
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('检测到异常操作，请稍后再试');

        $this->service->castVote(
            $vote->vote_id,
            [$optionId],
            9999,
            2001,
            '127.0.0.1',
            'Test Agent',
            'same_fingerprint'
        );
    }

    // ---------- 租户隔离 ----------

    public function test_votes_isolated_by_tenant(): void
    {
        $this->service->createVote([
            'title' => '租户A投票',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], 2001);

        $this->service->createVote([
            'title' => '租户B投票',
            'options' => [
                ['title' => '选项X'],
                ['title' => '选项Y'],
            ],
        ], 2002);

        // 使用 withoutGlobalScopes 绕过租户隔离检查
        $tenantAVotes = Vote::withoutGlobalScopes()->where('tenant_id', 2001)->count();
        $tenantBVotes = Vote::withoutGlobalScopes()->where('tenant_id', 2002)->count();

        $this->assertEquals(1, $tenantAVotes);
        $this->assertEquals(1, $tenantBVotes);
    }

    // ---------- 辅助方法 ----------

    private function createActiveVote(array $overrides = []): Vote
    {
        return $this->service->createVote(array_merge([
            'title' => '测试投票',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(7),
            'options' => [
                ['title' => '选项A', 'sort_order' => 0],
                ['title' => '选项B', 'sort_order' => 1],
            ],
        ], $overrides), 2001);
    }
}
