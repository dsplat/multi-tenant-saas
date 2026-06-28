Now I have enough context for a thorough review.

---

## Architecture
模型分层清晰：`Coupon`（全局优惠券）、`CouponUsage`（核销记录）、`CouponService`（业务逻辑），职责边界合理。

任务规格要求 Coupon "use HasTenantScope"，但实际不存在该 trait（代码库中为 `BelongsToTenant`）。实现选择不启用租户作用域，文档注释中给出了合理解释——优惠券为全局资源，CouponUsage 的租户隔离通过 Service 显式管理。这是一个合理的架构决策，且有充分文档支撑。

**扣分点**: `CouponService` 全部使用静态方法，无法被接口抽象或 mock（测试通过直接操作数据库绕过）。这是项目既有模式，但随着 Service 逻辑复杂化（核销事务、并发控制），注入式会更利于测试和扩展。非阻塞。

**评分: 8/10**

---

## Code Quality
- 命名规范统一，符合 PSR-12 和项目既有风格
- PHPDoc 完整，类级别注释解释了设计决策
- 常量分组合理（TYPE_*、APPLIES_TO_*），消除魔法字符串
- `generateCode` 去除易混淆字符（O/0/I/1）是好的 UX 细节
- `buildAttributes` 集中默认值管理，避免重复
- `isDuplicateException` 跨数据库兼容（MySQL/PostgreSQL/SQLite）
- i18n 消息键覆盖全面，中英文翻译完整

**评分: 9/10**

---

## Type Safety
- `casts()` 方法正确使用了 Laravel 的类型转换
- 方法签名类型标注完整（`?int`, `?float`, `int|string|null`）
- `getStatistics` 返回值有 PHPDoc `array{...}` shape 类型

**问题**:
1. `checkTenantQuota` 的 `$tenantId` 参数缺少类型标注（`$tenantId` 而非 `int $tenantId`）——第 263 行
2. `Coupon::value` 存储百分比时无 0-100 范围校验，`calculateDiscount` 直接除以 100，若传入 200 则计算结果为 200%

**评分: 7/10**

---

## Security
- **SQL 注入**: `getCoupons` 中 keyword 过滤正确转义了 `%` 和 `_` 通配符（第 303 行），LIKE 查询安全
- **XSS**: 模型层不直接输出 HTML，i18n 消息使用 `trans()` 安全
- **未授权访问**: CouponService 无鉴权逻辑，依赖上层调用方控制。对于 Service 层这是合理的关注点分离
- **敏感数据暴露**: `metadata` 字段为 JSON，无加密——如果存储 PII 需要注意，但作为优惠券元数据通常不涉及
- **并发安全**: 核销使用 `lockForUpdate()` + 事务，防止超卖（详见 Potential Bugs）

**评分: 8/10**

---

## Performance
- **`getStatistics` 存在冗余查询**（第 351-352 行）: `(clone $usageQuery)->count()` 和 `(clone $usageQuery)->sum('discount_amount')` 是两条独立查询，可用单条 `selectRaw('count(*) as cnt, sum(discount_amount) as total')` 替代
- `redeem` 中 `checkTenantQuota` 与事务内的租户配额检查是重复 COUNT 查询（validate 阶段一次，事务内一次）
- `generateCodes` 循环内逐条 `Coupon::create()`，大批量生成（>100）时效率低。可考虑 `insert()` 批量插入 + 冲突重试
- 索引设计合理：`code` unique、`[coupon_id, tenant_id]` 复合索引、`expires_at` / `is_active` 单列索引

**评分: 7/10**

---

## Potential Bugs

**1. TOCTOU 竞态：每租户配额可能被突破** (中等严重)

`redeem()` 在事务外调用 `validate()` → `checkTenantQuota()`，两个并发请求可同时通过配额校验，然后进入事务。事务内的二次校验（第 208-213 行）正确检查了配额，**但**该检查用的是 `$locked->max_uses_per_tenant`。若两个请求都通过了事务外的 validate，第一个请求写入 usage 后 increment，第二个请求的 `$usedByTenant` 已经包含第一条记录，因此事务内会正确拒绝。

**实际分析**: 事务内有 `lockForUpdate` + 二次检查，所以 TOCTOU 被事务保护住了。但逻辑略显脆弱——如果未来有人重构移除了事务内的二次检查就会出问题。

**2. `calculateDiscount` 对百分比值无范围校验** (低严重)

`value` 为 200 时，`200 * 200 / 100 = 400`，折扣可能大于本金。虽然 `min($discount, $amount)` 兜底了，但逻辑语义错误——`value > 100` 的百分比优惠券是无效的业务数据。

**3. `$amount` 为 null 时的类型隐患** (低严重)

`validate` 签名 `?float $amount = null`，第 165 行 `(float) $amount` 将 null 转为 0.0，然后与 `min_amount` 比较。`min_amount > 0` 时 0.0 < min_amount 会正确抛异常，但调用方传 `null` 意味着"不检查金额"，当前实现却当作金额=0 处理。语义不一致。

**4. `buildAttributes` 不验证 type/applies_to 合法性** (低严重)

传入 `type => 'invalid'` 会直接入库，后续 `calculateDiscount` 返回 0.0（静默失败）。虽然 Model 层有常量数组 `TYPES` / `APPLIES_TO`，但 `buildAttributes` 未做校验。

**评分: 7/10**

---

## Verdict: **PASS**

代码整体质量良好，架构决策合理且有文档支撑，测试覆盖全面（创建/校验/核销/并发/批量生成/查询统计）。无阻塞性安全或正确性缺陷。

**【建议改进】**（非阻塞）:

1. **`checkTenantQuota` 添加类型标注**: `$tenantId` 参数应声明为 `int $tenantId`（`CouponService.php:263`）
2. **百分比值范围校验**: `createCoupon` / `buildAttributes` 中应校验 `percentage` 类型的 `value` 在 `(0, 100]` 区间
3. **`validate` 的 `$amount` 语义**: 区分 `null`（不检查金额）和 `0`（检查但金额为零），避免 `(float) null === 0.0` 的隐式转换
4. **`getStatistics` 合并查询**: 用单条聚合查询替代 count + sum 两次查询
5. **`generateCodes` 大批量优化**: 考虑 `insert()` 批量插入，减少逐条 create 的开销
