<?php

namespace MultiTenantSaas\Exceptions;

/**
 * AI 摘要生成失败（上游模型异常）→ 502
 */
class SummaryGenerationException extends DomainException
{
    protected int $statusCode = 502;
}
