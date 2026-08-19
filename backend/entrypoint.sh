#!/bin/bash
set -e

echo "🚀 Iniciando Lotto API..."
echo "   Entorno: ${APP_ENV:-production}"

# Generar .env desde variables de entorno
echo "📝 Generando .env..."
cat > /app/.env << EOF
APP_NAME=${APP_NAME:-lotto-app}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL}
LOG_LEVEL=${LOG_LEVEL:-warning}
SESSION_SECURE_COOKIE=${SESSION_SECURE_COOKIE:-true}

DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=${SESSION_DRIVER:-file}
SESSION_LIFETIME=120
CACHE_STORE=${CACHE_STORE:-file}
QUEUE_CONNECTION=${QUEUE_CONNECTION:-sync}
LOG_CHANNEL=${LOG_CHANNEL:-stderr}

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

REDIS_HOST=${REDIS_HOST:-127.0.0.1}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-null}

CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS:-*}
SANCTUM_STATEFUL_DOMAINS=${SANCTUM_STATEFUL_DOMAINS:-localhost}
EOF

# Migraciones — solo el contenedor API las ejecuta (Horizon las omite)
if [ "${RUN_HORIZON:-false}" != "true" ]; then
if php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make('db'); echo Schema::hasTable('migrations') ? 'EXISTS' : 'EMPTY';" 2>/dev/null | grep -q "EXISTS"; then
    echo "📦 Migraciones ya ejecutadas, verificando pendientes..."
    php artisan migrate --force
else
    echo "📦 Ejecutando migraciones iniciales..."
    php artisan migrate --force
    echo "🌱 Ejecutando seeders..."
    php artisan db:seed --force --class=DatabaseSeeder 2>/dev/null || echo "   (ok)"
fi

echo "🧹 Limpiando cache..."
php artisan optimize:clear
fi

PORT=${PORT:-10000}

# Worker Horizon (colas)
if [ "${RUN_HORIZON:-false}" = "true" ]; then
    echo "🌐 Iniciando Horizon (worker de colas)..."
    exec php artisan horizon
fi

# API con FrankenPHP en modo worker
echo "🌐 Iniciando FrankenPHP (workers) en 0.0.0.0:$PORT..."
exec /usr/local/bin/frankenphp php-server --root /app/public --listen 0.0.0.0:${PORT} --worker /app/public/index.php
