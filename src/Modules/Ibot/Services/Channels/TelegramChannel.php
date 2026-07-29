<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;

/**
 * Telegram 频道（Bot API）
 *
 * 默认 long polling（getUpdates，无需公网回调），生产可切 webhook。
 * 凭证：credentials.bot_token（必填）、credentials.bot_username（生成 t.me 绑定链接用）。
 *
 * 约束（docs/ibot.md 第二节）：每个 Bot Token 同时只允许一个活跃轮询器；
 * 出向消息按 4096 字符上限自动分段。
 */
class TelegramChannel implements IbotChannelContract
{
    // Telegram 单条消息上限 4096，留余量避免多字节边界问题
    private const CHUNK_SIZE = 4000;

    public function parseInbound(Ibot $ibot, array $payload): ?IbotInboundMessage
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = $message['text'] ?? null;

        if ($chatId === null || ! is_string($text) || trim($text) === '') {
            return null;
        }

        return new IbotInboundMessage(
            externalId: (string) $chatId,
            text: trim($text),
            messageId: isset($message['message_id']) ? (string) $message['message_id'] : null,
            raw: $payload,
        );
    }

    public function sendMessage(Ibot $ibot, string $externalId, string $text): bool
    {
        $token = $ibot->credentials['bot_token'] ?? null;

        if (! $token || trim($text) === '') {
            return false;
        }

        $ok = true;

        foreach ($this->splitText($text) as $chunk) {
            $response = Http::timeout(15)->post($this->apiUrl($token, 'sendMessage'), [
                'chat_id' => $externalId,
                'text' => $chunk,
            ]);

            if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                Log::warning('[Ibot] Telegram sendMessage 失败', [
                    'ibot_id' => $ibot->ibot_id,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * 拉取更新（long polling）
     *
     * @return array{updates: array, ok: bool}
     */
    public function getUpdates(Ibot $ibot, int $offset = 0, int $timeout = 30): array
    {
        $token = $ibot->credentials['bot_token'] ?? null;

        if (! $token) {
            return ['updates' => [], 'ok' => false];
        }

        $response = Http::timeout($timeout + 10)->get($this->apiUrl($token, 'getUpdates'), [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => json_encode(['message']),
        ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('[Ibot] Telegram getUpdates 失败', [
                'ibot_id' => $ibot->ibot_id,
                'status' => $response->status(),
            ]);

            return ['updates' => [], 'ok' => false];
        }

        return ['updates' => $response->json('result') ?? [], 'ok' => true];
    }

    /**
     * 构造绑定链接（做成二维码供 operator 扫码）
     */
    public function bindLink(Ibot $ibot, string $bindCode): ?string
    {
        $username = $ibot->credentials['bot_username'] ?? null;

        return $username ? "https://t.me/{$username}?start={$bindCode}" : null;
    }

    private function apiUrl(string $token, string $method): string
    {
        $base = rtrim(config('ai.ibot.telegram.api_base', 'https://api.telegram.org'), '/');

        return "{$base}/bot{$token}/{$method}";
    }

    /**
     * @return array<string>
     */
    private function splitText(string $text): array
    {
        if (mb_strlen($text) <= self::CHUNK_SIZE) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > 0) {
            $chunks[] = mb_substr($remaining, 0, self::CHUNK_SIZE);
            $remaining = mb_substr($remaining, self::CHUNK_SIZE);
        }

        return $chunks;
    }
}
