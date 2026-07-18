<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Seed: roles (14 rows)
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (1264552901951924,NULL,'customer_insight','客户洞察','对应 CustomerInsightAgent — 客户情感分析、流失预测、画像分析和对话质量评估',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (1454484572332186,NULL,'end_user','普通用户','终端用户角色',1,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (2411930942217401,NULL,'super_admin','超级管理员','系统级管理角色',1,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (3802839228767291,NULL,'tenant_admin','租户管理员','租户管理角色',1,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (4001394228890269,NULL,'platform_user','平台用户','平台运营角色',1,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (4849472136750775,NULL,'marketing','营销策划','对应 MarketingPlanningAgent — 营销内容生成、话术推荐、活动策划和数据分析',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (5316865286797270,NULL,'design_production','设计制作','对应 DesignProductionAgent — 营销素材设计、图片提示词生成、视频脚本制作和内容创作',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (5366063320234810,NULL,'quality_inspection','质检','对应 QualityInspectionAgent — 对话质量检测、合规审核、敏感词拦截和客户情绪监控',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (5735348902702654,NULL,'operations','运营','对应 OperationsAgent — 社群运营、群发任务编排、SOP执行和数据分析',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (6692409044138623,NULL,'data_analysis','数据分析','对应 DataAnalysisAgent — 客户分析、流失预测、意向判断、趋势分析和报表生成',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (6731798802068768,NULL,'platform_support','平台支持','平台客服支持角色，拥有查看权限及成员管理权限',1,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (7122372001234435,NULL,'sales','销售','对应 SalesAgent — 客户意向分析、跟进话术推荐和销售计划管理',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (7381199573839280,NULL,'platform_admin','平台管理员','平台运营管理角色，拥有除租户核心操作外的所有权限',1,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `display_name`, `description`, `is_system`, `created_at`, `updated_at`) VALUES (7577176192469874,NULL,'customer_service','客服','对应 CustomerServiceAgent — 客户咨询、问题解答、售后支持和投诉处理',1,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);

        // Seed: permissions (72 rows)
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (1040115352558967,'ssl.manage','SSL管理','ssl','管理SSL证书','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (1363989099719568,'faq.view','查看FAQ','faq','查看常见问题知识库','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (1507230908682125,'campaign.update','更新活动','campaign','更新营销活动','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (1607120163831110,'material.delete','删除素材','material','删除素材','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (1896574930394716,'tenant.activate','恢复租户','tenant','恢复已暂停的租户','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2067235977823942,'material.create','创建素材','material','上传/创建素材','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2122553006432944,'analytics.view','数据分析','analytics','查看数据分析结果','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2363466575863210,'subscription.manage','订阅管理','subscription','管理订阅计划','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2433779542919645,'tenant.suspend','暂停租户','tenant','暂停租户','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2535111088288363,'community.view','查看社群','community','查看社群列表','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2620439006449975,'conversation.reply','回复会话','conversation','回复客户消息','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2659522779607423,'tenant.delete','删除租户','tenant','删除租户','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2747609850282737,'asset.view','查看素材库','asset','查看素材资源库','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2798318145578628,'compliance.view','查看合规','compliance','查看合规审核记录','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2807359299917792,'setting.view','查看配置','setting','查看租户配置','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2831916600399740,'payment.create','创建支付','payment','创建支付订单','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2851379429585378,'material.view','查看素材','material','查看营销素材','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (2985489741175065,'ticket.update','更新工单','ticket','更新工单状态','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3077834970341474,'member.view','查看成员','member','查看成员列表','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3087685312396443,'payment.view','查看支付','payment','查看支付订单','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3099849988652617,'rbac.manage','权限管理','rbac','管理角色和权限','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3141595925916559,'contact.update','更新联系人','contact','更新联系人信息','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3271856413674388,'sop.view','查看SOP','sop','查看SOP流程','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3286061385421795,'insight.view','客户洞察','insight','查看客户画像、情感分析、流失预测','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3469004643247918,'ticket.view','查看工单','ticket','查看客服工单','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3553708178066102,'broadcast.create','创建群发','broadcast','创建群发任务','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3594705078497896,'tenant.view','查看租户','tenant','查看租户详情','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (3819646240520818,'file.upload','上传文件','file','上传文件','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (4211924417016905,'quality.view','查看质检','quality','查看质检结果','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (4396033441797236,'member.delete','移除成员','member','从租户移除成员','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (4507811293243282,'file.delete','删除文件','file','删除文件','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (4569549183306607,'customer.view','查看客户','customer','查看客户列表和详情','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (4798424091587585,'dashboard.view','查看仪表盘','dashboard','查看数据仪表盘','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5017284839878609,'conversation.export','导出会话','conversation','导出对话记录','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5253802383371969,'audit.view','查看审计','audit','查看审计日志','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5548354420684374,'broadcast.view','查看群发','broadcast','查看群发任务','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5583689973347444,'credit.recharge','积分充值','credit','充值积分','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5723577550261159,'campaign.create','创建活动','campaign','创建营销活动','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5771423400686490,'credit.adjust','积分调整','credit','手动调整积分','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5825344318496889,'member.update','更新成员','member','更新成员信息','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5861039669887972,'ticket.create','创建工单','ticket','创建客服工单','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5882946549112494,'tenant.update','更新租户','tenant','更新租户信息','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (5889433987467907,'sales.view','查看销售','sales','查看销售计划和跟进记录','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6061193925840984,'compliance.manage','管理合规','compliance','配置合规规则和敏感词','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6112942541530994,'payment.refund','发起退款','payment','发起退款请求','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6134480963016838,'customer.update','更新客户','customer','更新客户信息','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6255401658733547,'report.export','导出报表','report','导出报表数据','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6596902169883336,'channel.send','发送消息','channel','通过渠道发送消息','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6827737771411332,'tenant.create','创建租户','tenant','创建新租户','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (6978296858353899,'broadcast.send','执行群发','broadcast','执行群发发送','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7008390330789090,'community.manage','管理社群','community','管理社群配置和成员','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7019776087971991,'material.update','更新素材','material','更新素材信息','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7152315726284483,'channel.view','查看渠道','channel','查看渠道配置','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7311620284254604,'credit.view','查看积分','credit','查看积分账户','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7498011196101332,'tag.view','查看标签','tag','查看标签体系','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7676785990965266,'conversation.view','查看会话','conversation','查看对话记录','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7683961869142976,'sales.update','更新销售','sales','更新销售计划和跟进记录','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7764581070232231,'customer.export','导出客户','customer','导出客户数据','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7900194198436816,'member.create','添加成员','member','向租户添加成员','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7942284192277393,'tag.manage','管理标签','tag','创建/编辑/删除标签','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (7969094676292810,'report.view','查看报表','report','查看业务报表','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8024944528056609,'contact.view','查看联系人','contact','查看联系人列表','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8195253679478735,'campaign.delete','删除活动','campaign','删除营销活动','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8307255481128602,'template.view','查看模板','template','查看消息/文案模板','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8313495696812905,'report.create','创建报表','report','创建自定义报表','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8407830697123238,'quality.manage','管理质检','quality','配置质检规则和标准','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8450121316221811,'domain.manage','域名管理','domain','管理域名配置','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8532157681839102,'setting.update','更新配置','setting','更新租户配置','2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8732837729058096,'asset.upload','上传素材','asset','上传素材到资源库','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8833984561258377,'sop.manage','管理SOP','sop','创建/编辑/删除SOP','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8834847223404059,'template.create','创建模板','template','创建模板','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `permissions` (`permission_id`, `name`, `display_name`, `group`, `description`, `created_at`, `updated_at`) VALUES (8901442712462846,'campaign.view','查看活动','campaign','查看营销活动','2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);

        // Seed: role_permissions (266 rows)
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (1,3802839228767291,5253802383371969,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (2,3802839228767291,5771423400686490,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (3,3802839228767291,5583689973347444,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (4,3802839228767291,7311620284254604,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (5,3802839228767291,8450121316221811,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (6,3802839228767291,4507811293243282,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (7,3802839228767291,3819646240520818,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (8,3802839228767291,7900194198436816,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (9,3802839228767291,4396033441797236,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (10,3802839228767291,5825344318496889,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (11,3802839228767291,3077834970341474,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (12,3802839228767291,2831916600399740,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (13,3802839228767291,6112942541530994,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (14,3802839228767291,3087685312396443,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (15,3802839228767291,3099849988652617,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (16,3802839228767291,8532157681839102,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (17,3802839228767291,2807359299917792,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (18,3802839228767291,1040115352558967,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (19,3802839228767291,2363466575863210,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (20,3802839228767291,1896574930394716,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (21,3802839228767291,5882946549112494,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (22,3802839228767291,3594705078497896,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (23,1454484572332186,5253802383371969,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (24,1454484572332186,7311620284254604,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (25,1454484572332186,3819646240520818,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (26,1454484572332186,3077834970341474,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (27,1454484572332186,3087685312396443,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (28,1454484572332186,2807359299917792,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (29,1454484572332186,3594705078497896,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (30,2411930942217401,5253802383371969,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (31,2411930942217401,5771423400686490,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (32,2411930942217401,5583689973347444,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (33,2411930942217401,7311620284254604,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (34,2411930942217401,8450121316221811,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (35,2411930942217401,4507811293243282,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (36,2411930942217401,3819646240520818,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (37,2411930942217401,7900194198436816,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (38,2411930942217401,4396033441797236,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (39,2411930942217401,5825344318496889,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (40,2411930942217401,3077834970341474,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (41,2411930942217401,2831916600399740,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (42,2411930942217401,6112942541530994,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (43,2411930942217401,3087685312396443,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (44,2411930942217401,3099849988652617,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (45,2411930942217401,8532157681839102,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (46,2411930942217401,2807359299917792,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (47,2411930942217401,1040115352558967,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (48,2411930942217401,2363466575863210,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (49,2411930942217401,1896574930394716,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (50,2411930942217401,6827737771411332,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (51,2411930942217401,2659522779607423,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (52,2411930942217401,2433779542919645,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (53,2411930942217401,5882946549112494,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (54,2411930942217401,3594705078497896,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (55,7381199573839280,5253802383371969,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (56,7381199573839280,5771423400686490,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (57,7381199573839280,5583689973347444,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (58,7381199573839280,7311620284254604,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (59,7381199573839280,8450121316221811,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (60,7381199573839280,4507811293243282,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (61,7381199573839280,3819646240520818,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (62,7381199573839280,7900194198436816,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (63,7381199573839280,4396033441797236,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (64,7381199573839280,5825344318496889,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (65,7381199573839280,3077834970341474,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (66,7381199573839280,2831916600399740,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (67,7381199573839280,6112942541530994,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (68,7381199573839280,3087685312396443,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (69,7381199573839280,3099849988652617,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (70,7381199573839280,8532157681839102,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (71,7381199573839280,2807359299917792,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (72,7381199573839280,1040115352558967,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (73,7381199573839280,2363466575863210,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (74,7381199573839280,1896574930394716,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (75,7381199573839280,5882946549112494,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (76,7381199573839280,3594705078497896,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (77,6731798802068768,5253802383371969,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (78,6731798802068768,7311620284254604,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (79,6731798802068768,7900194198436816,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (80,6731798802068768,5825344318496889,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (81,6731798802068768,3077834970341474,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (82,6731798802068768,3087685312396443,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (83,6731798802068768,3099849988652617,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (84,6731798802068768,2807359299917792,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (85,6731798802068768,3594705078497896,'2026-07-15 19:56:43','2026-07-15 19:56:43');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (86,7122372001234435,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (87,7122372001234435,3141595925916559,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (88,7122372001234435,8024944528056609,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (89,7122372001234435,7676785990965266,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (90,7122372001234435,6134480963016838,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (91,7122372001234435,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (92,7122372001234435,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (93,7122372001234435,3819646240520818,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (94,7122372001234435,7969094676292810,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (95,7122372001234435,7683961869142976,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (96,7122372001234435,5889433987467907,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (97,7122372001234435,7498011196101332,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (98,7577176192469874,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (99,7577176192469874,2620439006449975,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (100,7577176192469874,7676785990965266,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (101,7577176192469874,6134480963016838,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (102,7577176192469874,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (103,7577176192469874,1363989099719568,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (104,7577176192469874,3819646240520818,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (105,7577176192469874,7498011196101332,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (106,7577176192469874,5861039669887972,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (107,7577176192469874,2985489741175065,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (108,7577176192469874,3469004643247918,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (109,4849472136750775,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (110,4849472136750775,5723577550261159,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (111,4849472136750775,8195253679478735,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (112,4849472136750775,1507230908682125,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (113,4849472136750775,8901442712462846,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (114,4849472136750775,6596902169883336,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (115,4849472136750775,7152315726284483,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (116,4849472136750775,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (117,4849472136750775,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (118,4849472136750775,3819646240520818,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (119,4849472136750775,2067235977823942,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (120,4849472136750775,7019776087971991,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (121,4849472136750775,2851379429585378,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (122,4849472136750775,8834847223404059,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (123,4849472136750775,8307255481128602,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (124,1264552901951924,2122553006432944,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (125,1264552901951924,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (126,1264552901951924,7764581070232231,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (127,1264552901951924,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (128,1264552901951924,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (129,1264552901951924,3286061385421795,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (130,1264552901951924,7969094676292810,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (131,1264552901951924,7942284192277393,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (132,1264552901951924,7498011196101332,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (133,5735348902702654,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (134,5735348902702654,3553708178066102,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (135,5735348902702654,6978296858353899,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (136,5735348902702654,5548354420684374,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (137,5735348902702654,7152315726284483,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (138,5735348902702654,7008390330789090,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (139,5735348902702654,2535111088288363,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (140,5735348902702654,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (141,5735348902702654,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (142,5735348902702654,3819646240520818,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (143,5735348902702654,8833984561258377,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (144,5735348902702654,3271856413674388,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (145,5366063320234810,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (146,5366063320234810,6061193925840984,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (147,5366063320234810,2798318145578628,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (148,5366063320234810,5017284839878609,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (149,5366063320234810,7676785990965266,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (150,5366063320234810,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (151,5366063320234810,8407830697123238,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (152,5366063320234810,4211924417016905,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (153,5366063320234810,7969094676292810,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (154,6692409044138623,2122553006432944,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (155,6692409044138623,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (156,6692409044138623,7764581070232231,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (157,6692409044138623,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (158,6692409044138623,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (159,6692409044138623,8313495696812905,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (160,6692409044138623,6255401658733547,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (161,6692409044138623,7969094676292810,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (162,5316865286797270,8732837729058096,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (163,5316865286797270,2747609850282737,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (164,5316865286797270,5253802383371969,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (165,5316865286797270,4507811293243282,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (166,5316865286797270,3819646240520818,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (167,5316865286797270,2067235977823942,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (168,5316865286797270,1607120163831110,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (169,5316865286797270,7019776087971991,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (170,5316865286797270,2851379429585378,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (171,5316865286797270,8834847223404059,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (172,5316865286797270,8307255481128602,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (173,2411930942217401,2122553006432944,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (174,2411930942217401,8732837729058096,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (175,2411930942217401,2747609850282737,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (176,2411930942217401,3553708178066102,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (177,2411930942217401,6978296858353899,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (178,2411930942217401,5548354420684374,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (179,2411930942217401,5723577550261159,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (180,2411930942217401,8195253679478735,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (181,2411930942217401,1507230908682125,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (182,2411930942217401,8901442712462846,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (183,2411930942217401,6596902169883336,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (184,2411930942217401,7152315726284483,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (185,2411930942217401,7008390330789090,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (186,2411930942217401,2535111088288363,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (187,2411930942217401,6061193925840984,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (188,2411930942217401,2798318145578628,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (189,2411930942217401,3141595925916559,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (190,2411930942217401,8024944528056609,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (191,2411930942217401,5017284839878609,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (192,2411930942217401,2620439006449975,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (193,2411930942217401,7676785990965266,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (194,2411930942217401,7764581070232231,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (195,2411930942217401,6134480963016838,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (196,2411930942217401,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (197,2411930942217401,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (198,2411930942217401,1363989099719568,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (199,2411930942217401,3286061385421795,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (200,2411930942217401,2067235977823942,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (201,2411930942217401,1607120163831110,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (202,2411930942217401,7019776087971991,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (203,2411930942217401,2851379429585378,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (204,2411930942217401,8407830697123238,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (205,2411930942217401,4211924417016905,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (206,2411930942217401,8313495696812905,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (207,2411930942217401,6255401658733547,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (208,2411930942217401,7969094676292810,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (209,2411930942217401,7683961869142976,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (210,2411930942217401,5889433987467907,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (211,2411930942217401,8833984561258377,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (212,2411930942217401,3271856413674388,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (213,2411930942217401,7942284192277393,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (214,2411930942217401,7498011196101332,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (215,2411930942217401,8834847223404059,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (216,2411930942217401,8307255481128602,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (217,2411930942217401,5861039669887972,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (218,2411930942217401,2985489741175065,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (219,2411930942217401,3469004643247918,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (220,3802839228767291,2122553006432944,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (221,3802839228767291,8732837729058096,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (222,3802839228767291,2747609850282737,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (223,3802839228767291,3553708178066102,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (224,3802839228767291,6978296858353899,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (225,3802839228767291,5548354420684374,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (226,3802839228767291,5723577550261159,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (227,3802839228767291,8195253679478735,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (228,3802839228767291,1507230908682125,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (229,3802839228767291,8901442712462846,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (230,3802839228767291,6596902169883336,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (231,3802839228767291,7152315726284483,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (232,3802839228767291,7008390330789090,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (233,3802839228767291,2535111088288363,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (234,3802839228767291,6061193925840984,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (235,3802839228767291,2798318145578628,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (236,3802839228767291,3141595925916559,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (237,3802839228767291,8024944528056609,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (238,3802839228767291,5017284839878609,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (239,3802839228767291,2620439006449975,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (240,3802839228767291,7676785990965266,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (241,3802839228767291,7764581070232231,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (242,3802839228767291,6134480963016838,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (243,3802839228767291,4569549183306607,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (244,3802839228767291,4798424091587585,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (245,3802839228767291,1363989099719568,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (246,3802839228767291,3286061385421795,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (247,3802839228767291,2067235977823942,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (248,3802839228767291,1607120163831110,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (249,3802839228767291,7019776087971991,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (250,3802839228767291,2851379429585378,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (251,3802839228767291,8407830697123238,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (252,3802839228767291,4211924417016905,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (253,3802839228767291,8313495696812905,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (254,3802839228767291,6255401658733547,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (255,3802839228767291,7969094676292810,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (256,3802839228767291,7683961869142976,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (257,3802839228767291,5889433987467907,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (258,3802839228767291,8833984561258377,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (259,3802839228767291,3271856413674388,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (260,3802839228767291,7942284192277393,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (261,3802839228767291,7498011196101332,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (262,3802839228767291,8834847223404059,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (263,3802839228767291,8307255481128602,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (264,3802839228767291,5861039669887972,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (265,3802839228767291,2985489741175065,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`, `updated_at`) VALUES (266,3802839228767291,3469004643247918,'2026-07-17 02:38:54','2026-07-17 02:38:54');
SQL);

        // Seed: system_settings (3 rows)
        DB::statement(<<<'SQL'
INSERT INTO `system_settings` (`setting_id`, `group`, `key`, `value`, `is_encrypted`, `description`, `created_at`, `updated_at`) VALUES (1370142404058342,'scrm','scrm_platform_tenant_id','7152382912837150',0,NULL,'2026-07-15 20:12:49','2026-07-15 20:12:49');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `system_settings` (`setting_id`, `group`, `key`, `value`, `is_encrypted`, `description`, `created_at`, `updated_at`) VALUES (1831534896689824,'scrm','scrm_initialized_at','2026-07-16 04:12:49',0,NULL,'2026-07-15 20:12:49','2026-07-15 20:12:49');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `system_settings` (`setting_id`, `group`, `key`, `value`, `is_encrypted`, `description`, `created_at`, `updated_at`) VALUES (1970029981532572,'scrm','scrm_version','1.0.0',0,NULL,'2026-07-15 20:12:49','2026-07-15 20:12:49');
SQL);

        // Seed: subscription_plans (4 rows)
        DB::statement(<<<'SQL'
INSERT INTO `subscription_plans` (`subscription_plan_id`, `name`, `display_name`, `description`, `price_monthly`, `price_yearly`, `trial_days`, `features`, `limits`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (4566353822138644,'enterprise','企业版','适合大型企业定制化需求',99900,999000,30,'[\"basic_api\", \"priority_support\", \"custom_branding\", \"export_data\", \"advanced_analytics\", \"api_webhooks\", \"sso\", \"dedicated_support\", \"sla_guarantee\", \"white_label\"]','{\"max_users\": null, \"max_storage_mb\": null, \"api_calls_daily\": null}',1,4,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `subscription_plans` (`subscription_plan_id`, `name`, `display_name`, `description`, `price_monthly`, `price_yearly`, `trial_days`, `features`, `limits`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (5903389286024902,'basic','基础版','适合小型企业日常使用',9900,99000,14,'[\"basic_api\", \"priority_support\", \"custom_branding\", \"export_data\"]','{\"max_users\": 20, \"max_storage_mb\": 10240, \"api_calls_daily\": 10000}',1,2,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `subscription_plans` (`subscription_plan_id`, `name`, `display_name`, `description`, `price_monthly`, `price_yearly`, `trial_days`, `features`, `limits`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (6513558135712826,'pro','专业版','适合中型企业深度使用',29900,299000,14,'[\"basic_api\", \"priority_support\", \"custom_branding\", \"export_data\", \"advanced_analytics\", \"api_webhooks\", \"sso\"]','{\"max_users\": 100, \"max_storage_mb\": 51200, \"api_calls_daily\": 50000}',1,3,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);
        DB::statement(<<<'SQL'
INSERT INTO `subscription_plans` (`subscription_plan_id`, `name`, `display_name`, `description`, `price_monthly`, `price_yearly`, `trial_days`, `features`, `limits`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (8043649604368741,'free','免费版','适合个人和小团队试用',0,0,0,'[\"basic_api\", \"community_support\"]','{\"max_users\": 5, \"max_storage_mb\": 1024, \"api_calls_daily\": 1000}',1,1,'2026-07-11 06:53:22','2026-07-11 06:53:22');
SQL);

        // Seed: branding_configs (1 rows)
        DB::statement(<<<'SQL'
INSERT INTO `branding_configs` (`branding_config_id`, `tenant_id`, `logo_url`, `favicon_url`, `primary_color`, `secondary_color`, `custom_css`, `custom_domain`, `login_page_style`, `email_template`, `created_at`, `updated_at`, `deleted_at`) VALUES (2060496291108046,7152382912837150,NULL,NULL,'#1890ff','#666666',NULL,NULL,'default','default','2026-07-17 05:42:40','2026-07-17 05:42:40',NULL);
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::table('roles')->truncate();
        DB::table('permissions')->truncate();
        DB::table('role_permissions')->truncate();
        DB::table('system_settings')->truncate();
        DB::table('subscription_plans')->truncate();
        DB::table('branding_configs')->truncate();
    }
};
