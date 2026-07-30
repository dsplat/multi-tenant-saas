<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\DTOs\InboundMessage;

/**
 * 入向消息拉取策略（可插拔）
 *
 * 外部客户消息的接收有两种路径：
 * - 客服（kf）：回调仅通知，需调 kf/sync_msg 拉取实际消息（普惠，本期实现）
 * - 会话存档：定时拉取全量消息并解密（付费，大企业，后续接入）
 *
 * 按租户配置 receive_strategy=kf|archive 选择（默认 kf）。
 */
interface InboundFetcherContract
{
    /**
     * 根据回调通知拉取实际入向消息。
     *
     * @param  array<string, mixed>  $notification  解密后的回调通知内容
     * @return list<InboundMessage>
     */
    public function fetch(array $notification): array;
}
