<?php

namespace MultiTenantSaas\Services;

use MultiTenantSaas\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SystemSettingService
 *
 * 系统配置服务 - 管理平台级全局配置
 */
class SystemSettingService
{
    /**
     * 配置组常量
     */
    const GROUP_DIFY = 'dify';

    const GROUP_SYSTEM = 'system';

    const GROUP_MAIL = 'mail';

    const GROUP_CREDIT = 'credit';

    /**
     * 获取 Dify 配置
     */
    public function getDifyConfig(): array
    {
        return [
            'api_key' => SystemSetting::get(self::GROUP_DIFY, 'api_key', ''),
            'base_url' => SystemSetting::get(self::GROUP_DIFY, 'base_url', 'https://api.dify.ai'),
            'timeout' => SystemSetting::get(self::GROUP_DIFY, 'timeout', 30),
        ];
    }

    /**
     * 更新 Dify 配置
     */
    public function updateDifyConfig(array $config): void
    {
        $validator = Validator::make($config, [
            'api_key' => 'required|string|max:255',
            'base_url' => 'required|url|max:255',
            'timeout' => 'integer|min:5|max:300',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        SystemSetting::set(self::GROUP_DIFY, 'api_key', $config['api_key'], true, 'Dify API Key');
        SystemSetting::set(self::GROUP_DIFY, 'base_url', $config['base_url'], false, 'Dify API Base URL');

        if (isset($config['timeout'])) {
            SystemSetting::set(self::GROUP_DIFY, 'timeout', $config['timeout'], false, 'API 超时时间（秒）');
        }
    }

    /**
     * 测试 Dify 连接
     */
    public function testDifyConnection(?string $apiKey = null, ?string $baseUrl = null): array
    {
        $apiKey = $apiKey ?? SystemSetting::get(self::GROUP_DIFY, 'api_key');
        $baseUrl = $baseUrl ?? SystemSetting::get(self::GROUP_DIFY, 'base_url', 'https://api.dify.ai');

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => trans("common.api_key_required"),
            ];
        }

        try {
            // 测试 Dify API（这里使用假设的健康检查端点）
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                ])
                ->get("{$baseUrl}/v1/info");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => trans("common.connection_success"),
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => "连接失败: HTTP {$response->status()}",
                'error' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => trans("common.connection_failed") . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 获取系统参数配置
     */
    public function getSystemConfig(): array
    {
        return [
            'site_name' => SystemSetting::get(self::GROUP_SYSTEM, 'site_name', 'AI Agent SaaS'),
            'site_logo' => SystemSetting::get(self::GROUP_SYSTEM, 'site_logo', ''),
            'default_language' => SystemSetting::get(self::GROUP_SYSTEM, 'default_language', 'zh-CN'),
            'default_timezone' => SystemSetting::get(self::GROUP_SYSTEM, 'default_timezone', 'Asia/Shanghai'),
            'maintenance_mode' => SystemSetting::get(self::GROUP_SYSTEM, 'maintenance_mode', false),
            'maintenance_message' => SystemSetting::get(self::GROUP_SYSTEM, 'maintenance_message', trans("common.maintenance_message")),
        ];
    }

