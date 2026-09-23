#!/bin/sh
# Creates the demo Laravel app around the local leadscaptain package.
# Run inside the app container:
#   docker compose run --rm --no-deps -u www-data app sh scripts/bootstrap.sh
# Safe to re-run: existing files are kept.
set -eu

LARAVEL_VERSION="${LARAVEL_VERSION:-^12.0}"
PACKAGE_NAME="leadscaptain/laravel-leadscaptain"
TMP_DIR="/tmp/laravel-skeleton"

log() { printf '\n==> %s\n' "$1"; }

# Set KEY=VALUE, replacing an existing or commented-out line.
set_env() {
    key=$1; value=$2; file=$3
    if grep -qE "^#? *${key}=" "$file"; then
        sed -i "s|^#* *${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

# Add KEY=VALUE only if the key is not present (never overwrites secrets).
add_env() {
    key=$1; value=$2; file=$3
    grep -qE "^${key}=" "$file" || printf '%s=%s\n' "$key" "$value" >> "$file"
}

# 1. Laravel skeleton ---------------------------------------------------------
if [ -f artisan ]; then
    log "Laravel app already present, skipping create-project"
else
    log "Creating Laravel ${LARAVEL_VERSION} skeleton"
    rm -rf "$TMP_DIR"
    composer create-project "laravel/laravel:${LARAVEL_VERSION}" "$TMP_DIR" --prefer-dist --no-interaction

    for item in "$TMP_DIR"/* "$TMP_DIR"/.[!.]*; do
        [ -e "$item" ] || continue
        name=$(basename "$item")
        # Docker may have created an empty mount point (e.g. public/) already
        if [ -d "$name" ] && [ -z "$(ls -A "$name")" ]; then
            rmdir "$name"
        fi
        if [ -e "$name" ]; then
            echo "   keeping existing $name"
        else
            mv "$item" "./$name"
        fi
    done
    rm -rf "$TMP_DIR"
fi

# 2. Environment ---------------------------------------------------------------
log "Configuring .env for the Docker services"
[ -f .env ] || cp .env.example .env

for file in .env .env.example; do
    set_env APP_URL          "http://localhost:8080" "$file"
    set_env LOG_CHANNEL      "stderr"               "$file"
    set_env DB_CONNECTION    "mysql"                "$file"
    set_env DB_HOST          "db"                   "$file"
    set_env DB_PORT          "3306"                 "$file"
    set_env DB_DATABASE      "laravel"              "$file"
    set_env DB_USERNAME      "laravel"              "$file"
    set_env DB_PASSWORD      "secret"               "$file"
    add_env DB_ROOT_PASSWORD "root"                 "$file"

    add_env LEADSCAPTAIN_BASE_URL        "https://api.leadscaptain.com" "$file"
    add_env LEADSCAPTAIN_API_KEY         ""                             "$file"
    add_env LEADSCAPTAIN_LEADS_PATH      "/api/v1/leads"                "$file"
    add_env LEADSCAPTAIN_PAGE_SIZE       "100"                          "$file"
    add_env LEADSCAPTAIN_PAGE_SIZE_PARAM "limit"                        "$file"
    add_env LEADSCAPTAIN_TIMEOUT         "30"                           "$file"
    add_env LEADSCAPTAIN_RETRY_TIMES     "3"                            "$file"
    add_env LEADSCAPTAIN_CONCURRENCY     "10"                           "$file"
    add_env LEADSCAPTAIN_RATE_LIMIT      "60"                           "$file"
done

# 3. Local package -------------------------------------------------------------
log "Linking packages/leadscaptain through a composer path repository"
composer config repositories.leadscaptain \
    '{"type": "path", "url": "./packages/leadscaptain", "options": {"symlink": true}}'
composer require "${PACKAGE_NAME}:@dev" --no-interaction

log "Publishing package config"
php artisan vendor:publish --tag=leadscaptain-config --no-interaction
php artisan config:clear

log "Done. Next: docker compose up -d --wait && docker compose exec -u www-data app php artisan migrate"
