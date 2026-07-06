<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

class SlackSignatureValidator
{
    public function __construct(
        protected string $signingSecret,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function validate(array $headers, string $rawBody): bool
    {
        $signature = $headers['x-slack-signature'] ?? '';
        $timestamp = (int) ($headers['x-slack-request-timestamp'] ?? 0);

        if ($signature === '' || $timestamp === 0) {
            return false;
        }

        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $baseString = 'v0:' . $timestamp . ':' . $rawBody;
        $computed = 'v0=' . hash_hmac('sha256', $baseString, $this->signingSecret);

        return hash_equals($computed, $signature);
    }
}
