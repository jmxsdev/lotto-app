# Lotto App - Monorepo

## Requisitos previos
- Docker y Docker Compose
- Node.js 18+ (para el frontend)
- PHP 8.2+ (para Laravel local, opcional si usas Docker)

## Levantar el entorno (Sprint 0)
1. Clona el repositorio.
2. Copia el .env de ejemplo:
   `cp .env.example .env`
3. Levanta los contenedores:
   `docker-compose up -d`
4. Instala dependencias de PHP (dentro del contenedor o local):
   `docker exec -it lotto_backend composer install`
5. Instala dependencias del frontend:
   `cd taquilla && npm install`
6. Verifica que Laravel esté corriendo en `http://localhost:8080`
7. Verifica el frontend:
   `cd taquilla && npm run dev` (Astro en modo desarrollo) o `npm run electron:dev` (para abrir la ventana de Electron).

## Notas
- MySQL: root/root, puerto 3306.
- Redis: puerto 6379.
