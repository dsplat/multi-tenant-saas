<?php

return [
    'subscribe_success' => '订阅成功',
    'cancel_success' => '已取消自动续费，到期后将降级为免费版',
    'change_success' => '计划已变更',
    'plan_not_deletable' => '免费计划不可删除',
    'plan_not_available' => '该订阅计划不可用',
    'trial_started' => '试用已开始',
    'expired_downgraded' => '订阅已过期，已降级为免费版',
    'auto_renew_failed' => '自动续费失败',
    'quota_members' => '成员数量',
    'quota_credits' => '积分余额',
    'quota_storage' => '存储空间',

    // 催款（Dunning）
    'dunning_retry_scheduled' => '支付重试已安排，将于 :date 再次扣款',
    'dunning_payment_failed' => '支付失败，已进入催款流程',
    'dunning_grace_period' => '宽限期内，请尽快完成支付以免服务暂停',
    'dunning_suspended' => '因支付失败超过宽限期，服务已暂停',
    'dunning_expiry_reminder_7d' => '订阅将在 7 天后到期',
    'dunning_expiry_reminder_3d' => '订阅将在 3 天后到期',
    'dunning_expiry_reminder_1d' => '订阅将在 1 天后到期',

    // 用量计量
    'usage_limit_exceeded' => '用量已超出限额',
    'usage_overage_charged' => '已对超出部分按 :price 计费',
    'usage_hard_limit_reached' => '已达硬性用量上限，请求被拒绝',

    // 套餐变更
    'plan_change_upgraded' => '套餐已升级',
    'plan_change_downgraded' => '套餐已降级',
    'plan_change_proration_charged' => '已按比例补收差价 :amount',
    'plan_change_proration_credited' => '已按比例退还差价 :amount',
];
