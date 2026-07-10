<?php

namespace MultiTenantSaas\Contracts;

interface ChannelContract
{
    public function onMessage(array $rawMessage): array;

    public function sendMessage(string $conversationId, array $message): bool;

    public function getParticipants(string $conversationId): array;

    public function getConversationInfo(string $conversationId): array;
}
