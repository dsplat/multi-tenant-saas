<?php

return [
    'default_tenant_id' => null,
    
    'admin_domain' => env('ADMIN_DOMAIN', 'admin.example.com'),
    
    'platform_domains' => [
        'localhost',
        '127.0.0.1',
        env('ADMIN_DOMAIN', 'admin.example.com'),
    ],
    
    'cache' => [
        'prefix' => 'tenant:',
        'ttl' => 3600,
    ],
];
