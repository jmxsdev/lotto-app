#!/usr/bin/env bash
# deploy.sh — despliegue de producción de lotto-app en el VPS
# Uso: ./deploy.sh   (como usuario deploy, desde /srv/lotto-app)
set -euo pipefail

cd "$(dirname "$0")"
COMPOSE=(docker compose --env-file .env.production -f docker-compose.prod.yml)

"${COMPOSE[@]}" pull --quiet 2>/dev/null || true
"${COMPOSE[@]}" build --pull
"${COMPOSE[@]}" up -d

echo "⏳ Esperando healthcheck..."
ok=1
for _ in $(seq 1 36); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' http://127.0.0.1:10000/api/v1/juegos || true)
  if [ "$code" = "200" ] || [ "$code" = "401" ]; then
    echo "✅ Healthcheck OK (HTTP $code)"
    ok=0
    break
  fi
  sleep 5
done

if [ "$ok" -ne 0 ]; then
  echo "❌ Healthcheck falló. Rollback manual:"
  echo "   ${COMPOSE[*]} up -d <imagen-anterior>"
  exit 1
fi

echo "🟢 Despliegue completado."
