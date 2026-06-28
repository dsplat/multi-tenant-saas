<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Gateway Messages
    |--------------------------------------------------------------------------
    |
    | Shared messages for AiGatewayService and AiProviderContract implementations.
    | Placeholders: :provider, :model, :seconds.
    |
    */

    // Gateway-level errors
    'streaming_disabled' => 'Streaming output is disabled',
    'model_deprecated' => 'Model :model is deprecated',
    'rate_limited' => 'Too many requests, please retry after :seconds seconds',
    'provider_not_implemented' => 'Provider :provider is not implemented',
    'invalid_messages' => 'Messages list cannot be empty',
    'invalid_prompt' => 'Prompt text cannot be empty',
    'invalid_input' => 'Input text cannot be empty',

    // Provider-level errors (thrown by each Provider)
    'provider_not_configured' => 'Provider :provider API Key is not configured',
    'model_not_supported' => 'Provider :provider does not support model :model',
    'provider_auth_failed' => 'Provider :provider authentication failed',
    'provider_permission_denied' => 'Provider :provider permission denied',
    'provider_not_found' => 'Provider :provider resource not found',
    'provider_timeout' => 'Provider :provider request timed out',
    'provider_request_too_large' => 'Provider :provider request body too large',
    'provider_rate_limited' => 'Provider :provider rate limit triggered',
    'provider_server_error' => 'Provider :provider server error',
    'provider_api_error' => 'Provider :provider API error',
    'provider_connection_error' => 'Provider :provider connection failed',

    // Text AI / prompt templates (thrown by AiTextService)
    'json_parse_failed' => 'Failed to parse JSON from AI response',
    'json_mode_disabled' => 'JSON mode is disabled',
    'prompt_not_found' => 'Prompt template not found',
    'prompt_not_active' => 'Prompt template is inactive',
    'prompt_name_required' => 'Prompt template name cannot be empty',
    'prompt_name_exists' => 'Prompt template name already exists',
    'prompt_variable_missing' => 'Missing required variable: :name',
    'prompt_variable_invalid' => 'Variable :name has invalid type',
    'prompt_system_only' => 'System-level templates can only be managed in admin',

    // Image AI (thrown by AiImageService, DalleProvider, StableDiffusionProvider)
    'image_size_not_supported' => 'Provider :provider does not support size :size',
    'image_quality_not_supported' => 'Provider :provider does not support quality :quality',
    'image_style_not_supported' => 'Provider :provider does not support style :style',
    'image_operation_not_supported' => 'Provider :provider does not support operation :operation',
    'image_input_not_found' => 'Input image not found',
    'image_mask_not_found' => 'Mask image not found',
    'image_prompt_too_long' => 'Image prompt exceeds length limit (max :max chars)',

    // Video AI (thrown by AiVideoService, RunwayProvider, KlingProvider)
    'video_input_not_found' => 'Input video not found',
    'video_prompt_too_long' => 'Video prompt exceeds length limit (max :max chars)',
    'video_resolution_not_supported' => 'Provider :provider does not support resolution :resolution',
    'video_duration_not_supported' => 'Provider :provider does not support duration :duration seconds',
    'video_frame_count_invalid' => 'Frame count must be greater than 0',
    'video_task_not_found' => 'Video task not found',
    'video_task_not_completed' => 'Video task is not completed yet',
    'video_task_failed' => 'Video task failed: :reason',
    'video_task_timeout' => 'Video task polling timed out',
    'video_output_unavailable' => 'Video task completed but no downloadable output',
];
