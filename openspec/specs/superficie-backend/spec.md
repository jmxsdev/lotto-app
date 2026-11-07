# superficie-backend Specification

**Estado**: draft

## Purpose

Definir la superficie pública del backend de lotto-app como API-only: rutas de negocio bajo `/api/v1`, endpoints operativos, respuestas JSON y autorización Spatie. El backend MUST NOT servir una UI web legacy ni mantener middleware de roles duplicado.

## Requirements

### Requirement: Superficie API-only

El backend MUST exponer únicamente rutas de negocio bajo `/api/v1/*` y endpoints operativos (`/up`, Horizon, storage firmado). El backend MUST NOT registrar rutas web con vistas Blade, sesiones de panel o prefijo `admin/*`.

#### Scenario: route:list sin rutas legacy

- GIVEN el backend con el cambio aplicado
- WHEN se ejecuta `php artisan route:list`
- THEN no aparecen rutas con prefijo `admin/`
- AND no aparece la ruta `GET /` servida por `routes/web.php`
- AND sí aparecen `/up`, `/horizon/*` y `/sanctum/csrf-cookie`

### Requirement: Raíz JSON 404

`GET /` MUST responder `404` con cuerpo JSON, nunca HTML de Laravel, con o sin cabecera `Accept: application/json`.

#### Scenario: raíz con Accept JSON

- GIVEN el backend con el cambio aplicado
- WHEN se hace `GET /` con `Accept: application/json`
- THEN la respuesta es `404`
- AND el `Content-Type` es `application/json`
- AND el cuerpo contiene `{"message":"Recurso no encontrado."}`

#### Scenario: raíz sin Accept

- GIVEN el backend con el cambio aplicado
- WHEN se hace `GET /` sin cabecera `Accept`
- THEN la respuesta es `404`
- AND el `Content-Type` es `application/json`

### Requirement: Autorización Spatie sin alias legacy

La autorización de recursos MUST usar los middlewares de Spatie (`permission:`, `role_or_permission:`) y las policies. El alias `checkrole` MUST NOT existir y `App\Http\Middleware\CheckRole` MUST NOT existir en el código.

#### Scenario: alias ausente

- GIVEN el backend con el cambio aplicado
- WHEN se inspecciona el router (`app('router')->getMiddleware()`)
- THEN no existe la clave `checkrole`

#### Scenario: rutas protegidas intactas

- GIVEN una ruta protegida con `permission:view_resultados`
- WHEN un usuario sin el permiso hace la petición
- THEN la respuesta es `403` JSON
- AND el comportamiento no cambió respecto de antes del cambio

### Requirement: Scraping manual solo por API

El scraping manual de resultados MUST existir únicamente a través de `POST /api/v1/resultados/scrape` y `POST /api/v1/resultados/scrape-all`, protegidos por `permission:manage_resultados`. `FetchResultsJob` y la ruta web `POST /admin/resultados/scrape` MUST NOT existir.

#### Scenario: ruta API intacta

- GIVEN un usuario con `manage_resultados`
- WHEN hace `POST /api/v1/resultados/scrape`
- THEN la petición se procesa por el controller API
- AND no se usa `FetchResultsJob`

#### Scenario: job legacy ausente

- GIVEN el backend con el cambio aplicado
- WHEN se busca `FetchResultsJob` en `backend/app` y `backend/tests`
- THEN no hay coincidencias
