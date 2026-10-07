#!/usr/bin/env bash
set -Eeuo pipefail

# 由 GitHub Actions 经 SSH 调用。标准输入只包含配置解密密钥。
image="${1:?缺少镜像名}"
base="${2:-/srv/acg-faka}"
http_port="${3:-80}"
[[ "$image" =~ ^[a-z0-9._/-]+:sha-[a-f0-9]{40}$ ]] || { echo '镜像名格式无效' >&2; exit 1; }
[[ "$base" == /* && "$base" != / && "$base" != *'..'* ]] || { echo '部署目录无效' >&2; exit 1; }
[[ "$http_port" =~ ^[0-9]{1,5}$ ]] && (( http_port >= 1 && http_port <= 65535 )) || { echo 'HTTP 端口无效' >&2; exit 1; }

IFS= read -r config_key
[[ ${#config_key} -ge 12 && "$config_key" != *$'\r'* ]] || { echo '解密密钥格式无效' >&2; exit 1; }

umask 077
install -d -m 700 "$base" "$base/secrets"
install -d -m 755 "$base/data"
key_path="$base/secrets/config-key-${image##*:}"
key_tmp="$(mktemp "$base/secrets/.config-key.XXXXXXXX")"
printf '%s\n' "$config_key" > "$key_tmp"
chmod 600 "$key_tmp"
mv "$key_tmp" "$key_path"
unset config_key

# 与参考流水线相同：服务器不登录 Docker Hub，直接拉取公开镜像。
docker pull "$image"

name='acg-faka-app'
previous='acg-faka-previous'
had_previous=0
if docker container inspect "$name" >/dev/null 2>&1; then
    had_previous=1
    if docker container inspect "$previous" >/dev/null 2>&1; then
        docker rm -f "$previous" >/dev/null
    fi
    docker stop "$name" >/dev/null
    docker rename "$name" "$previous"
fi

rollback() {
    echo '新版本检查失败，恢复上一容器' >&2
    docker rm -f "$name" >/dev/null 2>&1 || true
    if (( had_previous )); then
        docker rename "$previous" "$name"
        docker start "$name" >/dev/null
    fi
}
trap rollback ERR

docker run -d --name "$name" --restart unless-stopped \
    -p "${http_port}:80" \
    --add-host host.docker.internal:host-gateway \
    --tmpfs /run/acg-config:rw,noexec,nosuid,nodev,size=1m,mode=0700 \
    --mount "type=bind,src=$base/data,dst=/data" \
    --mount "type=bind,src=$key_path,dst=/run/secrets/acg-deploy-key,readonly" \
    -e ACG_ENCRYPTED_CONFIG=/opt/acg-faka/database.enc.json \
    -e ACG_CONFIG_KEY_FILE=/run/secrets/acg-deploy-key \
    "$image" >/dev/null

healthy=0
for attempt in $(seq 1 30); do
    if curl --fail --silent --max-time 3 "http://127.0.0.1:${http_port}/healthz" | grep -qx 'pong'; then
        healthy=1
        break
    fi
    sleep 2
done
if (( ! healthy )); then
    echo 'HTTP 健康检查未通过' >&2
    false
fi

docker exec "$name" php -r '
require "/var/www/html/vendor/autoload.php";
$db = \Kernel\Util\EncryptedDeploymentConfig::database();
$pdo = new PDO("mysql:host={$db["host"]};port={$db["port"]};dbname={$db["database"]};charset=utf8mb4", $db["username"], $db["password"], [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->query("SELECT 1")->fetchColumn();
if ((\Kernel\Util\EncryptedDeploymentConfig::load()["redis"]["enabled"] ?? false) === true) {
    \Kernel\Util\EncryptedDeploymentConfig::configureSession();
    if (session_start() === false) throw new RuntimeException("Redis 会话连接失败");
    session_write_close();
}
' >/dev/null

trap - ERR
echo "发布成功：$image，HTTP 端口 $http_port；旧容器保留为 $previous"
