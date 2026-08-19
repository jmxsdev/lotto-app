---
capability: api-versioning
change: despliegue-vps
status: done
---

# api-versioning Specification

## Purpose

Exponer todas las rutas del backend bajo el prefijo `/api/v1`, adaptar los clientes (taquilla y panel) para consumir la URL versionada y configurar CORS/Sanctum para el dominio de producción `panel.gzuz.dev`, sin alterar el comportamiento de las capabilities de dominio existentes.

## Requirements

| # | Requirement | Strength | Estado |
|---|------------|----------|--------|
| REQ-1 | Rutas backend bajo `/api/v1` | MUST | draft |
| REQ-2 | Clientes usan `/api/v1` | MUST | draft |
| REQ-3 | CORS/Sanctum para `panel.gzuz.dev` | MUST | draft |
| REQ-4 | Capabilities de dominio intactas | MUST | draft |

### Requirement: REQ-1 — Rutas backend bajo `/api/v1`

Todas las rutas actuales de `backend/routes/api.php` MUST quedar envueltas en `Route::prefix('v1')`, de modo que la URL pública estable sea `https://lotto.gzuz.dev/api/v1/...`. Las rutas sin prefijo (`/api/...`) MUST NOT seguir siendo accesibles en producción.

#### Scenario: SCENARIO-1 — Endpoint versionado responde

- GIVEN la API desplegada en `lotto.gzuz.dev`
- WHEN se ejecuta `GET https://lotto.gzuz.dev/api/v1/juegos`
- THEN responde 200 con el payload de juegos
- AND cada ruta existente (login, activar, apuestas, cierres, reportes) responde igual bajo `/api/v1`

#### Scenario: SCENARIO-2 — Ruta desnuda no disponible

- GIVEN la API de producción
- WHEN se ejecuta `GET https://lotto.gzuz.dev/api/juegos`
- THEN responde 404 sin exponer lógica de negocio

### Requirement: REQ-2 — Clientes usan `/api/v1`

Taquilla (Electron+Astro) y panel (Astro) MUST construir la URL de la API con el patrón `(import.meta.env.PUBLIC_API_URL || 'http://localhost:8000') + '/api/v1'` en todos los puntos de acceso (~14 archivos), sin URLs hardcodeadas sueltas.

#### Scenario: SCENARIO-3 — Taquilla en desarrollo local

- GIVEN taquilla sin `PUBLIC_API_URL` definida
- WHEN la taquilla realiza una petición a la API
- THEN la URL base resultante es `http://localhost:8000/api/v1`
- AND login, apuestas y cierre funcionan contra la API local

#### Scenario: SCENARIO-4 — Panel en producción

- GIVEN panel con `PUBLIC_API_URL=https://lotto.gzuz.dev`
- WHEN el panel realiza una petición a la API
- THEN la URL base resultante es `https://lotto.gzuz.dev/api/v1`
- AND ninguna petición usa la URL desnuda `/api`

### Requirement: REQ-3 — CORS/Sanctum para `panel.gzuz.dev`

La API MUST aceptar peticiones del origen `https://panel.gzuz.dev` (`CORS_ALLOWED_ORIGINS=https://panel.gzuz.dev`) y MUST incluir `panel.gzuz.dev` en `SANCTUM_STATEFUL_DOMAINS` para autenticación stateful de Sanctum con cookies.

#### Scenario: SCENARIO-5 — Petición cross-origin con credenciales

- GIVEN una petición desde `https://panel.gzuz.dev` con cookies de sesión
- WHEN se envía un POST de login a `/api/v1/login` con `X-XSRF-TOKEN` válido
- THEN la API responde con cabeceras CORS correctas para ese origen
- AND la autenticación stateful de Sanctum completa el login satisfactoriamente

### Requirement: REQ-4 — Capabilities de dominio intactas

El versionado MUST NOT cambiar el comportamiento de las capabilities de dominio existentes: contratos de petición/respuesta, validaciones y códigos de estado permanecen idénticos; ningún escenario de las specs existentes depende de la URL desnuda `/api`.

#### Scenario: SCENARIO-6 — Regresión de contratos verificada en CI

- GIVEN la suite PHPUnit de feature con las rutas versionadas
- WHEN se ejecuta la suite completa contra `/api/v1`
- THEN todos los tests existentes pasan sin modificar sus aserciones de contrato
- AND ningún test de la suite referencia la ruta desnuda `/api/`
