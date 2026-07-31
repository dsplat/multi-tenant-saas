<?php

namespace MultiTenantSaas\Contracts;

/**
 * 工作脉络资产探测器契约（项目大脑 Phase 2b）
 *
 * 下游模块实现本契约，声明某类锚点对象（anchor_type）上可探测的
 * 关联资产与对象完整度事实（如：活动是否已关联海报/优惠券/群发，
 * 活动字段场地/票种/报名截止是否为空）。
 *
 * thread_review 聚合全部探测结果供 LLM 推理遗漏——探测器只返回事实，
 * 不做规则判断（遗漏判断交给 LLM 结合能力图谱推理，保持灵活性）。
 *
 * 注册方式：实现类加入 config('ai.brain.asset_probes') 类名列表
 * （下游 ServiceProvider 追加，与 extra_chain_classes 扩展模式一致）。
 */
interface ThreadAssetProbeContract
{
    /**
     * 本探测器是否适用于该锚点类型
     */
    public function supports(string $anchorType): bool;

    /**
     * 探测锚点对象的关联资产与完整度事实
     *
     * @return array<string, mixed> 事实键值对（如 ['poster' => ['linked' => false], ...]）
     */
    public function probe(string $anchorType, int $anchorId, int $tenantId): array;
}
