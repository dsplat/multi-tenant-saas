# TASK-006c: [Auto-split from TASK-006]

目标: 实现 TrialService 试用期管理，并补充优惠券/试用相关翻译 key
只允许修改:
- `src/Services/TrialService.php`（新建，含到期处理/延长/提醒通知）
- `lang/zh_CN/subscription.php`（追加 coupon + trial 翻译 key）
- `lang/en/subscription.php`（追加 coupon + trial 翻译 key）
禁止: 修改其他文件、新增依赖
预估时间: 1.5 小时
依赖: TASK-006a（TrialService 读写 Tenant 模型的 trial 字段）



## 状态
READY
