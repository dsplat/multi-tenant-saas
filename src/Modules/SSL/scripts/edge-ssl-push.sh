#!/usr/bin/env bash
# edge-ssl-push.sh — 源站侧：将租户证书推送到边缘节点并触发同步
#
# 触发时机：
#   1. acme.sh 签发/续期后（--reloadcmd 链式调用，root）
#   2. 租户上传/删除证书后（TenantSslController::refreshNginx，sudo 白名单）
#   3. 手动：bash edge-ssl-push.sh
#
# 依赖：执行用户已配置免密 SSH 到边缘（~/.ssh/config 中 Host edge）
#   - root：/root/.ssh/edge_deploy
#   - www-data：/var/www/.ssh/edge_deploy（同一私钥，PHP 进程经 sudo 白名单以 root 执行本脚本）
# 边缘侧同步脚本内嵌于本脚本（heredoc 写入暂存区），边缘无需预装。
#
# 环境变量：
#   SSL_EDGE_ENABLED   是否启用边缘推送（默认 true）
#   SSL_EDGE_HOST      SSH 目标（默认 edge）
#   SSL_EDGE_STAGING   边缘暂存目录（默认 edge-ssl-staging，相对边缘 $HOME）
#   SSL_CERTS_PATH     源站证书目录（默认 /etc/nginx/ssl）
set -uo pipefail

ENABLED="${SSL_EDGE_ENABLED:-true}"
[ "$ENABLED" = "true" ] || { echo "[edge-ssl-push] 未启用（SSL_EDGE_ENABLED=$ENABLED）"; exit 0; }

EDGE_HOST="${SSL_EDGE_HOST:-edge}"
STAGING="${SSL_EDGE_STAGING:-edge-ssl-staging}"
CERTS="${SSL_CERTS_PATH:-/etc/nginx/ssl}"

SSH="ssh -o BatchMode=yes -o ConnectTimeout=8"

# ---- 1. 组装推送目录：<certs>/<domain>.{crt,key} → /tmp 暂存 ----
TMP="$(mktemp -d /tmp/edge-ssl-push.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

shopt -s nullglob
manifest=()
for crt in "$CERTS"/*.crt; do
    domain="$(basename "$crt" .crt)"
    key="$CERTS/$domain.key"
    if [ ! -f "$key" ]; then
        echo "[edge-ssl-push] skip $domain: 缺 .key" >&2
        continue
    fi
    mkdir -p "$TMP/$domain"
    cp "$crt" "$TMP/$domain/$domain.crt"
    cp "$key" "$TMP/$domain/$domain.key"
    manifest+=("$domain")
done

if [ "${#manifest[@]}" -eq 0 ]; then
    echo "[edge-ssl-push] 无租户证书可推送"
    exit 0
fi
printf '%s\n' "${manifest[@]}" > "$TMP/.manifest"

# ---- 2. 内嵌边缘同步脚本 ----
cat > "$TMP/edge-ssl-sync.sh" <<'EDGE_SYNC_EOF'
#!/usr/bin/env bash
# edge-ssl-sync.sh — 边缘节点租户证书同步（由源站 edge-ssl-push.sh 内嵌分发）
set -euo pipefail

STAGING="${EDGE_STAGING_DIR:-$HOME/edge-ssl-staging}"
CERT_BASE="/etc/nginx/ssl/tenants"
CONF_DIR="/etc/nginx/conf.d/tenants"
BACKEND="${EDGE_BACKEND:-http://192.168.10.159:7100}"
MANIFEST="$STAGING/.manifest"

sudo mkdir -p "$CERT_BASE" "$CONF_DIR"

# 1. 安装暂存区证书（校验证书/私钥匹配，坏证书不入库）
if [ -d "$STAGING" ]; then
    for dir in "$STAGING"/*/; do
        [ -d "$dir" ] || continue
        domain="$(basename "$dir")"
        case "$domain" in .|..) continue ;; esac
        if [ ! -f "$dir/$domain.crt" ] || [ ! -f "$dir/$domain.key" ]; then
            echo "[edge-ssl-sync] skip $domain: 缺 .crt/.key" >&2
            continue
        fi
        cert_mod="$(openssl x509 -noout -modulus -in "$dir/$domain.crt" | md5sum)"
        key_mod="$(openssl rsa -noout -modulus -in "$dir/$domain.key" 2>/dev/null | md5sum)"
        if [ "$cert_mod" != "$key_mod" ]; then
            echo "[edge-ssl-sync] skip $domain: 证书与私钥不匹配" >&2
            continue
        fi
        sudo mkdir -p "$CERT_BASE/$domain"
        sudo install -m 644 "$dir/$domain.crt" "$CERT_BASE/$domain/fullchain.pem"
        sudo install -m 600 "$dir/$domain.key" "$CERT_BASE/$domain/privkey.pem"
        echo "[edge-ssl-sync] installed $domain"
    done
