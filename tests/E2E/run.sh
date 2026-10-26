#!/usr/bin/env bash
# Browser E2E against a disposable WordPress (private MariaDB from bin/test-db.sh, PHP built-in server, Playwright).
# Usage: tests/E2E/run.sh [workdir]
# Needs: bin/test-db.sh start, tests/wp vendor (WordPress core copy), playwright-core + a Chromium
#        (PW_DIR = node_modules directory containing playwright-core, CHROME = chromium executable).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${1:-$(mktemp -d)}"
PORT=8089
mkdir -p "$WORK"
export SWC_E2E_WP="$WORK/wp"
rm -rf "$SWC_E2E_WP" "$WORK/wp-private" && cp -r "$ROOT/tests/wp/vendor/johnpbloch/wordpress-core" "$SWC_E2E_WP"
mkdir -p "$SWC_E2E_WP/wp-content/plugins" "$WORK/shots" && ln -s "$ROOT" "$SWC_E2E_WP/wp-content/plugins/sw-cloudcalendar"
mariadb --socket="${SWC_TEST_DB_DIR:-/tmp/swc-test-db}/mysql.sock" -uroot -e "DROP DATABASE IF EXISTS swc_e2e; CREATE DATABASE swc_e2e CHARACTER SET utf8mb4"
cat > "$SWC_E2E_WP/wp-config.php" <<CONFIG
<?php
define( 'DB_NAME', 'swc_e2e' ); define( 'DB_USER', 'swc' ); define( 'DB_PASSWORD', 'swc' ); define( 'DB_HOST', '127.0.0.1:33078' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'e2e-' . \$k ); }
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false ); define( 'DISABLE_WP_CRON', true );
define( 'SW_CALENDAR_ENCRYPTION_KEY', 'e2e-only-key' ); define( 'SW_CALENDAR_PRIVATE_STORAGE_PATH', __DIR__ . '/../wp-private' );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CONFIG
(cd "$SWC_E2E_WP" && php "$ROOT/tests/E2E/setup.php" && php "$ROOT/tests/E2E/seed.php")
(cd "$SWC_E2E_WP" && php -S 127.0.0.1:$PORT >/dev/null 2>&1) &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 1
node "$ROOT/tests/E2E/browser.mjs" "$WORK/shots" | tee "$WORK/result.txt"
! grep -q '^FAIL' "$WORK/result.txt"
