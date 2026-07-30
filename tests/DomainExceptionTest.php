<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Exceptions\ConflictException;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\InsufficientCreditsException;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Exceptions\PermissionDeniedException;
use MultiTenantSaas\Exceptions\QuotaExceededException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Exceptions\StorageException;
use MultiTenantSaas\Exceptions\SummaryGenerationException;
use MultiTenantSaas\Exceptions\TenantNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 领域异常体系测试 — 状态码映射与继承契约
 */
class DomainExceptionTest extends TestCase
{
    public function test_domain_exception_defaults_to_422(): void
    {
        $e = new DomainException('业务错误');

        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame([], $e->getHeaders());
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertInstanceOf(HttpExceptionInterface::class, $e);
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function test_domain_exceptions_carry_expected_status_codes(string $class, int $expected): void
    {
        $e = new $class('msg');

        $this->assertInstanceOf(DomainException::class, $e);
        $this->assertSame($expected, $e->getStatusCode());
    }

    public static function statusCodeProvider(): array
    {
        return [
            'not found' => [NotFoundException::class, 404],
            'conflict' => [ConflictException::class, 409],
            'service unavailable' => [ServiceUnavailableException::class, 503],
            'quota exceeded' => [QuotaExceededException::class, 429],
            'permission denied' => [PermissionDeniedException::class, 403],
            'tenant not found' => [TenantNotFoundException::class, 404],
            'storage' => [StorageException::class, 500],
            'summary generation' => [SummaryGenerationException::class, 502],
        ];
    }

    public function test_insufficient_credits_exception_keeps_error_code_and_402(): void
    {
        $e = new InsufficientCreditsException;

        $this->assertSame(402, $e->getStatusCode());
        $this->assertSame(\MultiTenantSaas\Enums\ErrorCode::InsufficientCredits, $e->errorCode);
    }

    public function test_domain_exception_renders_json_with_status_code(): void
    {
        \Illuminate\Support\Facades\Route::get('/api/_domain_exception_test', function () {
            throw new NotFoundException('资源不存在');
        });

        // HttpExceptionInterface 保证无需 render 回调也按异常声明的状态码渲染
        // （统一 success/message 结构由应用层 bootstrap/app.php 的 render 回调补充）
        $this->getJson('/api/_domain_exception_test')
            ->assertStatus(404)
            ->assertJsonFragment([
                'message' => '资源不存在',
            ]);
    }
}
