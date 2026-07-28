#!/usr/bin/env php
<?php

/**
 * 批量生成框架模块 Tool Handler 文件
 *
 * 用法: php scripts/generate_module_tools.php
 */
$basePath = dirname(__DIR__) . '/src/Modules';

// ============================================================
// 工具定义：module => [slug, ServiceClass, method, params, risk]
// params: [name => [type, description, required?]]
// ============================================================

$modules = [

    // ==================== SMS (扩充) ====================
    'Sms' => [
        'namespace' => 'MultiTenantSaas\Modules\Sms\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Sms\Services\SmsService',
        'serviceProp' => 'SmsService',
        'tools' => [
            ['sms_create_template', 'SmsCreateTemplateHandler', 'createTemplate', 'L2',
                ['name' => 'string,模板名称,1', 'content' => 'string,模板内容,1', 'variables' => 'array,变量列表', 'type' => 'string,模板类型']],
            ['sms_update_template', 'SmsUpdateTemplateHandler', 'updateTemplate', 'L2',
                ['template_id' => 'integer,模板ID,1', 'name' => 'string,模板名称', 'content' => 'string,模板内容', 'status' => 'string,状态']],
            ['sms_submit_for_approval', 'SmsSubmitForApprovalHandler', 'submitForApproval', 'L2',
                ['template_id' => 'integer,模板ID,1']],
            ['sms_render_content', 'SmsRenderContentHandler', 'renderContent', 'L1',
                ['template_id' => 'integer,模板ID,1', 'variables' => 'object,模板变量']],
            ['sms_scheduled_send', 'SmsScheduledSendHandler', 'scheduledSend', 'L2',
                ['template_id' => 'integer,模板ID,1', 'phones' => 'array,手机号列表,1', 'scheduled_at' => 'string,定时时间,1']],
            ['sms_get_batch_task', 'SmsGetBatchTaskHandler', 'getBatchTask', 'L1',
                ['task_id' => 'integer,任务ID,1']],
            ['sms_cancel_batch_task', 'SmsCancelBatchTaskHandler', 'cancelBatchTask', 'L2',
                ['task_id' => 'integer,任务ID,1']],
            ['sms_get_delivery_stats', 'SmsGetDeliveryStatsHandler', 'getDeliveryStats', 'L1',
                ['batch_task_id' => 'integer,批次任务ID,1']],
            ['sms_unsubscribe', 'SmsUnsubscribeHandler', 'unsubscribe', 'L2',
                ['phone' => 'string,手机号,1', 'reason' => 'string,退订原因']],
            ['sms_get_unsubscribes', 'SmsGetUnsubscribesHandler', 'getUnsubscribes', 'L1',
                []],
        ],
    ],

    // ==================== Lottery (扩充) ====================
    'Lottery' => [
        'namespace' => 'MultiTenantSaas\Modules\Lottery\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Lottery\Services\LotteryService',
        'serviceProp' => 'LotteryService',
        'tools' => [
            ['lottery_create_activity', 'LotteryCreateActivityHandler', 'createActivity', 'L2',
                ['name' => 'string,活动名称,1', 'type' => 'string,活动类型', 'start_at' => 'string,开始时间', 'end_at' => 'string,结束时间']],
            ['lottery_update_activity', 'LotteryUpdateActivityHandler', 'updateActivity', 'L2',
                ['activity_id' => 'integer,活动ID,1', 'name' => 'string,活动名称', 'status' => 'string,状态']],
            ['lottery_get_activity', 'LotteryGetActivityHandler', 'getActivity', 'L1',
                ['activity_id' => 'integer,活动ID,1']],
            ['lottery_update_status', 'LotteryUpdateStatusHandler', 'updateActivityStatus', 'L2',
                ['activity_id' => 'integer,活动ID,1', 'status' => 'string,状态,1']],
            ['lottery_add_prize', 'LotteryAddPrizeHandler', 'addPrize', 'L2',
                ['activity_id' => 'integer,活动ID,1', 'name' => 'string,奖品名称,1', 'probability' => 'number,中奖概率', 'stock' => 'integer,库存']],
            ['lottery_update_prize', 'LotteryUpdatePrizeHandler', 'updatePrize', 'L2',
                ['prize_id' => 'integer,奖品ID,1', 'name' => 'string,奖品名称', 'probability' => 'number,概率', 'stock' => 'integer,库存']],
            ['lottery_delete_prize', 'LotteryDeletePrizeHandler', 'deletePrize', 'L2',
                ['prize_id' => 'integer,奖品ID,1']],
            ['lottery_get_prizes', 'LotteryGetPrizesHandler', 'getPrizes', 'L1',
                ['activity_id' => 'integer,活动ID,1']],
            ['lottery_get_draw_stats', 'LotteryGetDrawStatsHandler', 'getDrawStats', 'L1',
                ['activity_id' => 'integer,活动ID,1']],
            ['lottery_get_win_logs', 'LotteryGetWinLogsHandler', 'getWinLogs', 'L1',
                ['activity_id' => 'integer,活动ID,1']],
            ['lottery_get_user_draw_logs', 'LotteryGetUserDrawLogsHandler', 'getUserDrawLogs', 'L1',
                ['activity_id' => 'integer,活动ID,1', 'user_id' => 'integer,用户ID']],
            ['lottery_get_blacklist', 'LotteryGetBlacklistHandler', 'getBlacklist', 'L1',
                ['activity_id' => 'integer,活动ID,1']],
            ['lottery_add_blacklist', 'LotteryAddBlacklistHandler', 'addToBlacklist', 'L2',
                ['tenant_id' => 'integer,租户ID', 'activity_id' => 'integer,活动ID,1', 'identifier_type' => 'string,标识类型,1', 'identifier' => 'string,标识值,1', 'reason' => 'string,原因']],
            ['lottery_remove_blacklist', 'LotteryRemoveBlacklistHandler', 'removeFromBlacklist', 'L2',
                ['activity_id' => 'integer,活动ID,1', 'identifier_type' => 'string,标识类型,1', 'identifier' => 'string,标识值,1']],
        ],
    ],

    // ==================== Coupon (扩充) ====================
    'Coupon' => [
        'namespace' => 'MultiTenantSaas\Modules\Coupon\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Coupon\Services\CouponService',
        'serviceProp' => 'CouponService',
        'tools' => [
            ['coupon_create', 'CouponCreateHandler', 'createCoupon', 'L2',
                ['name' => 'string,优惠券名称,1', 'type' => 'string,类型,1', 'value' => 'number,面值,1', 'threshold' => 'number,使用门槛']],
            ['coupon_create_template', 'CouponCreateTemplateHandler', 'createTemplate', 'L2',
                ['name' => 'string,模板名称,1', 'type' => 'string,类型,1', 'value' => 'number,面值,1']],
            ['coupon_update_template', 'CouponUpdateTemplateHandler', 'updateTemplate', 'L2',
                ['template_id' => 'integer,模板ID,1', 'name' => 'string,名称', 'status' => 'string,状态']],
            ['coupon_activate', 'CouponActivateHandler', 'activate', 'L2',
                ['coupon_id' => 'integer,优惠券ID,1']],
            ['coupon_deactivate', 'CouponDeactivateHandler', 'deactivate', 'L2',
                ['coupon_id' => 'integer,优惠券ID,1']],
            ['coupon_redeem', 'CouponRedeemHandler', 'redeem', 'L2',
                ['code' => 'string,兑换码,1']],
            ['coupon_generate_codes', 'CouponGenerateCodesHandler', 'generateCodes', 'L2',
                ['prefix' => 'string,前缀,1', 'quantity' => 'integer,数量,1']],
            ['coupon_get_statistics', 'CouponGetStatisticsHandler', 'getStatistics', 'L1',
                ['coupon_id' => 'integer,优惠券ID,1']],
            ['coupon_get_usages', 'CouponGetUsagesHandler', 'getUsages', 'L1',
                ['coupon_id' => 'integer,优惠券ID,1']],
            ['coupon_calculate_discount', 'CouponCalculateDiscountHandler', 'calculateDiscount', 'L1',
                ['code' => 'string,优惠券码,1', 'amount' => 'number,订单金额,1']],
            ['coupon_bulk_distribute', 'CouponBulkDistributeHandler', 'bulkDistribute', 'L2',
                ['coupon_id' => 'integer,优惠券ID,1', 'user_ids' => 'array,用户ID列表,1']],
        ],
    ],

    // ==================== Voting (扩充) ====================
    'Voting' => [
        'namespace' => 'MultiTenantSaas\Modules\Voting\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Voting\Services\VotingService',
        'serviceProp' => 'VotingService',
        'tools' => [
            ['voting_create', 'VotingCreateHandler', 'createVote', 'L2',
                ['title' => 'string,投票标题,1', 'options' => 'array,选项列表,1', 'start_at' => 'string,开始时间', 'end_at' => 'string,结束时间']],
            ['voting_update', 'VotingUpdateHandler', 'updateVote', 'L2',
                ['vote_id' => 'integer,投票ID,1', 'title' => 'string,标题', 'status' => 'string,状态']],
            ['voting_get_records', 'VotingGetRecordsHandler', 'getRecords', 'L1',
                ['vote_id' => 'integer,投票ID,1']],
            ['voting_get_statistics', 'VotingGetStatisticsHandler', 'getStatistics', 'L1',
                ['vote_id' => 'integer,投票ID,1']],
        ],
    ],

    // ==================== Knowledge (扩充) ====================
    'Knowledge' => [
        'namespace' => 'MultiTenantSaas\Modules\Knowledge\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Knowledge\Services\ExternalKbService',
        'serviceProp' => 'ExternalKbService',
        'tools' => [
            ['knowledge_list_connections', 'KnowledgeListConnectionsHandler', 'listConnections', 'L1',
                []],
            ['knowledge_create_connection', 'KnowledgeCreateConnectionHandler', 'createConnection', 'L2',
                ['name' => 'string,连接名称,1', 'provider_type' => 'string,提供者类型,1', 'config' => 'object,配置,1']],
            ['knowledge_update_connection', 'KnowledgeUpdateConnectionHandler', 'updateConnection', 'L2',
                ['connection_id' => 'integer,连接ID,1', 'name' => 'string,名称', 'config' => 'object,配置']],
            ['knowledge_delete_connection', 'KnowledgeDeleteConnectionHandler', 'deleteConnection', 'L2',
                ['connection_id' => 'integer,连接ID,1']],
            ['knowledge_test_connection', 'KnowledgeTestConnectionHandler', 'testConnection', 'L1',
                ['connection_id' => 'integer,连接ID,1']],
            ['knowledge_push_document', 'KnowledgePushDocumentHandler', 'pushDocument', 'L2',
                ['connection_id' => 'integer,连接ID,1', 'name' => 'string,文档名称,1', 'content' => 'string,文档内容,1']],
        ],
    ],

    // ==================== Form (扩充) ====================
    'Form' => [
        'namespace' => 'MultiTenantSaas\Modules\Form\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Form\Services\FormBuilderService',
        'serviceProp' => 'FormBuilderService',
        'tools' => [
            ['form_create', 'FormCreateHandler', 'createForm', 'L2',
                ['title' => 'string,表单标题,1', 'fields' => 'array,字段定义,1', 'description' => 'string,描述']],
            ['form_update', 'FormUpdateHandler', 'updateForm', 'L2',
                ['form_id' => 'integer,表单ID,1', 'title' => 'string,标题', 'status' => 'string,状态']],
            ['form_get_submissions', 'FormGetSubmissionsHandler', 'getSubmissions', 'L1',
                ['form_id' => 'integer,表单ID,1']],
            ['form_get_statistics', 'FormGetStatisticsHandler', 'getStatistics', 'L1',
                ['form_id' => 'integer,表单ID,1']],
            ['form_export_data', 'FormExportDataHandler', 'exportData', 'L1',
                ['form_id' => 'integer,表单ID,1', 'format' => 'string,导出格式']],
        ],
    ],

    // ==================== Billing (新增) ====================
    'Billing' => [
        'namespace' => 'MultiTenantSaas\Modules\Billing\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Billing\Services\SubscriptionService',
        'serviceProp' => 'SubscriptionService',
        'tools' => [
            ['billing_get_current_plan', 'BillingGetCurrentPlanHandler', 'getCurrentPlan', 'L1',
                []],
            ['billing_subscribe', 'BillingSubscribeHandler', 'subscribe', 'L2',
                ['plan_id' => 'integer,计划ID,1']],
            ['billing_change_plan', 'BillingChangePlanHandler', 'changePlan', 'L2',
                ['plan_id' => 'integer,新计划ID,1']],
            ['billing_cancel', 'BillingCancelHandler', 'cancel', 'L2',
                []],
            ['billing_start_trial', 'BillingStartTrialHandler', 'startTrial', 'L2',
                ['plan_id' => 'integer,计划ID,1']],
            ['billing_get_invoices', 'BillingGetInvoicesHandler', 'getInvoices', 'L1',
                []],
            ['billing_get_history', 'BillingGetHistoryHandler', 'getHistory', 'L1',
                []],
            ['billing_get_change_history', 'BillingGetChangeHistoryHandler', 'getChangeHistory', 'L1',
                []],
            ['billing_get_monthly_report', 'BillingGetMonthlyReportHandler', 'getMonthlyReport', 'L1',
                ['month' => 'string,月份 YYYY-MM']],
            ['billing_is_in_trial', 'BillingIsInTrialHandler', 'isInTrial', 'L1',
                []],
        ],
    ],

    // ==================== Conversation (新增) ====================
    'Conversation' => [
        'namespace' => 'MultiTenantSaas\Modules\Conversation\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Conversation\Services\ConversationService',
        'serviceProp' => 'ConversationService',
        'tools' => [
            ['conversation_list', 'ConversationListHandler', 'listConversations', 'L1',
                ['status' => 'string,状态过滤', 'per_page' => 'integer,每页数量']],
            ['conversation_get', 'ConversationGetHandler', 'getConversation', 'L1',
                ['conversation_id' => 'integer,会话ID,1']],
            ['conversation_create', 'ConversationCreateHandler', 'createConversation', 'L2',
                ['title' => 'string,标题,1', 'type' => 'string,类型', 'participant_ids' => 'array,参与者ID列表']],
            ['conversation_delete', 'ConversationDeleteHandler', 'deleteConversation', 'L2',
                ['conversation_id' => 'integer,会话ID,1']],
            ['conversation_list_messages', 'ConversationListMessagesHandler', 'listMessages', 'L1',
                ['conversation_id' => 'integer,会话ID,1', 'per_page' => 'integer,每页数量']],
            ['conversation_send_message', 'ConversationSendMessageHandler', 'sendMessage', 'L2',
                ['conversation_id' => 'integer,会话ID,1', 'content' => 'string,消息内容,1', 'type' => 'string,消息类型']],
            ['conversation_search_messages', 'ConversationSearchMessagesHandler', 'searchMessages', 'L1',
                ['query' => 'string,搜索关键词,1', 'conversation_id' => 'integer,会话ID']],
            ['conversation_get_timeline', 'ConversationGetTimelineHandler', 'getTimeline', 'L1',
                ['conversation_id' => 'integer,会话ID,1']],
            ['conversation_get_summary', 'ConversationGetSummaryHandler', 'getSummary', 'L1',
                ['conversation_id' => 'integer,会话ID,1']],
            ['conversation_get_unread_count', 'ConversationGetUnreadCountHandler', 'getUnreadCount', 'L1',
                []],
        ],
    ],

    // ==================== Notification (新增) ====================
    'Notification' => [
        'namespace' => 'MultiTenantSaas\Modules\Notification\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Notification\Services\NotificationService',
        'serviceProp' => 'NotificationService',
        'tools' => [
            ['notification_list', 'NotificationListHandler', 'list', 'L1',
                ['type' => 'string,类型过滤', 'per_page' => 'integer,每页数量']],
            ['notification_send_to_user', 'NotificationSendToUserHandler', 'sendToUser', 'L2',
                ['user_id' => 'integer,用户ID,1', 'title' => 'string,标题,1', 'content' => 'string,内容,1', 'channel' => 'string,渠道']],
            ['notification_send_to_tenant_users', 'NotificationSendToTenantUsersHandler', 'sendToTenantUsers', 'L2',
                ['title' => 'string,标题,1', 'content' => 'string,内容,1', 'channel' => 'string,渠道']],
            ['notification_get_unread_count', 'NotificationGetUnreadCountHandler', 'getUnreadCount', 'L1',
                []],
            ['notification_mark_as_read', 'NotificationMarkAsReadHandler', 'markAsRead', 'L2',
                ['notification_id' => 'integer,通知ID,1']],
            ['notification_mark_all_read', 'NotificationMarkAllReadHandler', 'markAllRead', 'L2',
                []],
            ['notification_get_preferences', 'NotificationGetPreferencesHandler', 'getPreferences', 'L1',
                []],
            ['notification_get_history', 'NotificationGetHistoryHandler', 'getHistory', 'L1',
                ['per_page' => 'integer,每页数量']],
        ],
    ],

    // ==================== Workflow (新增) ====================
    'Workflow' => [
        'namespace' => 'MultiTenantSaas\Modules\Workflow\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Workflow\Services\WorkflowService',
        'serviceProp' => 'WorkflowService',
        'tools' => [
            ['workflow_list', 'WorkflowListHandler', 'listForTenant', 'L1',
                ['status' => 'string,状态过滤']],
            ['workflow_get', 'WorkflowGetHandler', 'find', 'L1',
                ['workflow_id' => 'integer,工作流ID,1']],
            ['workflow_create', 'WorkflowCreateHandler', 'create', 'L2',
                ['name' => 'string,名称,1', 'definition' => 'object,流程定义,1', 'trigger' => 'string,触发条件']],
            ['workflow_update', 'WorkflowUpdateHandler', 'update', 'L2',
                ['workflow_id' => 'integer,工作流ID,1', 'name' => 'string,名称', 'definition' => 'object,定义']],
            ['workflow_delete', 'WorkflowDeleteHandler', 'delete', 'L2',
                ['workflow_id' => 'integer,工作流ID,1']],
            ['workflow_start', 'WorkflowStartHandler', 'startExecution', 'L2',
                ['workflow_id' => 'integer,工作流ID,1', 'input' => 'object,输入数据']],
            ['workflow_retry', 'WorkflowRetryHandler', 'retry', 'L2',
                ['workflow_id' => 'integer,工作流ID,1']],
            ['workflow_rollback', 'WorkflowRollbackHandler', 'rollback', 'L2',
                ['workflow_id' => 'integer,工作流ID,1']],
        ],
    ],

    // ==================== User (新增) ====================
    'User' => [
        'namespace' => 'MultiTenantSaas\Modules\User\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\User\Services\UserService',
        'serviceProp' => 'UserService',
        'tools' => [
            ['user_list', 'UserListHandler', 'list', 'L1',
                ['status' => 'string,状态过滤', 'per_page' => 'integer,每页数量']],
            ['user_search', 'UserSearchHandler', 'search', 'L1',
                ['query' => 'string,搜索关键词,1']],
            ['user_get_profile', 'UserGetProfileHandler', 'getProfile', 'L1',
                ['user_id' => 'integer,用户ID,1']],
            ['user_create', 'UserCreateHandler', 'create', 'L2',
                ['name' => 'string,姓名,1', 'email' => 'string,邮箱', 'phone' => 'string,手机号']],
            ['user_update', 'UserUpdateHandler', 'update', 'L2',
                ['user_id' => 'integer,用户ID,1', 'name' => 'string,姓名', 'status' => 'string,状态']],
            ['user_toggle_status', 'UserToggleStatusHandler', 'toggleStatus', 'L2',
                ['user_id' => 'integer,用户ID,1']],
            ['user_get_login_logs', 'UserGetLoginLogsHandler', 'getLoginLogs', 'L1',
                ['user_id' => 'integer,用户ID,1']],
            ['user_get_devices', 'UserGetDevicesHandler', 'getDevices', 'L1',
                ['user_id' => 'integer,用户ID,1']],
            ['user_get_stats', 'UserGetStatsHandler', 'getUserStats', 'L1',
                ['user_id' => 'integer,用户ID,1']],
            ['user_get_tenants', 'UserGetTenantsHandler', 'getUserTenants', 'L1',
                ['user_id' => 'integer,用户ID,1']],
        ],
    ],

    // ==================== Storage (新增) ====================
    'Storage' => [
        'namespace' => 'MultiTenantSaas\Modules\Storage\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Storage\Services\FileService',
        'serviceProp' => 'FileService',
        'tools' => [
            ['storage_list_files', 'StorageListFilesHandler', 'listFiles', 'L1',
                ['path' => 'string,目录路径', 'per_page' => 'integer,每页数量']],
            ['storage_upload', 'StorageUploadHandler', 'upload', 'L2',
                ['path' => 'string,目标路径,1', 'content' => 'string,文件内容(base64),1', 'filename' => 'string,文件名,1']],
            ['storage_delete', 'StorageDeleteHandler', 'delete', 'L2',
                ['file_id' => 'integer,文件ID,1']],
            ['storage_download', 'StorageDownloadHandler', 'download', 'L1',
                ['file_id' => 'integer,文件ID,1']],
            ['storage_get_url', 'StorageGetUrlHandler', 'getUrl', 'L1',
                ['file_id' => 'integer,文件ID,1']],
            ['storage_create_share_url', 'StorageCreateShareUrlHandler', 'createShareUrl', 'L2',
                ['file_id' => 'integer,文件ID,1', 'expires_in' => 'integer,有效期秒数']],
            ['storage_get_usage', 'StorageGetUsageHandler', 'getStorageUsage', 'L1',
                []],
        ],
    ],

    // ==================== Event (新增) ====================
    'Event' => [
        'namespace' => 'MultiTenantSaas\Modules\Event\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Event\Services\BroadcastingService',
        'serviceProp' => 'BroadcastingService',
        'tools' => [
            ['event_get_history', 'EventGetHistoryHandler', 'getHistory', 'L1',
                ['per_page' => 'integer,每页数量']],
            ['event_broadcast_to_tenant', 'EventBroadcastToTenantHandler', 'broadcastToTenant', 'L2',
                ['event' => 'string,事件名,1', 'data' => 'object,事件数据,1']],
            ['event_broadcast_to_user', 'EventBroadcastToUserHandler', 'broadcastToUser', 'L2',
                ['user_id' => 'integer,用户ID,1', 'event' => 'string,事件名,1', 'data' => 'object,事件数据,1']],
            ['event_broadcast_announcement', 'EventBroadcastAnnouncementHandler', 'broadcastSystemAnnouncement', 'L2',
                ['title' => 'string,公告标题,1', 'content' => 'string,公告内容,1']],
        ],
    ],

    // ==================== Ticket (新增) ====================
    'Ticket' => [
        'namespace' => 'MultiTenantSaas\Modules\Ticket\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Ticket\Services\TicketService',
        'serviceProp' => 'TicketService',
        'tools' => [
            ['ticket_list', 'TicketListHandler', 'list', 'L1',
                ['status' => 'string,状态过滤', 'per_page' => 'integer,每页数量']],
            ['ticket_get', 'TicketGetHandler', 'find', 'L1',
                ['ticket_id' => 'integer,工单ID,1']],
            ['ticket_create', 'TicketCreateHandler', 'create', 'L2',
                ['subject' => 'string,主题,1', 'content' => 'string,内容,1', 'priority' => 'string,优先级']],
            ['ticket_update', 'TicketUpdateHandler', 'update', 'L2',
                ['ticket_id' => 'integer,工单ID,1', 'subject' => 'string,主题', 'status' => 'string,状态']],
            ['ticket_assign', 'TicketAssignHandler', 'assign', 'L2',
                ['ticket_id' => 'integer,工单ID,1', 'operator_id' => 'integer,处理人ID,1']],
            ['ticket_resolve', 'TicketResolveHandler', 'resolve', 'L2',
                ['ticket_id' => 'integer,工单ID,1', 'resolution' => 'string,解决方案']],
            ['ticket_add_comment', 'TicketAddCommentHandler', 'addComment', 'L2',
                ['ticket_id' => 'integer,工单ID,1', 'content' => 'string,评论内容,1']],
            ['ticket_get_comments', 'TicketGetCommentsHandler', 'getComments', 'L1',
                ['ticket_id' => 'integer,工单ID,1']],
        ],
    ],

    // ==================== Payment (新增) ====================
    'Payment' => [
        'namespace' => 'MultiTenantSaas\Modules\Payment\Services\Tools',
        'service' => 'MultiTenantSaas\Modules\Payment\Services\PaymentService',
        'serviceProp' => 'PaymentService',
        'tools' => [
            ['payment_create_order', 'PaymentCreateOrderHandler', 'createOrder', 'L2',
                ['amount' => 'number,金额,1', 'description' => 'string,描述', 'channel' => 'string,支付渠道']],
            ['payment_query_order', 'PaymentQueryOrderHandler', 'queryOrder', 'L1',
                ['order_id' => 'string,订单号,1']],
            ['payment_get_packages', 'PaymentGetPackagesHandler', 'getPackages', 'L1',
                []],
        ],
    ],

];

