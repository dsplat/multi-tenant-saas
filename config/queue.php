<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 默认队列连接
    |--------------------------------------------------------------------------
    |
    | 生产环境使用 redis 连接获得高吞吐；database 连接作为轻量备选。
    | 测试环境使用 sync 连接保证同步可断言。
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | 队列连接
    |--------------------------------------------------------------------------
    |
    | redis: 高吞吐、支持 block_for 长轮询、block_for > 0 时减少空轮询 CPU 开销
    | database: 轮询 jobs 表，retry_after 需小于任务平均耗时避免重复执行
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => true,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => env('REDIS_QUEUE_BLOCK_FOR', null) !== null
                ? (int) env('REDIS_QUEUE_BLOCK_FOR')
                : null,
            'after_commit' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 队列分组与优先级
    |--------------------------------------------------------------------------
    |
    | 按业务重要性分离队列，避免低优先级任务（如导出）阻塞高优先级任务（如支付）。
    | worker 启动示例：php artisan queue:work redis --queue=high,default
    |
    */

    'queues' => [
        'high' => env('QUEUE_HIGH', 'high'),           // 支付、订阅等关键业务
        'default' => env('QUEUE_DEFAULT', 'default'), // 通知、审计等
        'low' => env('QUEUE_LOW', 'low'),             // 导出、报表、清理
    ],

    /*
    |--------------------------------------------------------------------------
    | 批处理配置（Job Batching）
    |--------------------------------------------------------------------------
    |
    | 用于批量任务（如批量导出、批量分发 Webhook）的进度追踪。
    | 依赖数据库表 job_batches。
    |
    */

    'batching' => [
        'database' => env('DB_BATCHING_CONNECTION', env('DB_CONNECTION', 'mysql')),
        'table' => env('DB_BATCHING_TABLE', 'job_batches'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 失败任务
    |--------------------------------------------------------------------------
    |
    | failed_jobs 表保留失败任务便于重试与审计。
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
    ],

];
