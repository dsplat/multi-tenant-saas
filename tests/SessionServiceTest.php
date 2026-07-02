<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\UserSession;
use MultiTenantSaas\Services\SessionService;
use MultiTenantSaas\Tests\Schema\SecurityModule;
use MultiTenantSaas\Tests\Schema\ChannelModule;

/**
 * TASK-015 SessionService 单元测试
 *
 * 覆盖：会话记录、设备指纹、活跃会话列表、强制下线（单个/全部）、
 *       异常登录检测（新设备/新IP）、会话超时配置、过期清理
 */
class SessionServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class, SecurityModule::class];

    private SessionService $service;

    private int $userId = 2001;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            User::create([
                'user_id' => $this->userId,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId('1001');

        $this->service = app(SessionService::class);
    }

    /**
     * 插入一条 personal_access_tokens 记录，返回其 id
     */
    private function createToken(int $userId): int
    {
        return DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class,
            'tokenable_id' => $userId,
            'name' => 'auth-token',
            'token' => uniqid('tok_', true),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------- 设备指纹 ----------

    public function test_fingerprint_is_deterministic(): void
    {
        $fp1 = $this->service->generateFingerprint('Mozilla/5.0', '127.0.0.1');
        $fp2 = $this->service->generateFingerprint('Mozilla/5.0', '127.0.0.1');

        $this->assertSame($fp1, $fp2);
        $this->assertSame(64, strlen($fp1));
    }

    public function test_fingerprint_differs_by_ua_or_ip(): void
    {
        $fp1 = $this->service->generateFingerprint('Mozilla/5.0', '127.0.0.1');
        $fp2 = $this->service->generateFingerprint('curl/8.0', '127.0.0.1');
        $fp3 = $this->service->generateFingerprint('Mozilla/5.0', '192.168.0.1');

        $this->assertNotEquals($fp1, $fp2);
        $this->assertNotEquals($fp1, $fp3);
    }

    // ---------- 会话记录 ----------

    public function test_record_session_creates_session(): void
    {
        $tokenId = $this->createToken($this->userId);

        $session = $this->service->recordSession(
            $this->userId,
            $tokenId,
            '127.0.0.1',
            'Mozilla/5.0'
        );

        $this->assertInstanceOf(UserSession::class, $session);
        $this->assertSame($this->userId, $session->user_id);
        $this->assertSame($tokenId, $session->token_id);
        $this->assertSame('127.0.0.1', $session->ip_address);
        $this->assertNotEmpty($session->device_fingerprint);
        $this->assertNotNull($session->login_at);
    }

    public function test_first_session_is_anomalous(): void
    {
        $tokenId = $this->createToken($this->userId);

        $session = $this->service->recordSession($this->userId, $tokenId, '10.0.0.1', 'UA-A');

        // 新设备 + 新 IP → 异常
        $this->assertTrue($session->is_anomalous);
    }

    // ---------- 异常检测 ----------

    public function test_detect_anomaly_new_device_and_ip(): void
    {
        $tokenId = $this->createToken($this->userId);
        $this->service->recordSession($this->userId, $tokenId, '10.0.0.1', 'UA-A');

        $result = $this->service->detectAnomaly(
            $this->userId,
            $this->service->generateFingerprint('UA-A', '10.0.0.1'),
            '10.0.0.1'
        );

        $this->assertFalse($result['new_device']);
        $this->assertFalse($result['new_ip']);
    }

    public function test_detect_anomaly_new_ip_same_device(): void
    {
        $tokenId = $this->createToken($this->userId);
        $this->service->recordSession($this->userId, $tokenId, '10.0.0.1', 'UA-A');

        // 同设备(UA)不同 IP：指纹不同（含 IP），视为新设备
        $result = $this->service->detectAnomaly(
            $this->userId,
            $this->service->generateFingerprint('UA-A', '10.0.0.2'),
            '10.0.0.2'
        );

        $this->assertTrue($result['new_ip']);
    }

    public function test_list_anomalous_sessions(): void
    {
        $t1 = $this->createToken($this->userId);
        $t2 = $this->createToken($this->userId);

        $this->service->recordSession($this->userId, $t1, '10.0.0.1', 'UA-A');
        // 第二次相同设备+IP → 不再异常
        $this->service->recordSession($this->userId, $t2, '10.0.0.1', 'UA-A');

        $anomalous = $this->service->listAnomalousSessions($this->userId);

        $this->assertCount(1, $anomalous);
    }

    // ---------- 活跃会话列表 ----------

    public function test_list_sessions(): void
    {
        $t1 = $this->createToken($this->userId);
        $t2 = $this->createToken($this->userId);

        $this->service->recordSession($this->userId, $t1, '10.0.0.1', 'UA-A');
        $this->service->recordSession($this->userId, $t2, '10.0.0.2', 'UA-B');

        $sessions = $this->service->listSessions($this->userId);

        $this->assertCount(2, $sessions);
    }

    // ---------- 强制下线 ----------

    public function test_revoke_session_deletes_token_and_session(): void
    {
        $tokenId = $this->createToken($this->userId);
        $session = $this->service->recordSession($this->userId, $tokenId, '10.0.0.1', 'UA-A');

        $ok = $this->service->revokeSession($this->userId, $session->user_session_id);

        $this->assertTrue($ok);
        $this->assertSame(0, UserSession::where('user_id', $this->userId)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('id', $tokenId)->count());
    }

    public function test_revoke_session_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->revokeSession($this->userId, 999999));
    }

    public function test_revoke_all_sessions_excludes_current(): void
    {
        $t1 = $this->createToken($this->userId);
        $t2 = $this->createToken($this->userId);
        $t3 = $this->createToken($this->userId);

        $this->service->recordSession($this->userId, $t1, '10.0.0.1', 'UA-A');
        $this->service->recordSession($this->userId, $t2, '10.0.0.2', 'UA-B');
        $this->service->recordSession($this->userId, $t3, '10.0.0.3', 'UA-C');

        $count = $this->service->revokeAllSessions($this->userId, $t3);

        $this->assertSame(2, $count);
        // 保留当前会话
        $this->assertSame(1, UserSession::where('user_id', $this->userId)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('id', $t3)->count());
        // 其他 token 已删除
        $this->assertSame(0, DB::table('personal_access_tokens')->where('id', $t1)->count());
    }

    public function test_revoke_all_sessions_without_except(): void
    {
        $t1 = $this->createToken($this->userId);
        $t2 = $this->createToken($this->userId);

        $this->service->recordSession($this->userId, $t1, '10.0.0.1', 'UA-A');
        $this->service->recordSession($this->userId, $t2, '10.0.0.2', 'UA-B');

        $count = $this->service->revokeAllSessions($this->userId);

        $this->assertSame(2, $count);
        $this->assertSame(0, UserSession::where('user_id', $this->userId)->count());
    }

    // ---------- 活跃时间更新 ----------

    public function test_update_activity(): void
    {
        $tokenId = $this->createToken($this->userId);
        $session = $this->service->recordSession($this->userId, $tokenId, '10.0.0.1', 'UA-A');

        $original = $session->last_active_at;

        sleep(1);
        $this->service->updateActivity($tokenId);

        $session->refresh();
        $this->assertGreaterThan($original, $session->last_active_at);
    }

    // ---------- 会话超时 ----------

    public function test_session_timeout_default(): void
    {
        $this->assertSame(60, $this->service->getSessionTimeout());
    }

    public function test_session_timeout_configurable(): void
    {
        $this->service->setSessionTimeout(30);

        $this->assertSame(30, $this->service->getSessionTimeout());
    }

    // ---------- 过期清理 ----------

    public function test_purge_expired_sessions(): void
    {
        $t1 = $this->createToken($this->userId);
        $t2 = $this->createToken($this->userId);

        // 过期会话：活跃时间在 2 小时前
        $expired = $this->service->recordSession($this->userId, $t1, '10.0.0.1', 'UA-A');
        UserSession::where('user_session_id', $expired->user_session_id)->update([
            'last_active_at' => now()->subHours(2),
        ]);

        // 活跃会话
        $this->service->recordSession($this->userId, $t2, '10.0.0.2', 'UA-B');

        $count = $this->service->purgeExpiredSessions();

        $this->assertSame(1, $count);
        $this->assertSame(1, UserSession::where('user_id', $this->userId)->count());
        // 过期会话对应 token 已删除
        $this->assertSame(0, DB::table('personal_access_tokens')->where('id', $t1)->count());
    }
}
