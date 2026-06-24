<?php

namespace MultiTenantSaas\Contracts;

/**
 * ID 生成器接口契约
 *
 * 派生项目可实现此接口以替换默认的 ID 生成策略（如 UUID、雪花算法等）。
 * 通过服务容器绑定 IdGeneratorContract::class 即可替换实现。
 */
interface IdGeneratorContract
{
    /**
     * 生成新的全局唯一 ID
     */
    public function generate(): int;

    /**
     * 批量生成 ID
     *
     * @return int[]
     */
    public function batch(int $count = 10): array;

    /**
     * 验证 ID 格式是否正确
     */
    public function validate(int|string $id): bool;

    /**
     * 检查 ID 是否在 JavaScript 安全范围内
     */
    public function isJsSafe(int|string $id): bool;

    /**
     * 解析 ID 信息
     *
     * @return array{id: int, numeric: int, length: int, valid: bool, js_safe: bool}
     */
    public function parseId(int|string $id): array;
}
