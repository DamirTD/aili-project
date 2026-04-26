#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
  echo "[entrypoint] .env created from .env.example"
fi

DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="${DB_USERNAME:-postgres}"

echo "[entrypoint] Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" >/dev/null 2>&1; do
  sleep 1
done
echo "[entrypoint] PostgreSQL is ready."

if [ "${AUTO_COMPOSER_INSTALL:-true}" = "true" ]; then
  if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Installing composer dependencies..."
    composer install --no-interaction --prefer-dist
  fi
fi

if [ -f .env ]; then
  APP_KEY_LINE="$(grep '^APP_KEY=' .env || true)"
  if [ -z "${APP_KEY_LINE}" ] || [ "${APP_KEY_LINE}" = "APP_KEY=" ]; then
    echo "[entrypoint] Generating APP_KEY..."
    php artisan key:generate --force
  fi
fi

echo "[entrypoint] Clearing config cache..."
php artisan config:clear >/dev/null 2>&1 || true

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  echo "[entrypoint] Running migrations..."
  php artisan migrate --force
fi

echo "[entrypoint] Starting php-fpm..."
exec php-fpm
