<?php

namespace MultiTenantSaas\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MultiTenantSaas\Models\Coupon;
use MultiTenantSaas\Models\CouponDistribution;
use MultiTenantSaas\Models\CouponRule;
use MultiTenantSaas\Models\CouponTemplate;
use MultiTenantSaas\Models\CouponUsage;

/**
 * 优惠券服务
 *
 * 负责优惠券的创建、批量生成、验证、核销与查询统计。
 *
 * - 折扣类型: fixed=固定金额, percentage=百分比
 * - 使用限制: 全局次数(max_uses)、每租户次数(max_uses_per_tenant)、
 *             有效期(starts_at/expires_at)、最低消费(min_amount)、适用套餐(subscription_plan_id)、适用范围(applies_to)
 * - 核销流程: 校验可用性 → 计算折扣 → 事务内行锁扣减 used_count → 写入 CouponUsage → 返回核销记录
 * - 优惠码: 大写字母+数字，去除易混淆字符 O/0/I/1
 *
 * 租户隔离通过显式 tenant_id 参数管理（validate/redeem/getUsages 均接收 tenantId），
 * 支持管理端跨租户查询与指定租户核销。
 */
class CouponService
{
    /** 优惠码可选字符集（已去除易混淆字符 O/0/I/1） */
    protected const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * 创建优惠券
     *
     * @param  array  $data  优惠券属性，支持键:
     *                       code, description, type, value, currency,
     *                       min_amount, max_discount, applies_to,
     *                       subscription_plan_id, duration_months,
     *                       max_uses, max_uses_per_tenant, starts_at,
     *                       expires_at, is_active, metadata, prefix
     *
     * @throws \RuntimeException 优惠码重复
     */
    public static function createCoupon(array $data): Coupon
    {
        $code = $data['code'] ?? static::generateCode($data['prefix'] ?? '');

        try {
            return Coupon::create(static::buildAttributes($data, $code));
        } catch (QueryException $e) {
            if (static::isDuplicateException($e)) {
                throw new \RuntimeException(trans('subscription.coupon_code_exists'), 0, $e);
            }
            throw $e;
        }
    }

    /**
     * 生成单个优惠码（前缀 + 随机后缀）
     *
     * 碰撞概率极低（32^8 ≈ 10^12），仅做存在性校验兜底。
     *
     * @throws \RuntimeException 超过最大尝试次数
     */
    public static function generateCode(string $prefix = '', int $length = 8, int $maxAttempts = 100): string
    {
        $alphabet = static::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $prefix = strtoupper($prefix);
        $attempts = 0;

        do {
            if (++$attempts > $maxAttempts) {
                throw new \RuntimeException('Failed to generate unique coupon code after '.$maxAttempts.' attempts.');
            }

            $suffix = '';
            for ($i = 0; $i < $length; $i++) {
                $suffix .= $alphabet[random_int(0, $max)];
            }
            $code = $prefix.$suffix;
        } while (Coupon::byCode($code)->exists());

        return $code;
    }

    /**
     * 批量生成优惠码并持久化
     *
     * @param  string  $prefix  优惠码前缀
     * @param  int  $quantity  生成数量
     * @param  array  $attributes  优惠券模板属性（type, value, ...）
     * @return array<string> 生成的优惠码列表
     */
    public static function generateCodes(string $prefix, int $quantity, array $attributes = []): array
    {
        if ($quantity <= 0) {
            return [];
        }

        $codes = [];
        $attempts = 0;
        $maxAttempts = $quantity * 5;

        while (count($codes) < $quantity && $attempts < $maxAttempts) {
            $attempts++;
            $code = static::generateCode($prefix);

            try {
                Coupon::create(array_merge(
                    static::buildAttributes($attributes, $code),
                    ['used_count' => 0]
                ));
                $codes[] = $code;
            } catch (QueryException $e) {
                // 唯一约束冲突（并发码重复），跳过继续
                if (! static::isDuplicateException($e)) {
                    throw $e;
                }
            }
        }

        return $codes;
    }

