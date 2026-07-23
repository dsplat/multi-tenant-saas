<?php

namespace MultiTenantSaas\Modules\Billing\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Billing\Models\TaxRule;

/**
 * 税务服务
 *
 * 提供多地区税率计算与税号校验能力，支持 CN/US/EU/UK 四个地区。
 *
 * - 税率规则优先从 tax_rules 表按生效日期选取，无记录时回退到 config(pay.invoice.tax_rules) 或内置默认值
 * - 中国(CN)税率按商品类型区分：13%(标准) / 9%(低税率) / 6%(现代服务) / 0%(免税/出口)
 * - 税号校验覆盖：中国税号(15/18/20位)、欧盟 VAT、英国 VAT、美国 EIN
 */
class TaxService
{
    /** 支持的地区列表 */
    public const SUPPORTED_REGIONS = ['CN', 'US', 'EU', 'UK'];

    /** 内置默认税率配置（当 tax_rules 表与 config 均无记录时使用） */
    protected const DEFAULT_RATE_CONFIG = [
        'CN' => ['rate' => 0.13, 'name' => '增值税'],
        'US' => ['rate' => 0.07, 'name' => 'Sales Tax'],
        'EU' => ['rate' => 0.20, 'name' => 'VAT'],
        'UK' => ['rate' => 0.20, 'name' => 'VAT'],
    ];

    /** 按商品类型区分的税率（仅 CN 存在多档税率） */
    protected const PRODUCT_RATES = [
        'CN' => [
            'standard' => 0.13,       // 标准税率
            'goods' => 0.13,          // 货物销售
            'food' => 0.09,           // 部分农产品/食品
            'agriculture' => 0.09,    // 农业产品
            'transport' => 0.09,      // 交通运输
            'service' => 0.06,        // 现代服务
            'modern_service' => 0.06, // 现代服务业
            'finance' => 0.06,        // 金融业
            'export' => 0.00,         // 出口零税率
            'exempt' => 0.00,         // 免税
        ],
    ];

    public function __construct(private readonly TenantContextContract $tenantContext) {}

    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 计算税额
     *
     * @param  string  $region  地区代码（CN/US/EU/UK）
     * @param  float  $amount  税前金额
     * @param  string|null  $productType  商品类型（影响 CN 多档税率）
     * @return array{tax_rate: float, tax_amount: float, total: float, is_exempt: bool}
     */
    public function calculateTax(string $region, float $amount, ?string $productType = null): array
    {
        $region = strtoupper($region);

        if (! $this->isSupportedRegion($region)) {
            throw new \RuntimeException(trans('payment.tax_region_unsupported'));
        }

        if ($this->isExempt($region, $productType)) {
            return [
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'total' => round($amount, 2),
                'is_exempt' => true,
            ];
        }

        $rate = $this->resolveRate($region, $productType);
        $taxAmount = round($amount * $rate, 2);
        $total = round($amount + $taxAmount, 2);

        return [
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'is_exempt' => false,
        ];
    }

    /**
     * 校验税号格式
     *
     * @param  string  $region  地区代码（CN/US/EU/UK）
     * @param  string  $taxNumber  税号
     */
    public function validateTaxNumber(string $region, string $taxNumber): bool
    {
        $region = strtoupper($region);
        $taxNumber = strtoupper(trim($taxNumber));

        return match ($region) {
            'CN' => $this->validateChineseTaxNumber($taxNumber),
            'EU' => $this->validateEuVatNumber($taxNumber),
            'UK' => $this->validateUkVatNumber($taxNumber),
            'US' => $this->validateUsEinNumber($taxNumber),
            default => throw new \RuntimeException(trans('payment.tax_region_unsupported')),
        };
    }

    /**
     * 获取适用税率规则（按生效日期选取）
     *
     * 优先查询 tax_rules 表中 region_code 匹配且在生效期内的最新规则；
     * 无记录时回退到 config(pay.invoice.tax_rules) 或内置默认值构建的规则对象。
     *
     * @param  string  $region  地区代码
     * @param  Carbon|null  $date  生效日期，默认当前
     */
    public function getApplicableRate(string $region, ?Carbon $date = null): TaxRule
    {
        $region = strtoupper($region);
        $date = $date ?? now();
        $cacheKey = "tax_rule:{$region}:{$date->toDateString()}";

        $rule = Cache::remember($cacheKey, 3600, function () use ($region, $date) {
            return TaxRule::where('region_code', $region)
                ->where('effective_date', '<=', $date->toDateString())
                ->where(function ($query) use ($date) {
                    $query->whereNull('expiry_date')
                        ->orWhere('expiry_date', '>=', $date->toDateString());
                })
                ->orderByDesc('effective_date')
                ->first();
        });

        if ($rule) {
            return $rule;
        }

        return $this->buildDefaultRule($region, $date);
    }

