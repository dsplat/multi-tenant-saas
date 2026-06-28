<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI 网关消息
    |--------------------------------------------------------------------------
    |
    | AiGatewayService 与各 AiProviderContract 实现共用的提示与错误消息。
    | 占位符示例：:provider、:model、:seconds。
    |
    */

    // 网关级错误
    'streaming_disabled' => '流式输出已被禁用',
    'model_deprecated' => '模型 :model 已废弃',
    'rate_limited' => '请求过于频繁，请在 :seconds 秒后重试',
    'provider_not_implemented' => '提供商 :provider 暂未实现',
    'invalid_messages' => '消息列表不能为空',
    'invalid_prompt' => '提示文本不能为空',
    'invalid_input' => '输入文本不能为空',

    // 提供商级错误（由各 Provider 抛出）
    'provider_not_configured' => '提供商 :provider 未配置 API Key',
    'model_not_supported' => '提供商 :provider 不支持模型 :model',
    'provider_auth_failed' => '提供商 :provider 鉴权失败',
    'provider_permission_denied' => '提供商 :provider 权限不足',
    'provider_not_found' => '提供商 :provider 资源不存在',
    'provider_timeout' => '提供商 :provider 请求超时',
    'provider_request_too_large' => '提供商 :provider 请求体过大',
    'provider_rate_limited' => '提供商 :provider 触发限流',
    'provider_server_error' => '提供商 :provider 服务端异常',
    'provider_api_error' => '提供商 :provider 接口异常',
    'provider_connection_error' => '提供商 :provider 连接失败',

    // 文本 AI / 提示词模板（由 AiTextService 抛出）
    'json_parse_failed' => 'AI 返回内容 JSON 解析失败',
    'json_mode_disabled' => 'JSON 模式已被禁用',
    'prompt_not_found' => '提示词模板不存在',
    'prompt_not_active' => '提示词模板已停用',
    'prompt_name_required' => '提示词模板名称不能为空',
    'prompt_name_exists' => '提示词模板名称已存在',
    'prompt_variable_missing' => '缺少必需变量：:name',
    'prompt_variable_invalid' => '变量 :name 类型非法',
    'prompt_system_only' => '系统级模板仅能在后台管理',

    // 图片 AI（由 AiImageService、DalleProvider、StableDiffusionProvider 抛出）
    'image_size_not_supported' => '提供商 :provider 不支持尺寸 :size',
    'image_quality_not_supported' => '提供商 :provider 不支持质量 :quality',
    'image_style_not_supported' => '提供商 :provider 不支持风格 :style',
    'image_operation_not_supported' => '提供商 :provider 不支持操作 :operation',
    'image_input_not_found' => '输入图片不存在',
    'image_mask_not_found' => '遮罩图不存在',
    'image_prompt_too_long' => '图片提示文本超出长度限制（最大 :max 字符）',

    // 视频 AI（由 AiVideoService、RunwayProvider、KlingProvider 抛出）
    'video_input_not_found' => '输入视频不存在',
    'video_prompt_too_long' => '视频提示文本超出长度限制（最大 :max 字符）',
    'video_resolution_not_supported' => '提供商 :provider 不支持分辨率 :resolution',
    'video_duration_not_supported' => '提供商 :provider 不支持时长 :duration 秒',
    'video_frame_count_invalid' => '帧数量必须大于 0',
    'video_task_not_found' => '视频任务不存在',
    'video_task_not_completed' => '视频任务尚未完成',
    'video_task_failed' => '视频任务失败：:reason',
    'video_task_timeout' => '视频任务轮询超时',
    'video_output_unavailable' => '视频任务完成但未返回可下载结果',

    // 租户 AI 配置（由 AiConfigService 抛出）
    'tenant_config_not_found' => '租户 AI 配置不存在',
    'ai_capability_disabled' => 'AI 能力 :category 未启用',
    'ai_model_not_allowed' => '模型 :model 不在允许使用的列表中',
    'overage_action_invalid' => '超额处理策略 :action 非法，仅支持 block/warn/allow',
    'custom_api_key_required' => '提供商 :provider 未配置自定义 API Key',
    'config_import_invalid' => '配置导入数据格式非法',

    // 租户 AI 用量（由 AiUsageService 抛出）
    'text_quota_exceeded' => '文本 AI Token 用量已达上限（已用 :used / 上限 :limit）',
    'image_quota_exceeded' => '图片 AI 生成次数已达上限（已用 :used / 上限 :limit）',
    'video_quota_exceeded' => '视频 AI 时长已达上限（已用 :used / 上限 :limit）',
    'budget_exceeded' => 'AI 月度预算已达上限（已用 :used / 预算 :limit）',
    'quota_warning' => 'AI 用量告警：:category 已使用 :percent%（:used / :limit）',
];
