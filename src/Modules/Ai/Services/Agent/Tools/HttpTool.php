<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Contracts\ToolContract;

class HttpTool implements ToolContract
{
    public function name(): string
    {
        return 'http';
    }

    public function description(): string
    {
        return 'HTTP 请求';
    }

    public function category(): string
    {
        return 'core';
    }

    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
    private const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '10.', '172.16.', '192.168.'];

    public function execute(array $params): mixed
    {
        $method = strtoupper($params['method'] ?? 'GET');
        $url = $params['url'] ?? '';
        $data = $params['data'] ?? [];
        $headers = $params['headers'] ?? [];
        $timeout = min((int) ($params['timeout'] ?? 30), 60);

        if (empty($url)) {
            return ['error' => 'URL required'];
        }

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return ['error' => 'Method not allowed'];
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            return ['error' => 'Invalid URL'];
        }

        if (!in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            return ['error' => 'Only HTTP/HTTPS allowed'];
        }

        $host = $parsedUrl['host'];
        foreach (self::BLOCKED_HOSTS as $blocked) {
            if ($host === $blocked || str_starts_with($host, $blocked)) {
                return ['error' => 'Blocked host'];
            }
        }

        try {
            $response = match ($method) {
                'POST' => Http::withHeaders($headers)->timeout($timeout)->post($url, $data),
                'PUT' => Http::withHeaders($headers)->timeout($timeout)->put($url, $data),
                'DELETE' => Http::withHeaders($headers)->timeout($timeout)->delete($url),
                'PATCH' => Http::withHeaders($headers)->timeout($timeout)->patch($url, $data),
                default => Http::withHeaders($headers)->timeout($timeout)->get($url, $data),
            };

            return [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'success' => $response->successful(),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
