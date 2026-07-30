<?php

namespace MultiTenantSaas\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 领域异常基类 — 业务错误统一携带 HTTP 状态码
 *
 * 实现 HttpExceptionInterface：即使未被 bootstrap/app.php 的
 * render 回调显式拦截，Laravel 默认渲染也会按 getStatusCode()
 * 返回，生产环境 <500 的业务错误不会被兜底 500 吞掉。
 *
 * 模块领域异常应继承本类并声明 $statusCode（默认 422），
 * 逐步替代裸 \RuntimeException（后者按未知异常 500 处理）。
 * 通用场景可直接使用 NotFoundException / ConflictException /
 * ServiceUnavailableException。
 */
class DomainException extends RuntimeException implements HttpExceptionInterface
{
    /** 业务错误默认 422 Unprocessable Entity */
    protected int $statusCode = 422;

    /** @var array<string, string> */
    protected array $headers = [];

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
