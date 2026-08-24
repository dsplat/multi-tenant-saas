<?php

return [
    /*
     * SSL 证书目录（服务器上的数据路径，由软链接挂载，www-data 可写）
     * 建议: /app/ssl-certs
     * 服务器初始化:
     *   sudo mkdir -p /app/ssl-certs
     *   sudo chown www-data:www-data /app/ssl-certs && sudo chmod 750 /app/ssl-certs
     */
    'certs_path' => env('SSL_CERTS_PATH', '/app/ssl-certs'),

    /*
     * nginx SSL Map 文件路径
     * 写入后由 systemd path unit 监听目录变更，自动触发 nginx -s reload
     * 放在证书目录下，同一监听源
     */
    'nginx_map_file' => env('SSL_NGINX_MAP_FILE', '/app/ssl-certs/ssl-map.conf'),

    /*
     * ACME 自动签发（Let's Encrypt HTTP-01 webroot 模式，acme.sh 脚本驱动）
     *
     * 前置：域名已 approved（nginx 白名单放行 80 端口）且 DNS 指向平台。
     * 挑战文件由 acme.sh 写入 webroot/.well-known/acme-challenge/，
     * 租户基桩 location ^~ /.well-known/ 静态直出。
     * 续期由 acme.sh 自身 cron（--cron）全自动完成。
     */
    'acme' => [
        'enabled' => (bool) env('SSL_ACME_ENABLED', true),
        // acme.sh 主程序路径（生产以 root 安装于 /root/.acme.sh/）
        'binary' => env('SSL_ACME_BINARY', '/root/.acme.sh/acme.sh'),
        'server' => env('SSL_ACME_SERVER', 'letsencrypt'),
        // HTTP-01 webroot（默认应用 public 目录）
        'webroot' => env('SSL_ACME_WEBROOT', ''),
        // 单域名签发超时（秒）
        'timeout' => (int) env('SSL_ACME_TIMEOUT', 180),
    ],
];