    /**
     * 更新系统参数配置
     */
    public function updateSystemConfig(array $config, bool $strictValidation = true): void
    {
        $rules = [
            'site_name' => 'required|string|max:100',
            'site_logo' => 'nullable|url|max:500',
            'default_language' => 'required|string|in:zh-CN,en-US',
            'default_timezone' => 'required|timezone',
            'maintenance_mode' => 'boolean',
            'maintenance_message' => 'nullable|string|max:500',
        ];

        // 如果不是严格验证（如导入时），只验证提供的字段
        if (! $strictValidation) {
            $filteredRules = array_filter($rules, function ($key) use ($config) {
                return array_key_exists($key, $config);
            }, ARRAY_FILTER_USE_KEY);

            // 移除 required 规则，因为是部分更新
            $filteredRules = array_map(function ($rule) {
                return str_replace('required|', '', $rule);
            }, $filteredRules);

            $validator = Validator::make($config, $filteredRules);
        } else {
            $validator = Validator::make($config, $rules);
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        foreach ($config as $key => $value) {
            SystemSetting::set(
                self::GROUP_SYSTEM,
                $key,
                $value,
                false,
                $this->getSystemConfigDescription($key)
            );
        }
    }

    /**
     * 获取邮件服务配置
     */
    public function getMailConfig(): array
    {
        return [
            'driver' => SystemSetting::get(self::GROUP_MAIL, 'driver', 'smtp'),
            'host' => SystemSetting::get(self::GROUP_MAIL, 'host', ''),
            'port' => SystemSetting::get(self::GROUP_MAIL, 'port', 587),
            'username' => SystemSetting::get(self::GROUP_MAIL, 'username', ''),
            'password' => SystemSetting::get(self::GROUP_MAIL, 'password', ''),
            'encryption' => SystemSetting::get(self::GROUP_MAIL, 'encryption', 'tls'),
            'from_address' => SystemSetting::get(self::GROUP_MAIL, 'from_address', ''),
            'from_name' => SystemSetting::get(self::GROUP_MAIL, 'from_name', 'AI Agent SaaS'),
        ];
    }

    /**
     * 更新邮件服务配置
     */
    public function updateMailConfig(array $config): void
    {
        $validator = Validator::make($config, [
            'driver' => 'required|string|in:smtp,sendmail,mailgun',
            'host' => 'required_if:driver,smtp|string|max:255',
            'port' => 'required_if:driver,smtp|integer|min:1|max:65535',
            'username' => 'required_if:driver,smtp|string|max:255',
            'password' => 'required_if:driver,smtp|string|max:255',
            'encryption' => 'required_if:driver,smtp|string|in:tls,ssl',
            'from_address' => 'required|email|max:255',
            'from_name' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        foreach ($config as $key => $value) {
            if ($key === 'password' && $value === '********') {
                continue;
            }
            $isEncrypted = in_array($key, ['password']);
            SystemSetting::set(
                self::GROUP_MAIL,
                $key,
                $value,
                $isEncrypted,
                $this->getMailConfigDescription($key)
            );
        }
    }

    /**
     * 测试邮件发送
     */
    public function testMailConnection(string $testEmail): array
    {
        try {
            Mail::raw('这是一封测试邮件，用于验证邮件服务配置是否正确。', function ($message) use ($testEmail) {
                $message->to($testEmail)
                    ->subject('邮件服务测试');
            });

            return [
                'success' => true,
                'message' => trans("common.test_email_sent"),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => trans("common.email_send_failed") . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 获取积分价格配置
     */
    public function getCreditConfig(): array
    {
        return [
            'recharge_prices' => SystemSetting::get(self::GROUP_CREDIT, 'recharge_prices', [
                ['amount' => 100, 'price' => 10],
                ['amount' => 500, 'price' => 45],
                ['amount' => 1000, 'price' => 80],
                ['amount' => 5000, 'price' => 350],
            ]),
            'token_rate' => SystemSetting::get(self::GROUP_CREDIT, 'token_rate', [
                'input' => 1,    // 1 积分 = 1000 input tokens
                'output' => 2,   // 1 积分 = 500 output tokens
            ]),
            'plan_prices' => SystemSetting::get(self::GROUP_CREDIT, 'plan_prices', [
                'basic' => ['monthly' => 99, 'yearly' => 999],
                'professional' => ['monthly' => 299, 'yearly' => 2999],
                'enterprise' => ['monthly' => 999, 'yearly' => 9999],
            ]),
        ];
    }

    /**
     * 更新积分价格配置
     */
    public function updateCreditConfig(array $config): void
    {
        $validator = Validator::make($config, [
            'recharge_prices' => 'required|array|min:1',
            'recharge_prices.*.amount' => 'required|integer|min:1',
            'recharge_prices.*.price' => 'required|numeric|min:0',
            'token_rate' => 'required|array',
            'token_rate.input' => 'required|numeric|min:0.01',
            'token_rate.output' => 'required|numeric|min:0.01',
            'plan_prices' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        foreach ($config as $key => $value) {
            SystemSetting::set(
                self::GROUP_CREDIT,
                $key,
                $value,
                false,
                $this->getCreditConfigDescription($key)
            );
        }
    }

    /**
     * 获取所有配置（用于导出）
     */
    public function getAllConfig(): array
    {
        return [
            'dify' => $this->getDifyConfig(),
            'system' => $this->getSystemConfig(),
            'mail' => $this->getMailConfig(),
            'credit' => $this->getCreditConfig(),
        ];
    }

    /**
     * 批量导入配置
     */
    public function importConfig(array $config): void
    {
        if (isset($config['dify'])) {
            $this->updateDifyConfig($config['dify']);
        }

        if (isset($config['system'])) {
            $this->updateSystemConfig($config['system'], false);  // 导入时不严格验证
        }

        if (isset($config['mail'])) {
            $this->updateMailConfig($config['mail']);
        }

        if (isset($config['credit'])) {
            $this->updateCreditConfig($config['credit']);
        }
    }

    /**
     * 获取系统配置说明
     */
    private function getSystemConfigDescription(string $key): string
    {
        $descriptions = [
            'site_name' => '网站名称',
            'site_logo' => '网站Logo URL',
            'default_language' => '默认语言',
            'default_timezone' => '默认时区',
            'maintenance_mode' => '维护模式',
            'maintenance_message' => '维护提示信息',
        ];

        return $descriptions[$key] ?? '';
    }

    /**
     * 获取邮件配置说明
     */
    private function getMailConfigDescription(string $key): string
    {
        $descriptions = [
            'driver' => '邮件驱动',
            'host' => 'SMTP 服务器',
            'port' => 'SMTP 端口',
            'username' => 'SMTP 用户名',
            'password' => 'SMTP 密码',
            'encryption' => '加密方式',
            'from_address' => '发件人邮箱',
            'from_name' => '发件人名称',
        ];

        return $descriptions[$key] ?? '';
    }

    /**
     * 获取积分配置说明
     */
    private function getCreditConfigDescription(string $key): string
    {
        $descriptions = [
            'recharge_prices' => '积分充值价格表',
            'token_rate' => 'Token 积分兑换比例',
            'plan_prices' => '套餐价格配置',
        ];

        return $descriptions[$key] ?? '';
    }
}
