# TASK-006a: [Auto-split from TASK-006]

目标: 创建优惠券与试用期相关数据库迁移，并为 Tenant 模型追加 trial 字段
只允许修改:
- `database/migrations/2026_06_27_000011_create_coupons_tables.php`（新建，一个迁移内创建 coupons + coupon_usages 两张表）
- `database/migrations/2026_06_27_000012_add_trial_fields_to_tenants_table.php`（新建）
- `src/Models/Tenant.php`（追加 trial_ends_at、trial_extended、trial_notification_sent_at 到 fillable/casts）
禁止: 修改其他文件、新增依赖
预估时间: 1 小时
依赖: 无



## 状态
READY
