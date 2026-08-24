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
     * SNI 证书 map 文件：默认与 Domain 模块部署目录对齐，
     * 唯一事实源由 NginxConfigService::generateSslMap 生成，避免双写漂移。
     * 变更后由调用方触发 domains:generate-nginx --reload 生效。
     */
    'nginx_map_file' => env('SSL_NGINX_MAP_FILE', base_path('deploy/nginx/maps/ssl.map')),

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
        // 显式声明环境可用性（config 缓存下唯一可靠来源）；未设置则回退二进制探测。
        // Web 进程（www-data）无法探测 /root/.acme.sh，生产应显式设为 true。
        'available' => env('SSL_ACME_AVAILABLE'),
        // acme.sh 主程序路径（生产以 root 安装于 /root/.acme.sh/）
        'binary' => env('SSL_ACME_BINARY', '/root/.acme.sh/acme.sh'),
        'server' => env('SSL_ACME_SERVER', 'letsencrypt'),
        // HTTP-01 webroot（默认应用 public 目录）
        'webroot' => env('SSL_ACME_WEBROOT', ''),
        // 单域名签发超时（秒）
        'timeout' => (int) env('SSL_ACME_TIMEOUT', 180),
    ],

    /*
     * 边缘节点证书推送（源站签发/上传后自动同步到终结 443 的边缘节点）
     *
     * 链路：push 脚本 rsync 证书 → 边缘 sync 脚本安装 + 生成 server 块 + reload
     * push 脚本随本模块分发：<module>/scripts/edge-ssl-push.sh
     * 边缘侧脚本经 rsync 随暂存区同步，无需单独部署。
     */
    'edge' => [
        'enabled' => (bool) env('SSL_EDGE_ENABLED', false),
        'push_script' => env('SSL_EDGE_PUSH_SCRIPT', ''),
        'host' => env('SSL_EDGE_HOST', 'edge'),
        'staging' => env('SSL_EDGE_STAGING', 'edge-ssl-staging'),
    ],
];
