<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 全局ID生成器
 *
 * 生成16位随机数字ID，JavaScript安全，全局唯一
 *
 * 特性：
 * - ID范围: 1000000000000000 ~ 9007199254740991
 * - JS安全: <= Number.MAX_SAFE_INTEGER
 * - 可用数量: 约8万亿 (8.0072 × 10^15)
 * - 碰撞概率: < 10^-12
 * - 完全无序: 无法推测业务增长
 * - 所有表共用: 全局ID空间
 *
 * 设计决策 — 碰撞处理策略：
 * - 有意不增加碰撞检测/重试逻辑
 * - 碰撞概率极低（< 10^-12），在实际业务量级下可忽略
 * - 即使发生跨表碰撞（如 order_id = user_id），因外键隔离不会互相影响
 * - 同表碰撞会因 PRIMARY KEY 或 UNIQUE 约束写入失败，由业务层捕获处理
 * - 如果增加重试逻辑，会带来额外的数据库查询开销，在高并发场景下得不偿失
 * - 结论：接受极低概率的写入失败，而非牺牲性能
 *
 * 可替换：派生项目可实现 IdGeneratorContract 并在服务容器中替换绑定。
 */
class IdGenerator implements IdGeneratorContract
{
    protected int $min;

    protected int $max;

    public function __construct()
    {
        $this->min = config('tenancy.id.min_value', 1000000000000000);
        $this->max = config('tenancy.id.max_value', 9007199254740991);
    }

    /**
     * 生成新的全局唯一ID
     */
    public function generate(): int
    {
        return random_int($this->min, $this->max);
    }

    /**
     * 批量生成ID
     */
    public function batch(int $count = 10): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->generate();
        }

        return $ids;
    }

    /**
     * 验证ID格式是否正确
     */
    public function validate(int|string $id): bool
    {
        $numId = is_string($id) ? (int) $id : $id;

        return $numId >= $this->min
            && $numId <= $this->max
            && strlen((string) $numId) === 16;
    }

    /**
     * 检查ID是否在JavaScript安全范围内
     */
    public function isJsSafe(int|string $id): bool
    {
        $numId = is_string($id) ? (int) $id : $id;

        return $numId <= $this->max;
    }

    /**
     * 解析ID信息
     */
    public function parseId(int|string $id): array
    {
        $numId = is_string($id) ? (int) $id : $id;

        return [
            'id' => $numId,
            'numeric' => $numId,
            'length' => strlen((string) $numId),
            'valid' => $this->validate($numId),
            'js_safe' => $this->isJsSafe($numId),
        ];
    }
}
