<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Agent\Tools;

use Illuminate\Support\Facades\Validator;
use MultiTenantSaas\Contracts\ToolContract;

class ValidationTool implements ToolContract
{
    public function name(): string
    {
        return 'validation';
    }

    public function description(): string
    {
        return '数据验证';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $data = $params['data'] ?? [];
        $rules = $params['rules'] ?? [];

        if (empty($data) || empty($rules)) {
            return ['error' => 'Data and rules required'];
        }

        $validator = Validator::make($data, $rules);

        return [
            'valid' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }
}