    /**
     * 校验优惠券可用性
     *
     * @param  string  $code  优惠码
     * @param  int|null  $tenantId  租户ID（用于每租户配额校验）
     * @param  float|null  $amount  订单金额（用于最低消费校验）
     * @param  int|string|null  $planId  订阅计划ID（用于适用套餐校验）
     *
     * @throws \RuntimeException 优惠券不可用
     */
    public static function validate(string $code, ?int $tenantId, ?float $amount = null, int|string|null $planId = null): Coupon
    {
        $coupon = Coupon::byCode($code)->first();

        if (! $coupon) {
            throw new \RuntimeException(trans('subscription.coupon_not_found'));
        }

        if (! $coupon->isActive()) {
            throw new \RuntimeException(trans('subscription.coupon_not_active'));
        }

        if (! $coupon->hasStarted()) {
            throw new \RuntimeException(trans('subscription.coupon_not_started'));
        }

        if ($coupon->hasExpired()) {
            throw new \RuntimeException(trans('subscription.coupon_expired'));
        }

        if ($coupon->hasReachedMaxUses()) {
            throw new \RuntimeException(trans('subscription.coupon_usage_limit_reached'));
        }

        if ($tenantId && ! static::checkTenantQuota($coupon, $tenantId)) {
            throw new \RuntimeException(trans('subscription.coupon_per_tenant_limit_reached'));
        }

        if ($coupon->min_amount !== null && (float) $amount < (float) $coupon->min_amount) {
            throw new \RuntimeException(
                trans('subscription.coupon_min_amount_not_met', ['min_amount' => $coupon->min_amount])
            );
        }

        if ($coupon->applies_to === Coupon::APPLIES_TO_SUBSCRIPTION
            && $coupon->subscription_plan_id !== null
            && (string) $coupon->subscription_plan_id !== (string) $planId) {
            throw new \RuntimeException(trans('subscription.coupon_plan_not_applicable'));
        }

        return $coupon;
    }

    /**
     * 核销优惠券
     *
     * 流程: 校验 → 事务内行锁扣减 used_count → 写入 CouponUsage → 返回核销记录
     *
     * @param  string  $code  优惠码
     * @param  int|null  $tenantId  核销租户ID
     * @param  array  $context  上下文: amount, user_id, invoice_id,
     *                          subscription_plan_id, currency, metadata
     *
     * @throws \RuntimeException 优惠券不可用或已达使用上限
     */
    public static function redeem(string $code, ?int $tenantId, array $context = []): CouponUsage
    {
        $amount = (float) ($context['amount'] ?? 0);
        $coupon = static::validate($code, $tenantId, $amount, $context['subscription_plan_id'] ?? null);
        $discount = static::calculateDiscount($coupon, $amount);

        return DB::transaction(function () use ($coupon, $discount, $tenantId, $context, $amount) {
            $locked = Coupon::where('coupon_id', $coupon->coupon_id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive() || ! $locked->hasStarted() || $locked->hasExpired() || $locked->hasReachedMaxUses()) {
                throw new \RuntimeException(trans('subscription.coupon_invalid'));
            }

            $discount = static::calculateDiscount($locked, $amount);

            if ($tenantId) {
                $usedByTenant = CouponUsage::where('coupon_id', $locked->coupon_id)
                    ->where('tenant_id', $tenantId)
                    ->count();
                if ($usedByTenant >= $locked->max_uses_per_tenant) {
                    throw new \RuntimeException(trans('subscription.coupon_per_tenant_limit_reached'));
                }
            }

            $locked->increment('used_count');

            return CouponUsage::create([
                'coupon_id' => $locked->coupon_id,
                'tenant_id' => $tenantId,
                'user_id' => $context['user_id'] ?? null,
                'invoice_id' => $context['invoice_id'] ?? null,
                'subscription_plan_id' => $context['subscription_plan_id'] ?? $locked->subscription_plan_id,
                'discount_amount' => $discount,
                'currency' => $context['currency'] ?? $locked->currency,
                'metadata' => $context['metadata'] ?? null,
            ]);
        });
    }

