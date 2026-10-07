#!/usr/bin/env bash
set -euo pipefail
docker ps --format '{{.Names}}\t{{.Image}}\t{{.Ports}}'
command -v nginx || true
command -v certbot || true
command -v openssl || true
command -v crontab || true
ss -ltn | awk 'NR == 1 || $4 ~ /:(80|443|8089)$/'
for container in $(docker ps --format '{{.Names}}'); do
    image=$(docker inspect --format '{{.Config.Image}}' "$container")
    if [[ "$image" == *nginx* || "$image" == *caddy* || "$image" == *traefik* ]]; then
        echo "PROXY_CONTAINER=$container"
        docker inspect --format '{{json .Mounts}}' "$container"
        docker inspect --format 'NETWORK={{.HostConfig.NetworkMode}}' "$container"
        docker exec "$container" sh -c 'nginx -T 2>&1' | awk '/^# configuration file / || /^[[:space:]]*(listen|server_name|ssl_certificate|ssl_certificate_key|include)[[:space:]]/ {print}' || true
    fi
done
if command -v nginx >/dev/null 2>&1; then
    nginx -T 2>&1 | awk '/^# configuration file / || /^[[:space:]]*(listen|server_name|ssl_certificate|ssl_certificate_key|include)[[:space:]]/ {print}'
fi
docker exec acg-faka-app php -r 'echo "APP_HTTP=" . trim((string)file_get_contents("http://127.0.0.1/healthz")) . PHP_EOL;'
