#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

BACKUP_ROOT="/var/backups/xurvexa/daily"
APP_DIR="/var/www/project-ares/backend"
DB_NAME="xurvexa"
RETENTION_DAYS=14

TS="$(date +%Y%m%d-%H%M%S)"

DB_FILE="${BACKUP_ROOT}/xurvexa-db-${TS}.dump"
STORAGE_FILE="${BACKUP_ROOT}/xurvexa-storage-${TS}.tar.gz"
CHECKSUM_FILE="${BACKUP_ROOT}/xurvexa-${TS}.sha256"

TMP_DB="${DB_FILE}.tmp"
TMP_STORAGE="${STORAGE_FILE}.tmp"

cleanup() {
    rm -f "$TMP_DB" "$TMP_STORAGE"
}

trap cleanup EXIT

mkdir -p "$BACKUP_ROOT"

echo "BACKUP_START=$TS"

runuser -u postgres -- pg_dump \
    --format=custom \
    "$DB_NAME" \
    > "$TMP_DB"

pg_restore -l "$TMP_DB" > /dev/null

mv "$TMP_DB" "$DB_FILE"

tar -czf "$TMP_STORAGE" \
    -C "$APP_DIR" \
    storage/app

tar -tzf "$TMP_STORAGE" > /dev/null

mv "$TMP_STORAGE" "$STORAGE_FILE"

chmod 600 \
    "$DB_FILE" \
    "$STORAGE_FILE"

sha256sum \
    "$DB_FILE" \
    "$STORAGE_FILE" \
    > "$CHECKSUM_FILE"

chmod 600 "$CHECKSUM_FILE"

find "$BACKUP_ROOT" \
    -type f \
    \( \
        -name 'xurvexa-db-*.dump' \
        -o -name 'xurvexa-storage-*.tar.gz' \
        -o -name 'xurvexa-*.sha256' \
    \) \
    -mtime +"$RETENTION_DAYS" \
    -delete

echo "BACKUP_STATUS=PASS"
echo "TIMESTAMP=$TS"
echo "DB_FILE=$DB_FILE"
echo "STORAGE_FILE=$STORAGE_FILE"
echo "CHECKSUM_FILE=$CHECKSUM_FILE"
echo "RETENTION_DAYS=$RETENTION_DAYS"