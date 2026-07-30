<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;

/**
 * 渠道驱动契约（Channel Provider）
 *
 * 每个外部 IM 平台/协议一个实现（企微自建应用、企微客服、公众号、Slack…）。
 * 框架 Channel 抽象层处理「接收 -> 解析 -> 存储 -> 事件」全链路，
 * 驱动只负责平台协议本身：验签、解密、解析入向消息、按会话发送。
 *
 * 与 Ibot 的 IbotChannelContract 无继承关系——ibot 服务 Operator（agent_conversations），
 * Channel 服务 User（conversations），两套独立体系，仅共享 Support 平台 SDK。
 */
interface ChannelContract
{
    /** 渠道类型标识，如 enterprise_wechat_app / enterprise_wechat_kf */
    public function type(): string;

    /**
     * URL 验证（GET）：验签 + 解密 echostr，成功返回明文，失败返回 null。
     * 企微要求原样回显明文 echostr。
     *
     * @param  array<string, mixed>  $query
     */
    public function verifyUrl(array $query): ?string;

    /**
     * 消息回调验签（POST）。
     *
     * @param  array<string, mixed>  $query  查询参数（msg_signature/timestamp/nonce 等）
     * @param  string  $rawBody  原始请求体（XML/JSON）
     */
    public function verifySignature(array $query, string $rawBody): bool;

    /**
     * 从原始回调体解析出归一化入向消息列表。
     *
     * 返回数组（0~N 条）：
     * - 自建应用：回调即消息，返回 0 或 1 条
     * - 客服：回调仅通知，驱动内部调 sync_msg 拉取，可能多条
     * - 非消息事件（关注/进入会话等）或不支持的类型返回空数组（控制器直接 ACK）
     *
     * @param  array<string, mixed>  $query
     * @return list<InboundMessage>
     */
    public function parseInbound(string $rawBody, array $query): array;

    /**
     * 向会话发送消息（驱动按会话类型/元数据路由到 message/send、appchat、kf 等）。
     *
     * @param  array<string, mixed>  $message
     */
    public function sendMessage(Conversation $conversation, array $message): bool;
}
