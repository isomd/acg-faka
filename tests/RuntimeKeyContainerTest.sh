#!/usr/bin/env bash
set -euo pipefail

# Run ONLY in GitHub Actions. Generated fixtures have no production credentials.
fixture_dir="$(mktemp -d "$RUNNER_TEMP/acg-key-fixture.XXXXXXXX")"
chmod 0755 "$fixture_dir"
trap 'rm -f "$fixture_dir/key" "$fixture_dir/config.json"; rmdir "$fixture_dir"' EXIT
php -r '
require "vendor/autoload.php";
$dir=$argv[1];
$secret="test-only-".bin2hex(random_bytes(16));
file_put_contents($dir."/key", $secret);
chmod($dir."/key", 0600);
file_put_contents($dir."/config.json", \Kernel\Util\EncryptedDeploymentConfig::encrypt([
    "database"=>["host"=>"db.internal", "database"=>"shop", "username"=>"shop", "password"=>"test-only-db-password"],
    "redis"=>["enabled"=>false],
], $secret));
' "$fixture_dir"

# Actual entrypoint, actual tmpfs and actual FPM user. No database connection.
docker run --rm \
  --tmpfs /run/acg-config:rw,noexec,nosuid,nodev,size=1m,mode=0700 \
  --mount "type=bind,src=$fixture_dir,dst=/fixtures,readonly" \
  -e ACG_ENCRYPTED_CONFIG=/fixtures/config.json \
  -e ACG_CONFIG_KEY_FILE=/fixtures/key \
  "$IMAGE" /bin/sh -ec '
    test "$(stat -c %a /run/acg-config/derived-key.json)" = 440
    test "$(stat -c %U:%G /run/acg-config/derived-key.json)" = root:www-data
    test "$(stat -c %a /run/acg-config)" = 750
    runuser -u www-data -- test ! -r /fixtures/key
    runuser -u www-data -- test ! -w /run/acg-config/derived-key.json
    runuser -u www-data -- test ! -w /run/acg-config
    runuser -u www-data -- php -d zend.exception_ignore_args=1 -r '\''
      require "/var/www/html/vendor/autoload.php";
      $start=microtime(true);
      $db=\Kernel\Util\EncryptedDeploymentConfig::database();
      if ($db["host"]!=="db.internal") exit(1);
      if (microtime(true)-$start>0.5) throw new RuntimeException("Runtime path unexpectedly slow");
    '\''
    echo "Container startup key, tmpfs and permissions passed"
  '

# Explicit encrypted mode without tmpfs must refuse to start; it must not write
# a derived key into the container image layer as a silent fallback.
if docker run --rm \
  --mount "type=bind,src=$fixture_dir,dst=/fixtures,readonly" \
  -e ACG_ENCRYPTED_CONFIG=/fixtures/config.json \
  -e ACG_CONFIG_KEY_FILE=/fixtures/key \
  "$IMAGE" /bin/true; then
    echo 'Encrypted startup unexpectedly succeeded without tmpfs' >&2
    exit 1
fi
