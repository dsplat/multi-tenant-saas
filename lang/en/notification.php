<?php

return [
    'tenant_suspended_title' => 'Tenant Suspended',
    'tenant_suspended_body' => 'Your tenant has been suspended',
    'credit_low_title' => 'Low Credit Balance',
    'credit_low_body' => 'Current balance :remaining is below threshold :threshold',
    'subscription_expiring_title' => 'Subscription Expiring',
    'subscription_expiring_body' => 'Subscription will expire in :days days',
    'payment_success_title' => 'Payment Successful',
    'payment_success_body' => 'Order :orderNo payment successful',
    'marked_read' => 'Marked as read',
    'all_marked_read' => 'All marked as read',
    'deleted' => 'Notification deleted',
    'read_cleared' => 'Read notifications cleared',
    'not_found' => 'Notification not found',

    'mail_templates' => [
        'not_found' => 'Mail template not found',
        'invalid_status' => 'Invalid template status',
        'status_activated' => 'Activated',
        'status_disabled' => 'Disabled',
        'types' => [
            'billing' => 'Billing',
            'notification' => 'Notification',
            'welcome' => 'Welcome',
            'reset' => 'Password Reset',
        ],
        'names' => [
            'welcome_registration' => 'Welcome Registration Email',
            'password_reset' => 'Password Reset Email',
            'payment_success' => 'Payment Success Notice',
            'invoice_generated' => 'Invoice Generated Notice',
            'subscription_expiring' => 'Subscription Expiring Reminder',
            'tenant_suspended' => 'Tenant Suspended Notice',
        ],
    ],
];
