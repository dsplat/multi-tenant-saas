<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Channels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Support\Messaging\MarkdownAdapter;

/**
 * Telegram 频道（Bot API）
 *
 * 默认 long polling（getUpdates，无需公网回调），生产可切 webhook。
 * 凭证：credentials.bot_token（必填）、credentials.bot_username（生成 t.me 绑定链接用）。
 *
 * 约束（docs/ibot.md 第二节）：每个 Bot Token 同时只允许一个活跃轮询器；
 * 出向短消息优先 parse_mode=HTML 渲染（失败回退纯文本重发），
 * 超长消息按 4096 字符上限纯文本自动分段（避免 HTML 标签被切断）。
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

        // 短消息走 HTML 渲染；超长文本直接纯文本分段，避免标签跨段被切断
        $html = MarkdownAdapter::toTelegramHtml($text);

        if (mb_strlen($html) <= self::CHUNK_SIZE) {
            if ($this->sendChunk($ibot, $token, $externalId, $html, 'HTML')) {
                return true;
            }

            // HTML 被拒（如标签不合法）时回退纯文本重发一次
            return $this->sendChunk($ibot, $token, $externalId, MarkdownAdapter::toPlain($text));
        }

        $ok = true;

        foreach ($this->splitText(MarkdownAdapter::toPlain($text)) as $chunk) {
            $ok = $this->sendChunk($ibot, $token, $externalId, $chunk) && $ok;
        }

        return $ok;
    }

    /**
     * 发送单段消息（$parseMode 为 null 时纯文本）
     */
    private function sendChunk(Ibot $ibot, string $token, string $externalId, string $text, ?string $parseMode = null): bool
    {
        $payload = [
            'chat_id' => $externalId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        $response = $this->http(15)->post($this->apiUrl($token, 'sendMessage'), $payload);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('[Ibot] Telegram sendMessage 失败', [
                'ibot_id' => $ibot->ibot_id,
                'parse_mode' => $parseMode,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return false;
        }

        return true;
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

        $response = $this->http($timeout + 10)->get($this->apiUrl($token, 'getUpdates'), [
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
     * 构造 HTTP 客户端（支持可选出站代理，仅作用于 Telegram API）
     *
     * 国内服务器直连 api.telegram.org 不通时，配置 AI_IBOT_TELEGRAM_PROXY
     * 指向本地 SOCKS5/HTTP 代理（如 socks5h://127.0.0.1:1080）。
     */
    private function http(int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout);

        $proxy = config('ai.ibot.telegram.proxy');

        if ($proxy) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
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
