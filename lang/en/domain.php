<?php

return [
    'already_used' => 'This domain is already used by another tenant',
    'not_configured' => 'Tenant has not configured a custom domain',
    'tenant_not_found_by_domain' => 'Tenant with custom_domain = :domain not found.',
    'existing_custom_domains' => 'Existing custom domains:',
    'tenant_info' => 'Tenant: :name (ID: :id)',
    'old_domain' => 'Old domain',
    'new_domain' => 'New domain',
    'confirm_update' => 'Confirm update?',
    'db_updated' => '✓ Database updated.',
    'regenerating_nginx_map' => 'Regenerating nginx whitelist...',
    'nginx_map_regenerated' => '✓ nginx whitelist regenerated.',
    'nginx_map_failed' => '⚠ Problem occurred while generating nginx whitelist, please check manually.',
    'manual_reload_hint' => 'Hint: Please run the following command on the server to update nginx whitelist:',
    'generating_nginx_config' => 'Starting to generate Nginx domain whitelist config...',
    'config_generated' => '✓ Config file generated: :path',
    'reloading_nginx' => 'Reloading Nginx config...',
    'nginx_reloaded' => '✓ Nginx reloaded: :result',
    'nginx_test_failed' => '✗ Nginx config test failed, reload not executed',
];
