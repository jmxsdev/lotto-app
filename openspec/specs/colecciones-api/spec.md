# colecciones-api Specification

**Estado**: draft

## Purpose

Requisitos del artefacto `collections/` (OpenCollection 1.0.0): paridad fiel con `backend/routes/api.php` y entornos Local/Produccion ejecutables.

## Requirements

### Requirement: Entornos Local y Produccion

`collections/environments/` MUST contener `Local.yml` y `Produccion.yml` (nombres exactos) con `baseUrl`, `token` (vacío), `macAddress`, `deviceFingerprint` y credenciales de login (vacías, `secret: true`). `opencollection.yml` MUST declarar `defaultEnvironment: Local` y MUST eliminar `baseUrlWeb`. Seleccionar entorno MUST conmutar todas las requests sin editar archivos.

#### Scenario: Entorno Local resuelve baseUrl

- GIVEN entorno `Local` con `baseUrl=http://localhost:8000`
- WHEN se ejecuta `GET /api/v1/juegos`
- THEN la URL resuelta es `http://localhost:8000/api/v1/juegos`

#### Scenario: Conmutación sin editar archivos

- GIVEN entornos `Local` y `Produccion`
- WHEN se cambia el entorno en el selector
- THEN todas las requests usan el `baseUrl` activo sin editar archivos

### Requirement: Convención de URLs

Toda request MUST usar `{{baseUrl}}/api/v1/...` (host pelado); MUST NOT haber URLs hardcodeadas (`lotto.gzuz.dev`/Render) ni `{id}` literal.

#### Scenario: Prefijo versionado

- GIVEN una request de la colección
- WHEN se inspecciona `http.url`
- THEN usa `{{baseUrl}}/api/v1/...` y no `{{baseUrl}}/api/...`

#### Scenario: Sin hardcode ni `{id}` literal

- GIVEN toda la colección
- WHEN se buscan URLs
- THEN no aparece `lotto.gzuz.dev`, `lotto-api-6nrc.onrender.com` ni `{id}` literal

### Requirement: Cobertura de endpoints

Todo endpoint de `api.php` MUST tener colección, incluidos los faltantes: carpetas nuevas Agencias, Tickets, Configuraciones, Logs, Releases; y sueltos `GET /limites` (matriz), `DELETE /limites/{limite}`, `GET /cierre/actual`, `GET /resultados/{resultado}/apariciones`, toggles de bancas/grupos/taquillas, `GET /reportes/cuadre-caja`. Cada request SHOULD documentar rol(es) y estado.

#### Scenario: Endpoints cubiertos

- GIVEN los endpoints de `api.php`
- WHEN se comparan con `collections/`
- THEN cada endpoint tiene request de colección (5 carpetas nuevas y los sueltos)

#### Scenario: Roles documentados

- GIVEN un request de colección
- WHEN se lee `docs:`
- THEN documenta rol(es) y estado (p. ej. Tickets/Pagos sin agencia)

### Requirement: Contratos de payload y respuesta

Los ejemplos MUST ser fieles al contrato de `main` (autoridad: controladores, no tests):

| Área | Contrato vigente |
|---|---|
| Límites | sin `fraccion`/`limite_tiempo`; PUT `banca_id` required; batch modo `scope` |
| Apuestas/Tickets | `selecciones[]`; modalidades single-draw |
| Pagos | montos opcionales (premio autoritativo); pagable `pendiente\|ganadora` |
| Usuarios/Taquillas | `agencia_id`; rol `agencia` |
| Auth | `X-Panel` o fingerprint según flujo |
| Juegos/reglas | respuesta con `premios` |

#### Scenario: Límites sin campos retirados

- GIVEN `PUT /api/v1/limites/{juego}`
- WHEN se inspecciona el body
- THEN no incluye `fraccion` ni `limite_tiempo`, y `banca_id` es required

#### Scenario: Pago autoritativo

- GIVEN `POST /api/v1/pagos` tipo `egreso` sin montos
- WHEN la apuesta está `pendiente|ganadora`
- THEN el backend aplica el premio del motor

#### Scenario: Multi-selección

- GIVEN `POST /api/v1/apuestas` o `/tickets`
- WHEN se inspecciona el body
- THEN ejemplifica `combinacion.selecciones[]`

### Requirement: Credenciales y secretos

NINGÚN secreto real (password/hash/token) MUST quedar en git; credenciales MUST ser variables vacías `secret: true`; el login MUST rellenar `token` en el entorno activo con `bru.setEnvVar`.

#### Scenario: Sin secretos en git

- GIVEN el diff de `collections/`
- WHEN se inspecciona
- THEN no hay password/hash/token reales, y las credenciales son variables vacías `secret: true`

#### Scenario: Login escribe en entorno activo

- GIVEN `Auth/Login.yml` con after-response exitoso
- WHEN obtiene `response.token`
- THEN usa `bru.setEnvVar('token', ...)` y no `bru.setVar`

### Requirement: Calidad del artefacto

Los YAML MUST ser válidos OpenCollection 1.0.0 (`folder.yml`/request con `info`/`http`/`settings`/`runtime`); F1/F2 MUST verificarse con flujo ejecutable en Bruno (Local) y revisión estática.

#### Scenario: YAML válido

- GIVEN cada YAML de `collections/`
- WHEN se parsea
- THEN respeta OpenCollection 1.0.0 con `info`/`http`/`settings`/`runtime`

#### Scenario: Flujo ejecutable Local

- GIVEN entorno `Local` y `baseUrl` correcto
- WHEN se ejecutan F1/F2
- THEN resuelven sin 404 por prefijo y la revisión estática confirma cobertura
