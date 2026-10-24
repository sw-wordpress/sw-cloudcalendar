#!/usr/bin/env bash
# Starts/stops a private, throw-away MariaDB instance for the WordPress integration tests (never touches other databases).
# Usage: bin/test-db.sh start|stop|status   (data dir: $SWC_TEST_DB_DIR, default /tmp/swc-test-db)
set -euo pipefail

DIR="${SWC_TEST_DB_DIR:-/tmp/swc-test-db}"
PORT="${SWC_TEST_DB_PORT:-33078}"
SOCKET="$DIR/mysql.sock"

case "${1:-}" in
  start)
    if [ -S "$SOCKET" ] && mariadb-admin --socket="$SOCKET" ping >/dev/null 2>&1; then echo "already running"; exit 0; fi
    rm -rf "$DIR"; mkdir -p "$DIR/data"
    mariadb-install-db --no-defaults --datadir="$DIR/data" --auth-root-authentication-method=normal >/dev/null
    nohup mariadbd --no-defaults --datadir="$DIR/data" --socket="$SOCKET" --port="$PORT" --bind-address=127.0.0.1 \
      --pid-file="$DIR/mysql.pid" --skip-name-resolve --innodb-flush-log-at-trx-commit=0 \
      --character-set-server=utf8mb4 --sql-mode=STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO \
      >"$DIR/mysqld.log" 2>&1 &
    for _ in $(seq 1 60); do
      mariadb-admin --socket="$SOCKET" ping >/dev/null 2>&1 && break; sleep 0.5
    done
    mariadb --socket="$SOCKET" -uroot -e "CREATE USER IF NOT EXISTS 'swc'@'%' IDENTIFIED BY 'swc'; GRANT ALL ON *.* TO 'swc'@'%'; CREATE USER IF NOT EXISTS 'swc'@'localhost' IDENTIFIED BY 'swc'; GRANT ALL ON *.* TO 'swc'@'localhost'; FLUSH PRIVILEGES;"
    mariadb --socket="$SOCKET" -uroot -e "CREATE DATABASE IF NOT EXISTS swc_wp_tests CHARACTER SET utf8mb4"
    echo "started on 127.0.0.1:$PORT (user swc / swc, strict SQL mode)"
    ;;
  stop)
    [ -f "$DIR/mysql.pid" ] && kill "$(cat "$DIR/mysql.pid")" 2>/dev/null || true
    echo stopped
    ;;
  status)
    mariadb-admin --socket="$SOCKET" ping 2>&1 || true
    ;;
  *) echo "usage: $0 start|stop|status"; exit 1;;
esac
