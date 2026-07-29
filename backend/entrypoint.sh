#!/bin/bash
set -e

echo "🚀 Iniciando Lotto API..."
echo "   Entorno: ${APP_ENV:-production}"

# Generar .env desde variables de entorno de Render
echo "📝 Generando .env..."
cat > /app/.env << EOF
APP_NAME=${APP_NAME:-lotto-app}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL}

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

CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS:-*}
SANCTUM_STATEFUL_DOMAINS=${SANCTUM_STATEFUL_DOMAINS:-localhost}
EOF

# Migraciones
echo "📦 Ejecutando migraciones..."
php artisan migrate --force

# Seed inicial (no falla si ya existen datos)
echo "🌱 Ejecutando seeders..."
php artisan db:seed --force --class=DatabaseSeeder 2>/dev/null || echo "   (datos ya existentes, omitiendo seed)"

# Iniciar servidor
PORT=${PORT:-10000}
echo "🌐 Iniciando servidor en 0.0.0.0:$PORT..."
php artisan serve --host 0.0.0.0 --port $PORT
