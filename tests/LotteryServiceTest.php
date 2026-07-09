<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Lottery\Models\LotteryActivity;
use MultiTenantSaas\Modules\Lottery\Models\LotteryActivityPrize;
use MultiTenantSaas\Modules\Lottery\Models\LotteryBlacklist;
use MultiTenantSaas\Modules\Lottery\Models\LotteryDrawLog;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;
use MultiTenantSaas\Tests\Schema\LotteryModule;

class LotteryServiceTest extends TestCase
{
    protected array $uses = [LotteryModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Lottery Tenant A',
            'slug' => 'lottery-tenant-a',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Lottery Tenant B',
            'slug' => 'lottery-tenant-b',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(1001);
    }

    // ---------- 活动管理 ----------

    public function test_create_activity(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '新年抽奖',
            'slug' => 'new-year-lottery',
            'description' => '新年大抽奖活动',
            'status' => 'draft',
        ]);

        $this->assertNotNull($activity->activity_id);
        $this->assertEquals('新年抽奖', $activity->title);
        $this->assertEquals('draft', $activity->status);
    }

    public function test_update_activity(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '原标题',
            'slug' => 'test-activity',
        ]);

        $updated = LotteryService::updateActivity($activity->activity_id, [
            'title' => '新标题',
        ]);

        $this->assertEquals('新标题', $updated->title);
    }

    public function test_get_activity_with_prizes(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '测试活动',
            'slug' => 'test-activity',
        ]);

        LotteryService::addPrize($activity->activity_id, [
            'name' => '一等奖',
            'type' => 'physical',
            'value' => 100,
            'total_count' => 1,
            'weight' => 1,
        ]);

        $result = LotteryService::getActivity($activity->activity_id);

        $this->assertEquals('测试活动', $result->title);
        $this->assertCount(1, $result->prizes);
    }

    public function test_get_activities(): void
    {
        LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '活动1',
            'slug' => 'activity-1',
        ]);
        LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '活动2',
            'slug' => 'activity-2',
        ]);

        $activities = LotteryService::getActivities(1001);
        $this->assertCount(2, $activities);
    }

    public function test_get_activities_filter_by_status(): void
    {
        LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '草稿活动',
            'slug' => 'draft-activity',
            'status' => 'draft',
        ]);
        LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '活跃活动',
            'slug' => 'active-activity',
            'status' => 'active',
        ]);

        $active = LotteryService::getActivities(1001, ['status' => 'active']);
        $this->assertCount(1, $active);
        $this->assertEquals('活跃活动', $active->first()->title);
    }

    public function test_update_activity_status(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '状态测试',
            'slug' => 'status-test',
            'status' => 'draft',
        ]);

        $updated = LotteryService::updateActivityStatus($activity->activity_id, 'active');
        $this->assertEquals('active', $updated->status);
    }

    // ---------- 奖品管理 ----------

    public function test_add_prize(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '奖品测试',
            'slug' => 'prize-test',
        ]);

        $prize = LotteryService::addPrize($activity->activity_id, [
            'name' => 'iPhone',
            'type' => 'physical',
            'value' => 999,
            'total_count' => 10,
            'weight' => 1,
        ]);

        $this->assertNotNull($prize->prize_id);
        $this->assertEquals('iPhone', $prize->name);
        $this->assertEquals(10, $prize->remaining_count);
    }

    public function test_update_prize(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '奖品更新测试',
            'slug' => 'prize-update-test',
        ]);

        $prize = LotteryService::addPrize($activity->activity_id, [
            'name' => '原奖品',
            'type' => 'virtual',
            'total_count' => 5,
            'weight' => 1,
        ]);

        $updated = LotteryService::updatePrize($prize->prize_id, [
            'name' => '新奖品',
        ]);

        $this->assertEquals('新奖品', $updated->name);
    }

    public function test_delete_prize(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '奖品删除测试',
            'slug' => 'prize-delete-test',
        ]);

        $prize = LotteryService::addPrize($activity->activity_id, [
            'name' => '待删除奖品',
            'type' => 'virtual',
            'total_count' => 1,
            'weight' => 1,
        ]);

        $result = LotteryService::deletePrize($prize->prize_id);
        $this->assertTrue($result);
    }

    public function test_get_prizes(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '奖品列表测试',
            'slug' => 'prize-list-test',
        ]);

        LotteryService::addPrize($activity->activity_id, [
            'name' => '奖品A',
            'type' => 'virtual',
            'total_count' => 1,
            'weight' => 1,
            'sort_order' => 2,
        ]);
        LotteryService::addPrize($activity->activity_id, [
            'name' => '奖品B',
            'type' => 'virtual',
            'total_count' => 1,
            'weight' => 1,
            'sort_order' => 1,
        ]);

        $prizes = LotteryService::getPrizes($activity->activity_id);
        $this->assertCount(2, $prizes);
        $this->assertEquals('奖品B', $prizes->first()->name); // sort_order=1 排前面
    }

    // ---------- 抽奖执行 ----------

    public function test_draw_win(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '中奖测试',
            'slug' => 'draw-win-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        // 添加一个权重很大的奖品，确保中奖
        $prize = LotteryService::addPrize($activity->activity_id, [
            'name' => '必中奖品',
            'type' => 'virtual',
            'value' => 10,
            'total_count' => 100,
            'weight' => 1000,
        ]);

        $result = LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $this->assertEquals('win', $result['result']);
        $this->assertNotNull($result['prize']);
        $this->assertNotNull($result['log']);
    }

    public function test_draw_miss(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '未中奖测试',
            'slug' => 'draw-miss-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        // 添加一个库存为 0 的奖品
        LotteryService::addPrize($activity->activity_id, [
            'name' => '无库存奖品',
            'type' => 'virtual',
            'total_count' => 0,
            'remaining_count' => 0,
            'weight' => 100,
        ]);

        $result = LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $this->assertEquals('miss', $result['result']);
        $this->assertNull($result['prize']);
    }

    public function test_draw_inactive_activity_throws(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '未激活活动',
            'slug' => 'inactive-test',
            'status' => 'draft',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('lottery.activity_not_active'));

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');
    }

    public function test_draw_expired_activity_throws(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '已过期活动',
            'slug' => 'expired-test',
            'status' => 'active',
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDay(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('lottery.activity_ended'));

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');
    }

    public function test_draw_not_started_activity_throws(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '未开始活动',
            'slug' => 'not-started-test',
            'status' => 'active',
            'start_at' => now()->addDay(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('lottery.activity_not_started'));

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');
    }

    public function test_draw_blacklisted_user(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '黑名单测试',
            'slug' => 'blacklist-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'user_id', '2001', '作弊');

        $result = LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $this->assertEquals('blacklist', $result['result']);
        $this->assertNull($result['prize']);
    }

    public function test_draw_blacklisted_ip(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => 'IP黑名单测试',
            'slug' => 'ip-blacklist-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'ip', '192.168.1.1', '恶意IP');

        $result = LotteryService::draw($activity->activity_id, null, '192.168.1.1', 'TestAgent');

        $this->assertEquals('blacklist', $result['result']);
    }

    public function test_draw_max_per_user_limit(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '次数限制测试',
            'slug' => 'max-per-user-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'rules' => ['max_per_user' => 1],
        ]);

        // 第一次抽奖
        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        // 第二次抽奖应该返回 miss
        $result = LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $this->assertEquals('miss', $result['result']);
    }

    public function test_draw_prize_stock_decrement(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '库存扣减测试',
            'slug' => 'stock-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $prize = LotteryService::addPrize($activity->activity_id, [
            'name' => '限量奖品',
            'type' => 'virtual',
            'total_count' => 1,
            'remaining_count' => 1,
            'weight' => 1000,
        ]);

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $prize->refresh();
        $this->assertEquals(0, $prize->remaining_count);
    }

    // ---------- 黑名单管理 ----------

    public function test_add_to_blacklist(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '黑名单添加测试',
            'slug' => 'blacklist-add-test',
        ]);

        $blacklist = LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'user_id', '2001', '作弊');

        $this->assertNotNull($blacklist->blacklist_id);
        $this->assertEquals('2001', $blacklist->identifier);
    }

    public function test_remove_from_blacklist(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '黑名单移除测试',
            'slug' => 'blacklist-remove-test',
        ]);

        LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'user_id', '2001', '作弊');
        $result = LotteryService::removeFromBlacklist($activity->activity_id, 'user_id', '2001');

        $this->assertTrue($result);
        $this->assertFalse(LotteryService::isBlacklisted($activity->activity_id, 'user_id', '2001'));
    }

    public function test_is_blacklisted(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '黑名单检查测试',
            'slug' => 'blacklist-check-test',
        ]);

        LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'user_id', '2001', '作弊');

        $this->assertTrue(LotteryService::isBlacklisted($activity->activity_id, 'user_id', '2001'));
        $this->assertFalse(LotteryService::isBlacklisted($activity->activity_id, 'user_id', '2002'));
    }

    public function test_get_blacklist(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '黑名单列表测试',
            'slug' => 'blacklist-list-test',
        ]);

        LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'user_id', '2001', '原因1');
        LotteryService::addToBlacklist($activity->tenant_id, $activity->activity_id, 'ip', '192.168.1.1', '原因2');

        $blacklist = LotteryService::getBlacklist($activity->activity_id);
        $this->assertCount(2, $blacklist);
    }

    // ---------- 统计查询 ----------

    public function test_get_draw_stats(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '统计测试',
            'slug' => 'stats-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        // 添加奖品并抽奖
        LotteryService::addPrize($activity->activity_id, [
            'name' => '测试奖品',
            'type' => 'virtual',
            'total_count' => 100,
            'weight' => 1000,
        ]);

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');
        LotteryService::draw($activity->activity_id, 2002, '127.0.0.2', 'TestAgent');

        $stats = LotteryService::getDrawStats($activity->activity_id);

        $this->assertEquals(2, $stats['total_draws']);
        $this->assertArrayHasKey('wins', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('win_rate', $stats);
    }

    public function test_get_user_draw_logs(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '用户记录测试',
            'slug' => 'user-logs-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        LotteryService::addPrize($activity->activity_id, [
            'name' => '测试奖品',
            'type' => 'virtual',
            'total_count' => 100,
            'weight' => 1000,
        ]);

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');
        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $logs = LotteryService::getUserDrawLogs($activity->activity_id, 2001);
        $this->assertCount(2, $logs);
    }

    public function test_get_win_logs(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '中奖记录测试',
            'slug' => 'win-logs-test',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        LotteryService::addPrize($activity->activity_id, [
            'name' => '测试奖品',
            'type' => 'virtual',
            'total_count' => 100,
            'weight' => 1000,
        ]);

        LotteryService::draw($activity->activity_id, 2001, '127.0.0.1', 'TestAgent');

        $winLogs = LotteryService::getWinLogs($activity->activity_id);
        $this->assertLessThanOrEqual(1, $winLogs->count());
    }

    // ---------- 租户隔离 ----------

    public function test_activities_isolated_by_tenant(): void
    {
        LotteryService::createActivity([
            'tenant_id' => 1001,
            'title' => '租户A活动',
            'slug' => 'tenant-a-activity',
        ]);

        TenantContext::setTenantId(1002);

        LotteryService::createActivity([
            'tenant_id' => 1002,
            'title' => '租户B活动',
            'slug' => 'tenant-b-activity',
        ]);

        TenantContext::setTenantId(1001);
        $activities = LotteryService::getActivities(1001);
        $this->assertCount(1, $activities);
        $this->assertEquals('租户A活动', $activities->first()->title);
    }
}