fi

# 2. 为每个已安装证书生成独立 server 块（带标记头，仅管理自生成文件）
for dir in "$CERT_BASE"/*/; do
    [ -d "$dir" ] || continue
    domain="$(basename "$dir")"
    [ -f "$CERT_BASE/$domain/fullchain.pem" ] && [ -f "$CERT_BASE/$domain/privkey.pem" ] || continue
    sudo tee "$CONF_DIR/$domain.conf" > /dev/null <<CONF
# edge-ssl-sync generated — do not edit
server {
    listen 80;
    server_name $domain;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name $domain;

    ssl_certificate $CERT_BASE/$domain/fullchain.pem;
    ssl_certificate_key $CERT_BASE/$domain/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        proxy_pass $BACKEND;
        proxy_set_header Host \$host;
        proxy_set_header X-Original-Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_http_version 1.1;
        proxy_set_header Connection '';
        proxy_buffering off;
        proxy_connect_timeout 10s;
        proxy_send_timeout 600s;
        proxy_read_timeout 600s;
    }

    access_log /var/log/nginx/tenant-$domain.log;
    error_log /var/log/nginx/tenant-$domain.error.log;
}
CONF
done

# 3. 孤儿清理：源站已删除的证书（仅清理带标记头的自生成配置）
if [ -f "$MANIFEST" ]; then
    for conf in "$CONF_DIR"/*.conf; do
        [ -f "$conf" ] || continue
        grep -q "edge-ssl-sync generated" "$conf" || continue
        fname="$(basename "$conf" .conf)"
        if ! grep -qx "$fname" "$MANIFEST"; then
            sudo rm -f "$conf" "$CERT_BASE/$fname/fullchain.pem" "$CERT_BASE/$fname/privkey.pem"
            sudo rmdir "$CERT_BASE/$fname" 2>/dev/null || true
            echo "[edge-ssl-sync] removed orphan $fname"
        fi
    done
fi

# 4. 校验并 reload
if sudo nginx -t > /tmp/edge-ssl-sync-nginx-test.log 2>&1; then
    sudo nginx -s reload
    echo "[edge-ssl-sync] nginx reloaded OK"
else
    cat /tmp/edge-ssl-sync-nginx-test.log >&2
    echo "[edge-ssl-sync] nginx -t 失败，未 reload" >&2
    exit 1
fi
EDGE_SYNC_EOF

# ---- 3. 推送到边缘暂存区（--delete 保证删除能同步） ----
if ! rsync -az --delete -e "$SSH" "$TMP/" "$EDGE_HOST:$STAGING/"; then
    echo "[edge-ssl-push] rsync 到 $EDGE_HOST 失败" >&2
    exit 1
fi
echo "[edge-ssl-push] 已推送 ${#manifest[@]} 个证书: ${manifest[*]}"

# ---- 4. 触发边缘同步（安装 + 生成配置 + reload） ----
if $SSH "$EDGE_HOST" "bash $STAGING/edge-ssl-sync.sh"; then
    echo "[edge-ssl-push] 边缘同步完成"
else
    echo "[edge-ssl-push] 边缘同步失败" >&2
    exit 1
fi
