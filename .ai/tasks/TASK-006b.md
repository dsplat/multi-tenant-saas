# TASK-006b: [Auto-split from TASK-006]

目标: 实现 Coupon 和 CouponUsage 模型，以及 CouponService 的完整折扣核销逻辑
只允许修改:
- `src/Models/Coupon.php`（新建，use HasTenantScope，tenant_id 可为 null）
- `src/Models/CouponUsage.php`（新建，use HasTenantScope）
- `src/Services/CouponService.php`（新建，含验证/核销/批量生成/查询统计）
禁止: 修改其他文件、新增依赖
预估时间: 2 小时
依赖: 无



## 状态
READY