    /**
     * 判断是否免税
     *
     * @param  string  $region  地区代码
     * @param  string|null  $productType  商品类型
     */
    public function isExempt(string $region, ?string $productType = null): bool
    {
        $region = strtoupper($region);

        if ($productType === null) {
            return false;
        }

        $rates = self::PRODUCT_RATES[$region] ?? [];

        if (array_key_exists($productType, $rates)) {
            return (float) $rates[$productType] <= 0.0;
        }

        return false;
    }

    /**
     * 判断地区是否受支持
     */
    protected function isSupportedRegion(string $region): bool
    {
        return in_array($region, self::SUPPORTED_REGIONS, true);
    }

    /**
     * 解析适用税率：优先商品类型对应税率，回退到生效税率规则
     */
    protected function resolveRate(string $region, ?string $productType): float
    {
        if ($productType !== null) {
            $productRates = self::PRODUCT_RATES[$region] ?? [];
            if (array_key_exists($productType, $productRates)) {
                return (float) $productRates[$productType];
            }
        }

        return (float) $this->getApplicableRate($region)->tax_rate;
    }

    /**
     * 构建默认税率规则（无 DB 记录时回退）
     */
    protected function buildDefaultRule(string $region, Carbon $date): TaxRule
    {
        $config = $this->getDefaultRateConfig($region);

        $rule = new TaxRule;
        $rule->region_code = $region;
        $rule->tax_rate = $config['rate'];
        $rule->tax_name = $config['name'];
        $rule->effective_date = $date->toDateString();
        $rule->expiry_date = null;
        $rule->is_default = true;

        return $rule;
    }

    /**
     * 获取默认税率配置：config 优先，其次内置常量
     *
     * @return array{rate: float, name: string}
     */
    protected function getDefaultRateConfig(string $region): array
    {
        $config = config("pay.invoice.tax_rules.{$region}");

        if (is_array($config) && isset($config['rates']) && is_array($config['rates'])) {
            $rate = (float) reset($config['rates']);

            return [
                'rate' => $rate,
                'name' => $config['name'] ?? ($region . ' Tax'),
            ];
        }

        if (isset(self::DEFAULT_RATE_CONFIG[$region])) {
            return self::DEFAULT_RATE_CONFIG[$region];
        }

        throw new \RuntimeException(trans('payment.tax_rule_not_found'));
    }

    /**
     * 中国税号校验：15/18/20 位字母数字（统一社会信用代码/纳税人识别号）
     */
    protected function validateChineseTaxNumber(string $taxNumber): bool
    {
        $pattern = config('pay.invoice.tax_rules.CN.number_pattern', '/^[0-9A-Z]{15}$|^[0-9A-Z]{18}$|^[0-9A-Z]{20}$/');

        return (bool) preg_match($pattern, $taxNumber);
    }

    /**
     * 欧盟 VAT 校验：2 位国家代码 + 2~12 位字母数字
     */
    protected function validateEuVatNumber(string $taxNumber): bool
    {
        $pattern = config('pay.invoice.tax_rules.EU.number_pattern', '/^[A-Z]{2}[0-9A-Z]{2,12}$/');

        return (bool) preg_match($pattern, $taxNumber);
    }

    /**
     * 英国 VAT 校验：GB/GD/HA + 9 或 12 位数字
     */
    protected function validateUkVatNumber(string $taxNumber): bool
    {
        $pattern = config('pay.invoice.tax_rules.UK.number_pattern', '/^(GB|GD|HA)[0-9]{9}$|^(GB|GD|HA)[0-9]{12}$/');

        return (bool) preg_match($pattern, $taxNumber);
    }

    /**
     * 美国 EIN 校验：9 位数字（可选连字符 XX-XXXXXXX）
     */
    protected function validateUsEinNumber(string $taxNumber): bool
    {
        $pattern = config('pay.invoice.tax_rules.US.number_pattern', '/^[0-9]{9}$|^[0-9]{2}-[0-9]{7}$/');

        return (bool) preg_match($pattern, $taxNumber);
    }
}
