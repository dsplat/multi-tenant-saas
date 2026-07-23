<?php

namespace MultiTenantSaas\Modules\Coupon\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Coupon\Models\Coupon;
use MultiTenantSaas\Modules\Coupon\Models\CouponShare;
use MultiTenantSaas\Modules\Coupon\Models\CouponUsage;

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
    public function createCoupon(array $data): Coupon
    {
        $code = $data['code'] ?? $this->generateCode($data['prefix'] ?? '');

        try {
            return Coupon::create($this->buildAttributes($data, $code));
        } catch (QueryException $e) {
            if ($this->isDuplicateException($e)) {
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
    public function generateCode(string $prefix = '', int $length = 8, int $maxAttempts = 100): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $prefix = strtoupper($prefix);
        $attempts = 0;

        do {
            if (++$attempts > $maxAttempts) {
                throw new \RuntimeException('Failed to generate unique coupon code after ' . $maxAttempts . ' attempts.');
            }

            $suffix = '';
            for ($i = 0; $i < $length; $i++) {
                $suffix .= $alphabet[random_int(0, $max)];
            }
            $code = $prefix . $suffix;
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
    public function generateCodes(string $prefix, int $quantity, array $attributes = []): array
    {
        if ($quantity <= 0) {
            return [];
        }

        $codes = [];
        $attempts = 0;
        $maxAttempts = $quantity * 5;

        while (count($codes) < $quantity && $attempts < $maxAttempts) {
            $attempts++;
            $code = $this->generateCode($prefix);

            try {
                Coupon::create(array_merge(
                    $this->buildAttributes($attributes, $code),
                    ['used_count' => 0]
                ));
                $codes[] = $code;
            } catch (QueryException $e) {
                // 唯一约束冲突（并发码重复），跳过继续
                if (! $this->isDuplicateException($e)) {
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
    public function validate(string $code, ?int $tenantId, ?float $amount = null, int|string|null $planId = null): Coupon
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

        if ($tenantId && ! $this->checkTenantQuota($coupon, $tenantId)) {
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
    public function redeem(string $code, ?int $tenantId, array $context = []): CouponUsage
    {
        $amount = (float) ($context['amount'] ?? 0);
        $coupon = $this->validate($code, $tenantId, $amount, $context['subscription_plan_id'] ?? null);
        $discount = $this->calculateDiscount($coupon, $amount);

        return DB::transaction(function () use ($coupon, $discount, $tenantId, $context, $amount) {
            $locked = Coupon::where('coupon_id', $coupon->coupon_id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive() || ! $locked->hasStarted() || $locked->hasExpired() || $locked->hasReachedMaxUses()) {
                throw new \RuntimeException(trans('subscription.coupon_invalid'));
            }

            $discount = $this->calculateDiscount($locked, $amount);

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
    public function calculateDiscount(Coupon $coupon, float $amount): float
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
    public function checkTenantQuota(Coupon $coupon, $tenantId): bool
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
    public function getCoupons(array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
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
                $q->where('code', 'like', $keyword . '%')
                    ->orWhere('description', 'like', '%' . $keyword . '%');
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
    public function getUsages(int $couponId, ?int $tenantId = null): Collection
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
    public function getStatistics(int $couponId): array
    {
        $coupon = $this->findCoupon($couponId);

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
    public function activate(int $couponId): Coupon
    {
        $coupon = $this->findCoupon($couponId);
        $coupon->is_active = true;
        $coupon->save();

        return $coupon;
    }

    /**
     * 停用优惠券
     */
    public function deactivate(int $couponId): Coupon
    {
        $coupon = $this->findCoupon($couponId);
        $coupon->is_active = false;
        $coupon->save();

        return $coupon;
    }

    // ========================================
    // 模板管理
    // ========================================

    /**
     * 创建优惠券模板
     *
     * @param  array  $data  模板属性，支持键:
     *                       name, description, type, value, currency,
     *                       min_amount, max_discount, applies_to,
     *                       subscription_plan_id, duration_months,
     *                       max_uses, max_uses_per_tenant, starts_at,
     *                       expires_at, metadata
     */
    public function createTemplate(array $data): Coupon
    {
        // 模板需要唯一 code，但模板码不用于实际兑换，使用 TPL- 前缀自动生成
        $code = $data['code'] ?? $this->generateCode('TPL-');

        return Coupon::create(array_merge(
            $this->buildAttributes($data, $code),
            [
                'is_template' => true,
                'is_active' => $data['is_active'] ?? true,
            ]
        ));
    }

    /**
     * 更新优惠券模板
     */
    public function updateTemplate(int $templateId, array $data): Coupon
    {
        $template = $this->findCoupon($templateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        $updatableFields = [
            'description', 'type', 'value', 'currency', 'min_amount',
            'max_discount', 'applies_to', 'subscription_plan_id',
            'duration_months', 'max_uses', 'max_uses_per_tenant',
            'starts_at', 'expires_at', 'metadata',
        ];

        $attributes = [];
        foreach ($updatableFields as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (! empty($attributes)) {
            $template->update($attributes);
        }

        return $template->fresh();
    }

    /**
     * 删除优惠券模板
     *
     * 仅允许删除无关联优惠券的模板
     */
    public function deleteTemplate(int $templateId): bool
    {
        $template = $this->findCoupon($templateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        $generatedCount = Coupon::where('template_id', $templateId)->count();
        if ($generatedCount > 0) {
            throw new \RuntimeException("该模板已生成 {$generatedCount} 张优惠券，无法删除");
        }

        return $template->delete();
    }

    /**
     * 查询模板列表
     *
     * @param  array  $filters  支持键: type, applies_to, is_active, keyword
     * @param  int|null  $perPage  每页数量，null=不分页返回全量
     * @return Collection<int, Coupon>|LengthAwarePaginator
     */
    public function getTemplates(array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = Coupon::templates();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['applies_to'])) {
            $query->where('applies_to', $filters['applies_to']);
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }
        if (! empty($filters['keyword'])) {
            $keyword = Str::replace(['%', '_'], ['\\%', '\\_'], $filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('description', 'like', '%' . $keyword . '%');
            });
        }

        $query->orderByDesc('created_at');

        if ($perPage !== null) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * 从模板生成优惠券
     *
     * @param  int  $templateId  模板ID
     * @param  int  $quantity  生成数量
     * @param  string  $prefix  优惠码前缀
     * @return array<string> 生成的优惠码列表
     */
    public function generateFromTemplate(int $templateId, int $quantity, string $prefix = ''): array
    {
        $template = $this->findCoupon($templateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        if (! $template->isActive()) {
            throw new \RuntimeException('模板已停用，无法生成优惠券');
        }

        $attributes = [
            'description' => $template->description,
            'type' => $template->type,
            'value' => $template->value,
            'currency' => $template->currency,
            'min_amount' => $template->min_amount,
            'max_discount' => $template->max_discount,
            'applies_to' => $template->applies_to,
            'subscription_plan_id' => $template->subscription_plan_id,
            'duration_months' => $template->duration_months,
            'max_uses' => $template->max_uses,
            'max_uses_per_tenant' => $template->max_uses_per_tenant,
            'starts_at' => $template->starts_at,
            'expires_at' => $template->expires_at,
            'metadata' => $template->metadata,
            'template_id' => $templateId,
            'is_template' => false,
        ];

        return $this->generateCodes($prefix, $quantity, $attributes);
    }

    /**
     * 启用模板
     */
    public function activateTemplate(int $templateId): Coupon
    {
        $template = $this->findCoupon($templateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        $template->is_active = true;
        $template->save();

        return $template;
    }

    /**
     * 停用模板
     */
    public function deactivateTemplate(int $templateId): Coupon
    {
        $template = $this->findCoupon($templateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        $template->is_active = false;
        $template->save();

        return $template;
    }

    // ========================================
    // 批量发券
    // ========================================

    /**
     * 批量发放优惠券给指定用户列表
     *
     * @param  int  $templateId  模板ID
     * @param  array  $userIds  用户ID列表（int[]）
     * @param  int  $tenantId  租户ID
     * @return array ['issued' => int, 'codes' => array]
     */
    public function bulkDistribute(int $templateId, array $userIds, int $tenantId): array
    {
        if (empty($userIds)) {
            return ['issued' => 0, 'codes' => []];
        }

        $template = $this->findCoupon($templateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        if (! $template->isActive()) {
            throw new \RuntimeException('模板已停用，无法发券');
        }

        // 去重并校验
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $userIds = array_filter($userIds, fn ($id) => $id > 0);

        $issued = 0;
        $codes = [];

        return DB::transaction(function () use ($template, $userIds, $tenantId, $templateId, &$issued, &$codes) {
            foreach ($userIds as $userId) {
                try {
                    $code = $this->generateCode('BD-');
                    Coupon::create($this->buildCouponFromTemplate($template, [
                        'code' => $code,
                        'max_uses' => 1,
                        'max_uses_per_tenant' => 1,
                        'template_id' => $templateId,
                        'is_template' => false,
                        'metadata' => [
                            'distributed_to' => $userId,
                            'tenant_id' => $tenantId,
                            'template_id' => $templateId,
                        ],
                    ]));
                    $codes[] = $code;
                    $issued++;
                } catch (QueryException $e) {
                    if (! $this->isDuplicateException($e)) {
                        throw $e;
                    }
                }
            }

            return ['issued' => $issued, 'codes' => $codes];
        });
    }

    // ========================================
    // 裂变发券
    // ========================================

    /**
     * 生成分享链接并记录分享关系
     */
    public function shareCoupon(int $sharerId, int $couponTemplateId, int $tenantId): CouponShare
    {
        $template = $this->findCoupon($couponTemplateId);

        if (! $template->isTemplate()) {
            throw new \RuntimeException('指定优惠券不是模板');
        }

        if (! $template->isActive()) {
            throw new \RuntimeException('模板已停用，无法分享');
        }

        $shareCode = strtoupper(Str::random(16));

        return CouponShare::create([
            'tenant_id' => $tenantId,
            'sharer_id' => $sharerId,
            'coupon_template_id' => $couponTemplateId,
            'share_code' => $shareCode,
            'status' => 'pending',
        ]);
    }

    /**
     * 裂变发券：分享链接被点击后向分享人和被分享人发券
     *
     * @param  string  $shareCode  分享码
     * @param  int  $receiverId  被分享人用户ID
     * @param  int  $tenantId  租户ID
     * @return array ['sharer_coupon' => Coupon, 'receiver_coupon' => Coupon, 'share' => CouponShare]
     */
    public function acceptShare(string $shareCode, int $receiverId, int $tenantId): array
    {
        return DB::transaction(function () use ($shareCode, $receiverId, $tenantId) {
            // 事务内加行锁，防止并发竞态
            $share = CouponShare::where('share_code', $shareCode)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $share) {
                throw new \RuntimeException('分享链接无效或已使用');
            }

            // 租户隔离校验
            if ((int) $share->tenant_id !== $tenantId) {
                throw new \RuntimeException('无权接受此分享');
            }

            if ((int) $share->sharer_id === $receiverId) {
                throw new \RuntimeException('不能接受自己的分享');
            }

            $template = $this->findCoupon($share->coupon_template_id);

            if (! $template->isActive()) {
                throw new \RuntimeException('优惠券模板已停用');
            }

            // 向被分享人发券
            $receiverCoupon = Coupon::create($this->buildCouponFromTemplate($template, [
                'code' => $this->generateCode('FS-'),
                'description' => $template->description . '（裂变分享）',
                'max_uses' => 1,
                'max_uses_per_tenant' => 1,
                'template_id' => $share->coupon_template_id,
                'is_template' => false,
                'metadata' => [
                    'fission_share' => $share->share_id,
                    'role' => 'receiver',
                ],
            ]));

            // 向分享人发券
            $sharerCoupon = Coupon::create($this->buildCouponFromTemplate($template, [
                'code' => $this->generateCode('FS-'),
                'description' => $template->description . '（裂变奖励）',
                'max_uses' => 1,
                'max_uses_per_tenant' => 1,
                'template_id' => $share->coupon_template_id,
                'is_template' => false,
                'metadata' => [
                    'fission_share' => $share->share_id,
                    'role' => 'sharer',
                ],
            ]));

            // 更新分享状态
            $share->update([
                'receiver_id' => $receiverId,
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            return [
                'sharer_coupon' => $sharerCoupon,
                'receiver_coupon' => $receiverCoupon,
                'share' => $share->fresh(),
            ];
        });
    }

    /**
     * 查询分享记录
     */
    public function getShareRecords(int $tenantId, array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = CouponShare::where('tenant_id', $tenantId);

        if (! empty($filters['sharer_id'])) {
            $query->where('sharer_id', (int) $filters['sharer_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderByDesc('created_at');

        if ($perPage !== null) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * 从模板构建优惠券属性（提取公共逻辑，避免重复）
     */
    protected function buildCouponFromTemplate(Coupon $template, array $overrides = []): array
    {
        return array_merge([
            'description' => $template->description,
            'type' => $template->type,
            'value' => $template->value,
            'currency' => $template->currency,
            'min_amount' => $template->min_amount,
            'max_discount' => $template->max_discount,
            'applies_to' => $template->applies_to,
            'subscription_plan_id' => $template->subscription_plan_id,
            'duration_months' => $template->duration_months,
            'starts_at' => $template->starts_at,
            'expires_at' => $template->expires_at,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * 构造优惠券属性
     */
    protected function buildAttributes(array $data, ?string $code = null): array
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
    protected function findCoupon(int $couponId): Coupon
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
    protected function isDuplicateException(QueryException $e): bool
    {
        $driverCode = (string) ($e->errorInfo[1] ?? '');
        $message = $e->getMessage() ?? '';

        // MySQL: 1062, PostgreSQL: 23505, SQLite: 2067
        if (in_array($driverCode, ['1062', '23505', '2067'], true)) {
            return true;
        }

        return stripos($message, 'unique') !== false;
    }
}
