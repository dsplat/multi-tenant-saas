<?php

return [
    'already_used' => '该域名已被其他租户使用',
    'not_configured' => '租户未配置自定义域名',
    'tenant_not_found_by_domain' => '未找到 custom_domain = :domain 的租户。',
    'existing_custom_domains' => '现有自定义域名：',
    'tenant_info' => '租户：:name（ID: :id）',
    'old_domain' => '旧域名',
    'new_domain' => '新域名',
    'confirm_update' => '确认更新？',
    'db_updated' => '✓ 数据库已更新。',
    'regenerating_nginx_map' => '重新生成 nginx 白名单...',
    'nginx_map_regenerated' => '✓ nginx 白名单已重新生成。',
    'nginx_map_failed' => '⚠ nginx 白名单生成时出现问题，请手动检查。',
    'manual_reload_hint' => '提示：请在服务器上执行以下命令更新 nginx 白名单：',
    'generating_nginx_config' => '开始生成Nginx域名白名单配置...',
    'config_generated' => '✓ 配置文件已生成: :path',
    'reloading_nginx' => '正在reload Nginx配置...',
    'nginx_reloaded' => '✓ Nginx已reload: :result',
    'nginx_test_failed' => '✗ Nginx配置测试失败，未执行reload',
];
