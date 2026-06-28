<?php

return [
    'subscribe_success' => 'Subscription successful',
    'cancel_success' => 'Auto-renewal cancelled, will downgrade to free plan at expiry',
    'change_success' => 'Plan changed successfully',
    'plan_not_deletable' => 'Free plan cannot be deleted',
    'plan_not_available' => 'Subscription plan not available',
    'trial_started' => 'Trial started',
    'expired_downgraded' => 'Subscription expired, downgraded to free plan',
    'auto_renew_failed' => 'Auto renewal failed',
    'quota_members' => 'Members',
    'quota_credits' => 'Credits',
    'quota_storage' => 'Storage',

    // Dunning
    'dunning_retry_scheduled' => 'Payment retry scheduled, will be charged on :date',
    'dunning_payment_failed' => 'Payment failed, dunning process started',
    'dunning_grace_period' => 'Within grace period, please complete payment to avoid suspension',
    'dunning_suspended' => 'Service suspended due to payment failure beyond grace period',
    'dunning_expiry_reminder_7d' => 'Subscription will expire in 7 days',
    'dunning_expiry_reminder_3d' => 'Subscription will expire in 3 days',
    'dunning_expiry_reminder_1d' => 'Subscription will expire in 1 day',

    // Usage metering
    'usage_limit_exceeded' => 'Usage limit exceeded',
    'usage_overage_charged' => 'Overage charged at :price for the exceeded portion',
    'usage_hard_limit_reached' => 'Hard usage limit reached, request rejected',

    // Plan change
    'plan_change_upgraded' => 'Plan upgraded',
    'plan_change_downgraded' => 'Plan downgraded',
    'plan_change_proration_charged' => 'Proration charge of :amount applied',
    'plan_change_proration_credited' => 'Proration credit of :amount applied',
];