// ============================================================
// 生成 Handler 文件
// ============================================================

$generated = 0;
$skipped = 0;

foreach ($modules as $module => $config) {
    $dir = "$basePath/$module/Services/Tools";
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    foreach ($config['tools'] as $tool) {
        [$slug, $className, $method, $risk, $params] = $tool;
        $file = "$dir/$className.php";

        if (file_exists($file)) {
            $skipped++;

            continue;
        }

        $serviceClass = $config['serviceProp'];
        $serviceFqcn = $config['service'];

        // 构建 __invoke 方法体
        $body = buildMethodBody($method, $params);

        $php = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$config['namespace']};\n\nuse MultiTenantSaas\\Modules\\Ai\\Services\\Agent\\Contracts\\ToolHandlerContract;\nuse {$serviceFqcn};\n\nclass {$className} implements ToolHandlerContract\n{\n    public function __construct(private readonly {$serviceClass} \$service) {}\n\n    public function __invoke(array \$arguments, int \$tenantId): mixed\n    {\n{$body}\n    }\n}\n";

        file_put_contents($file, $php);
        $generated++;
    }
}

echo "Generated: $generated handlers, Skipped (existing): $skipped\n";

// ============================================================
// 辅助函数
// ============================================================

function buildMethodBody(string $method, array $params): string
{
    if (empty($params)) {
        // 无参方法 - 可能需要 tenantId
        return "        return \$this->service->{$method}(\$tenantId);";
    }

    $args = [];
    foreach ($params as $name => $def) {
        $parts = explode(',', $def);
        $type = $parts[0];
        $required = isset($parts[2]) && $parts[2] === '1';

        if ($type === 'integer') {
            $args[] = $required
                ? "(int) \$arguments['$name']"
                : "isset(\$arguments['$name']) ? (int) \$arguments['$name'] : null";
        } elseif ($type === 'number') {
            $args[] = $required
                ? "(float) \$arguments['$name']"
                : "isset(\$arguments['$name']) ? (float) \$arguments['$name'] : null";
        } elseif ($type === 'array' || $type === 'object') {
            $args[] = $required
                ? "\$arguments['$name']"
                : "\$arguments['$name'] ?? []";
        } else { // string
            $args[] = $required
                ? "\$arguments['$name']"
                : "\$arguments['$name'] ?? null";
        }
    }

    $argStr = implode(', ', $args);

    return "        return \$this->service->{$method}({$argStr});";
}
