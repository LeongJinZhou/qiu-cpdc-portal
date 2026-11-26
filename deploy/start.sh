#!/bin/sh
# Container start (Render test copy): make the storage folders, bring the database schema up to date, run Apache.
# Render's free plan has no disk, so storage (sessions, sign-in state, logs) is empty after every restart.
set -eu

for dir in sessions logs google; do
    mkdir -p "$STORAGE_DIR/$dir"
done
chown -R www-data:www-data "$STORAGE_DIR"

# Render's secret files are readable by root only; Apache's workers run as www-data. Copy the database CA to a
# file only www-data can read and point DB_SSL_CA at it (Apache inherits the exported value).
if [ -n "${DB_SSL_CA:-}" ] && [ ! -r "$DB_SSL_CA" ]; then
    echo "cpdc-start: DB_SSL_CA is $DB_SSL_CA but that file is missing. On Render add the Secret File $(basename "$DB_SSL_CA") (the database CA certificate)." >&2
    exit 1
fi
if [ -n "${DB_SSL_CA:-}" ]; then
    install -o www-data -g www-data -m 0400 "$DB_SSL_CA" /run/cpdc-db-ca.pem
    export DB_SSL_CA=/run/cpdc-db-ca.pem
fi

# Applies only migration files not yet recorded in schema_migration, so every restart is safe.
php /home/cpdc/cpdc/api/bin/migrate.php

exec apache2-foreground