    /**
     * 计算折扣金额
     *
     * - fixed: 固定金额，不超过订单金额
     * - percentage: 按比例计算，受 max_discount 上限约束，不超过订单金额
     */
    public static function calculateDiscount(Coupon $coupon, float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        if ($coupon->isFixed()) {
            return round(min((float) $coupon->value, $amount), 2);
        }

        if ($coupon->isPercentage()) {
            $discount = $amount * (float) $coupon->value / 100;

            if ($coupon->max_discount !== null) {
                $discount = min($discount, (float) $coupon->max_discount);
            }

            return round(min($discount, $amount), 2);
        }

        return 0.0;
    }

    /**
     * 检查租户使用配额
     */
    public static function checkTenantQuota(Coupon $coupon, $tenantId): bool
    {
        $used = CouponUsage::where('coupon_id', $coupon->coupon_id)
            ->where('tenant_id', $tenantId)
            ->count();

        return $used < $coupon->max_uses_per_tenant;
    }

    /**
     * 查询优惠券列表
     *
     * @param  array  $filters  支持键: type, applies_to, is_active,
     *                          subscription_plan_id, start_date, end_date, keyword
     * @param  int|null  $perPage  每页数量，null=不分页返回全量
     * @return Collection<int, Coupon>|LengthAwarePaginator
     */
    public static function getCoupons(array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = Coupon::query();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['applies_to'])) {
            $query->where('applies_to', $filters['applies_to']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }
        if (! empty($filters['subscription_plan_id'])) {
            $query->where('subscription_plan_id', $filters['subscription_plan_id']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<', Carbon::parse($filters['end_date'])->addDay());
        }
        if (! empty($filters['keyword'])) {
            $keyword = Str::replace(['%', '_'], ['\\%', '\\_'], $filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', $keyword.'%')
                    ->orWhere('description', 'like', '%'.$keyword.'%');
            });
        }

        $query->orderByDesc('created_at');

        if ($perPage !== null) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * 查询优惠券使用记录
     *
     * @param  int  $couponId  优惠券ID
     * @param  int|null  $tenantId  租户ID，null=全部租户
     * @return Collection<int, CouponUsage>
     */
    public static function getUsages(int $couponId, ?int $tenantId = null): Collection
    {
        $query = CouponUsage::query()
            ->with('coupon')
            ->where('coupon_id', $couponId);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 优惠券使用统计
     *
     * @return array{used_count: int, total_discount: float, max_uses: int|null, ...}
     */
    public static function getStatistics(int $couponId): array
    {
        $coupon = static::findCoupon($couponId);

        $usageQuery = CouponUsage::where('coupon_id', $couponId);

        return [
            'used_count' => (clone $usageQuery)->count(),
            'total_discount' => round((float) (clone $usageQuery)->sum('discount_amount'), 2),
            'max_uses' => $coupon->max_uses,
            'max_uses_per_tenant' => $coupon->max_uses_per_tenant,
            'used_count_field' => $coupon->used_count,
            'is_active' => $coupon->isActive(),
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
        ];
    }

    /**
     * 启用优惠券
     */
    public static function activate(int $couponId): Coupon
    {
        $coupon = static::findCoupon($couponId);
        $coupon->is_active = true;
        $coupon->save();

        return $coupon;
    }

    /**
     * 停用优惠券
     */
    public static function deactivate(int $couponId): Coupon
    {
        $coupon = static::findCoupon($couponId);
        $coupon->is_active = false;
        $coupon->save();

        return $coupon;
    }

    /**
     * 构造优惠券属性
     */
    protected static function buildAttributes(array $data, ?string $code = null): array
    {
        return [
            'code' => $code ?? ($data['code'] ?? null),
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? Coupon::TYPE_FIXED,
            'value' => $data['value'] ?? 0,
            'currency' => $data['currency'] ?? null,
            'min_amount' => $data['min_amount'] ?? null,
            'max_discount' => $data['max_discount'] ?? null,
            'applies_to' => $data['applies_to'] ?? Coupon::APPLIES_TO_SUBSCRIPTION,
            'subscription_plan_id' => $data['subscription_plan_id'] ?? null,
            'duration_months' => $data['duration_months'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'max_uses_per_tenant' => $data['max_uses_per_tenant'] ?? 1,
            'used_count' => $data['used_count'] ?? 0,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'metadata' => $data['metadata'] ?? null,
        ];
    }

    /**
     * 查找优惠券
     */
    protected static function findCoupon(int $couponId): Coupon
    {
        $coupon = Coupon::find($couponId);

        if (! $coupon) {
            throw new \RuntimeException(trans('subscription.coupon_not_found'));
        }

        return $coupon;
    }

    /**
     * 判断是否为唯一约束冲突异常
     */
    protected static function isDuplicateException(QueryException $e): bool
    {
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $message = $e->getMessage() ?? '';

        // MySQL: 1062, PostgreSQL: 23505, SQLite: 2067
        if (in_array($driverCode, ['1062', '23505', '2067'], true)) {
            return true;
        }

        return stripos($message, 'unique') !== false;
    }

    // ========================================================================
    // 扩展: 批量发券、裂变发券、券模板、用券规则
    // ========================================================================

    /**
     * 批量发券
     *
     * 从模板批量生成优惠券并分发给指定租户或用户。
     *
     * @param  int  $templateId  优惠券模板ID
     * @param  array  $recipients  接收者 [{tenant_id, user_id}, ...]
     * @param  string|null  $prefix  优惠码前缀
     * @return array{codes: array, distribution: CouponDistribution}
     */
    public static function batchIssueCoupons(int $templateId, array $recipients, ?string $prefix = null): array
    {
        $template = CouponTemplate::findOrFail($templateId);

        if (!$template->is_active) {
            throw new \RuntimeException('优惠券模板未启用');
        }

        $quantity = count($recipients);
        $batchId = 'BATCH_' . date('YmdHis') . '_' . Str::random(8);
        $codes = [];
        $distributions = [];

        $baseAttributes = [
            'type' => $template->type,
            'value' => $template->value,
            'currency' => $template->currency,
            'min_amount' => $template->min_amount,
            'max_discount' => $template->max_discount,
            'applies_to' => $template->applies_to,
            'subscription_plan_id' => $template->subscription_plan_id,
            'duration_months' => $template->duration_months,
            'max_uses' => $template->max_uses ?? 1,
            'max_uses_per_tenant' => $template->max_uses_per_tenant,
            'starts_at' => now(),
            'expires_at' => $template->valid_days ? now()->addDays($template->valid_days) : null,
            'is_active' => true,
        ];

        $generatedCodes = static::generateCodes($prefix ?? 'BATCH', $quantity, $baseAttributes);

        foreach ($recipients as $index => $recipient) {
            $code = $generatedCodes[$index] ?? null;
            if (!$code) {
                continue;
            }

            $coupon = Coupon::byCode($code)->first();
            $codes[] = $code;

            $distributions[] = CouponDistribution::create([
                'coupon_id' => $coupon->coupon_id,
                'template_id' => $templateId,
                'tenant_id' => $recipient['tenant_id'] ?? null,
                'user_id' => $recipient['user_id'] ?? null,
                'distribution_type' => 'batch',
                'batch_id' => $batchId,
                'metadata' => $recipient['metadata'] ?? null,
            ]);
        }

        return ['codes' => $codes, 'distributions' => $distributions, 'batch_id' => $batchId];
    }

    /**
     * 裂变发券
     *
     * 用户邀请好友后双方各得一张优惠券。
     *
     * @param  int  $templateId  优惠券模板ID
     * @param  int  $sourceUserId  邀请人用户ID
     * @param  int  $targetUserId  被邀请人用户ID
     * @param  int|null  $tenantId  租户ID
     * @return array{inviter_coupon: Coupon, invitee_coupon: Coupon}
     */
    public static function splitIssueCoupons(int $templateId, int $sourceUserId, int $targetUserId, ?int $tenantId = null): array
    {
        $template = CouponTemplate::findOrFail($templateId);

        if (!$template->is_active) {
            throw new \RuntimeException('优惠券模板未启用');
        }

        $baseAttributes = [
            'type' => $template->type,
            'value' => $template->value,
            'currency' => $template->currency,
            'min_amount' => $template->min_amount,
            'max_discount' => $template->max_discount,
            'applies_to' => $template->applies_to,
            'max_uses' => 1,
            'max_uses_per_tenant' => 1,
            'starts_at' => now(),
            'expires_at' => $template->valid_days ? now()->addDays($template->valid_days) : null,
            'is_active' => true,
        ];

        $inviterCode = static::generateCode('INV');
        $inviterCoupon = static::createCoupon(array_merge($baseAttributes, ['code' => $inviterCode]));

        CouponDistribution::create([
            'coupon_id' => $inviterCoupon->coupon_id,
            'template_id' => $templateId,
            'tenant_id' => $tenantId,
            'user_id' => $sourceUserId,
            'distribution_type' => 'split',
            'source_user_id' => $targetUserId,
            'metadata' => ['role' => 'inviter'],
        ]);

        $inviteeCode = static::generateCode('INV');
        $inviteeCoupon = static::createCoupon(array_merge($baseAttributes, ['code' => $inviteeCode]));

        CouponDistribution::create([
            'coupon_id' => $inviteeCoupon->coupon_id,
            'template_id' => $templateId,
            'tenant_id' => $tenantId,
            'user_id' => $targetUserId,
            'distribution_type' => 'split',
            'source_user_id' => $sourceUserId,
            'metadata' => ['role' => 'invitee'],
        ]);

        return ['inviter_coupon' => $inviterCoupon, 'invitee_coupon' => $inviteeCoupon];
    }

    /**
     * 创建优惠券模板
     */
    public static function createTemplate(array $data): CouponTemplate
    {
        return CouponTemplate::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? Coupon::TYPE_FIXED,
            'value' => $data['value'] ?? 0,
            'currency' => $data['currency'] ?? null,
            'min_amount' => $data['min_amount'] ?? null,
            'max_discount' => $data['max_discount'] ?? null,
            'applies_to' => $data['applies_to'] ?? Coupon::APPLIES_TO_SUBSCRIPTION,
            'subscription_plan_id' => $data['subscription_plan_id'] ?? null,
            'duration_months' => $data['duration_months'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'max_uses_per_tenant' => $data['max_uses_per_tenant'] ?? 1,
            'valid_days' => $data['valid_days'] ?? 30,
            'is_active' => $data['is_active'] ?? true,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * 获取模板列表
     */
    public static function getTemplates(array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = CouponTemplate::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderByDesc('created_at');

        return $perPage !== null ? $query->paginate($perPage) : $query->get();
    }

    /**
     * 添加用券规则
     */
    public static function addRule(int $couponId, string $ruleType, array $ruleConfig, int $priority = 0, string $description = ''): CouponRule
    {
        return CouponRule::create([
            'coupon_id' => $couponId,
            'rule_type' => $ruleType,
            'rule_config' => $ruleConfig,
            'priority' => $priority,
            'is_active' => true,
            'description' => $description,
        ]);
    }

    /**
     * 检查高级用券规则
     *
     * @param  Coupon  $coupon  优惠券
     * @param  array  $context  上下文: amount, category, tenant_id, user_id, stacked_coupons
     * @return bool 是否满足所有规则
     */
    public static function checkRules(Coupon $coupon, array $context = []): bool
    {
        $rules = CouponRule::where('coupon_id', $coupon->coupon_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        foreach ($rules as $rule) {
            switch ($rule->rule_type) {
                case 'stackable':
                    $maxStack = $rule->rule_config['max_stack'] ?? 1;
                    $stacked = $context['stacked_coupons'] ?? [];
                    if (count($stacked) >= $maxStack) {
                        return false;
                    }
                    break;

                case 'category_limit':
                    $allowedCategories = $rule->rule_config['categories'] ?? [];
                    $category = $context['category'] ?? null;
                    if ($category && !in_array($category, $allowedCategories)) {
                        return false;
                    }
                    break;

                case 'tiered_threshold':
                    $threshold = $rule->rule_config['threshold'] ?? 0;
                    $amount = $context['amount'] ?? 0;
                    if ($amount < $threshold) {
                        return false;
                    }
                    break;

                case 'new_user_only':
                    $userId = $context['user_id'] ?? null;
                    if ($userId) {
                        $existingUsage = CouponUsage::where('user_id', $userId)
                            ->where('created_at', '<', now()->subDays($rule->rule_config['days'] ?? 30))
                            ->exists();
                        if ($existingUsage) {
                            return false;
                        }
                    }
                    break;
            }
        }

        return true;
    }
}
