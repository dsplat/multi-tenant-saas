<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Contracts\ToolContract;

class EncryptionTool implements ToolContract
{
    public function name(): string
    {
        return 'encryption';
    }

    public function description(): string
    {
        return '加密解密';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $action = $params['action'] ?? 'encrypt';
        $data = $params['data'] ?? '';

        try {
            return match ($action) {
                'encrypt' => ['result' => Crypt::encryptString($data)],
                'decrypt' => ['result' => Crypt::decryptString($data)],
                'hash' => ['result' => hash($params['algorithm'] ?? 'sha256', $data)],
                default => ['error' => 'Unknown action'],
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
