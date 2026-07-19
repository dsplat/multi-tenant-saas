<?php

return [
    'tenant_suspended_title' => '团队已暂停',
    'tenant_suspended_body' => '您所在的团队已被暂停',
    'credit_low_title' => '积分余额不足',
    'credit_low_body' => '当前剩余积分 :remaining，低于预警阈值 :threshold',
    'subscription_expiring_title' => '订阅即将过期',
    'subscription_expiring_body' => '订阅将在 :days 天后过期',
    'payment_success_title' => '支付成功',
    'payment_success_body' => '订单 :orderNo 支付成功',
    'marked_read' => '已标记为已读',
    'all_marked_read' => '全部已标记为已读',
    'deleted' => '通知已删除',
    'read_cleared' => '已清空已读通知',
    'not_found' => '通知不存在',

    'mail_templates' => [
        'not_found' => '邮件模板不存在',
        'invalid_status' => '无效的模板状态',
        'status_activated' => '已激活',
        'status_disabled' => '已停用',
        'types' => [
            'billing' => '账单',
            'notification' => '通知',
            'welcome' => '欢迎',
            'reset' => '密码重置',
        ],
        'names' => [
            'welcome_registration' => '欢迎注册邮件',
            'password_reset' => '密码重置邮件',
            'payment_success' => '支付成功通知',
            'invoice_generated' => '账单生成通知',
            'subscription_expiring' => '订阅到期提醒',
            'tenant_suspended' => '团队暂停通知',
        ],
    ],
];
