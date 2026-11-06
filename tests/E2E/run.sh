#!/usr/bin/env bash
# Browser E2E against a disposable WordPress (private MariaDB from bin/test-db.sh, PHP built-in server, Playwright).
# Usage: tests/E2E/run.sh [workdir]
# Needs: bin/test-db.sh start, tests/wp vendor (WordPress core copy), npm ci --prefix tests/E2E + npx playwright-core install chromium
#        Optional overrides: PW_DIR (node_modules), CHROME (Chromium executable).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${1:-$(mktemp -d)}"
PORT=8089
mkdir -p "$WORK"
WORK="$(cd "$WORK" && pwd)"
export SWC_E2E_WP="$WORK/wp"
rm -rf "$SWC_E2E_WP" "$WORK/wp-private" && cp -r "$ROOT/tests/wp/vendor/johnpbloch/wordpress-core" "$SWC_E2E_WP"
mkdir -p "$SWC_E2E_WP/wp-content/plugins" "$WORK/shots" && ln -s "$ROOT" "$SWC_E2E_WP/wp-content/plugins/sw-cloudcalendar"
MYSQL_PWD="${SWC_TEST_DB_PASSWORD:-swc}" mariadb --host="${SWC_TEST_DB_HOST:-127.0.0.1}" \
  --port="${SWC_TEST_DB_PORT:-33078}" --user="${SWC_TEST_DB_USER:-swc}" -e "DROP DATABASE IF EXISTS swc_e2e; CREATE DATABASE swc_e2e CHARACTER SET utf8mb4"
cat > "$SWC_E2E_WP/wp-config.php" <<CONFIG
<?php
define( 'DB_NAME', 'swc_e2e' );
define( 'DB_USER', getenv( 'SWC_TEST_DB_USER' ) ?: 'swc' );
define( 'DB_PASSWORD', getenv( 'SWC_TEST_DB_PASSWORD' ) ?: 'swc' );
define( 'DB_HOST', ( getenv( 'SWC_TEST_DB_HOST' ) ?: '127.0.0.1' ) . ':' . ( getenv( 'SWC_TEST_DB_PORT' ) ?: '33078' ) );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'e2e-' . \$k ); }
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false ); define( 'DISABLE_WP_CRON', true );
define( 'SW_CALENDAR_ENCRYPTION_KEY', 'e2e-only-key' ); define( 'SW_CALENDAR_PRIVATE_STORAGE_PATH', __DIR__ . '/../wp-private' );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CONFIG
(cd "$SWC_E2E_WP" && php "$ROOT/tests/E2E/setup.php" && php "$ROOT/tests/E2E/seed.php")
(cd "$SWC_E2E_WP" && exec php -S 127.0.0.1:$PORT >"$WORK/server.log" 2>&1) &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do
  if curl --silent --fail "http://127.0.0.1:$PORT/" >/dev/null; then break; fi
  sleep 1
done
curl --silent --fail "http://127.0.0.1:$PORT/" >/dev/null
export PW_DIR="${PW_DIR:-$ROOT/tests/E2E/node_modules}"
node "$ROOT/tests/E2E/browser.mjs" "$WORK/shots" | tee "$WORK/result.txt"
