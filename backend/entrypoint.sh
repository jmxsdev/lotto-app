#!/bin/bash
set -e

echo "🚀 Iniciando Lotto API..."
echo "   Entorno: ${APP_ENV:-production}"

# Generar APP_KEY si no existe
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    echo "🔑 Generando APP_KEY..."
    php artisan key:generate --force
fi

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
