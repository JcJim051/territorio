#!/usr/bin/env bash
set -euo pipefail

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
plain="/tmp/territorio-${stamp}.sql"
encrypted="${plain}.enc"

notify() {
    if [[ -n "${TELEGRAM_BOT_TOKEN:-}" && -n "${TELEGRAM_CHAT_ID:-}" ]]; then
        curl -fsS -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
            -d "chat_id=${TELEGRAM_CHAT_ID}" \
            --data-urlencode "text=$1" >/dev/null || true
    fi
}

trap 'notify "❌ Falló el backup de Territorio (${stamp})"' ERR

PGPASSWORD="${DB_PASSWORD}" pg_dump \
    --host="${DB_HOST}" \
    --port="${DB_PORT:-5432}" \
    --username="${DB_USERNAME}" \
    --format=custom \
    --file="${plain}" \
    "${DB_DATABASE}"

openssl enc -aes-256-cbc -pbkdf2 -salt \
    -in "${plain}" \
    -out "${encrypted}" \
    -pass env:BACKUP_ENCRYPTION_PASSWORD

rclone copyto "${encrypted}" "gdrive:${BACKUP_DRIVE_PATH:-territorio-backups}/$(basename "${encrypted}")"
rm -f "${plain}" "${encrypted}"
notify "✅ Backup cifrado de Territorio enviado a Drive (${stamp})"
