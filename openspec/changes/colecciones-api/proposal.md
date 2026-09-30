# Proposal: Modernización de colecciones de API (`colecciones-api`)

## Intent

Las colecciones Bruno en `collections/` (OpenCollection 1.0.0, 81 requests / 16 carpetas) quedaron sistemáticamente desalineadas de la API real. Evidencia del explore: **77 de 81** requests usan `{{baseUrl}}/api/...` sin `/v1` (el prefijo se introdujo en `baee6b6`, **anterior** a la última edición de colecciones `1491640`), **1** URL hardcodeada (`Juegos/Ver Juego.yml:12`), **16** endpoints sin colección (5 carpetas inexistentes: Agencias, Tickets, Configuraciones, Logs, Releases), **≥12** payloads con contratos viejos (límites `fraccion`/`limite_tiempo`, apuestas sin `selecciones[]`, pagos con montos obligatorios), y **sin entornos** (`baseUrlWeb` apunta al legado Render `lotto-api-6nrc.onrender.com`, la prod vigente es `https://lotto.gzuz.dev`). Hoy **ninguna colección ejecuta contra prod ni local** tal cual. El objetivo es devolver `collections/` a paridad fiel con `backend/routes/api.php` y sus controladores, y dotarla de entornos prod/local.

## Alcance (Scope)

### In Scope

- **F1 (fundacional)** — entornos + Límites + URLs rotas de Cierre de Caja/Juegos/Ver Juego (~15 archivos).
- **F2 (drift restante)** — `/v1` en ~74 URLs + 5 carpetas nuevas + payloads desactualizados + requests faltantes en carpetas existentes + docs de roles/estados.

### Out of Scope / Non-goals

- Premios / configuración de juegos (`feat/configuracion-juegos-s4`, sin fusionar) y comisiones (`feat/comisiones`): **no** crear colecciones para endpoints no fusionados en `main`.
- `backend/`, panel, taquilla: sin tocar código de aplicación.
- Secretos: sin credenciales reales en git (ver D3).
- Rediseño de la API o de los contratos: solo reflejar lo ya publicado en `main`.

## Decisiones (D1–D4)

### D1 — Alcance por fases

**Decisión**: todo el drift, entregado en dos fases encadenadas `stacked-to-main`.

| Opción | Tradeoff |
|---|---|
| Solo Límites+entornos | Menor riesgo, pero deja la colección sin ejecutar (77 URLs rotas) |
| **Todo el drift, por fases** ✅ | Paridad completa; el tamaño se controla con F1/F2 y slices de F2 |

F1 es autónoma y verificable (vuelve a ejecutar la colección); F2 depende de F1 y se divide en 2–3 slices por el límite de revisión (400 líneas).

### D2 — Convención de URLs (opción A)

**Decisión**: `baseUrl` = host pelado (`http://localhost:8000` local, `https://lotto.gzuz.dev` prod); **todas** las requests usan `{{baseUrl}}/api/v1/...`; eliminar `baseUrlWeb` (Render = legado); deshardcodear `Juegos/Ver Juego.yml`.

| Opción | Tradeoff |
|---|---|
| **A: host pelado + `/api/v1/` en path** ✅ | Auto-descriptivo, versionado visible, estándar OpenCollection; toca 77 archivos de forma mecánica |
| B: `baseUrl` con `/api/v1/` incluido | Menos ruido por request, pero oculta el versionado y arriesga `//` doble (inconsistencia del WIP sin commitear) |

### D3 — Credenciales por entorno

**Decisión**: el WIP sin commitear del checkout principal **no se integra** (su hash de password es de una cuenta inexistente y **nunca** debe commitearse). El diseño deja variables de credenciales de login (email/password de panel y/o taquilla) **vacías** y `secret: true` por entorno, rellenables por el usuario en su entorno activo.

| Opción | Tradeoff |
|---|---|
| **Variables vacías + `secret: true`** ✅ | Sin secretos en git; el usuario las rellena al apuntar a prod |
| Hardcodear credenciales | Fuga de secretos; inaceptable |

### D4 — Entornos estándar

**Decisión**: carpeta `environments/` con `Local.yml` + `Produccion.yml` (nombres exactos `Local`/`Produccion`), `defaultEnvironment` en `opencollection.yml` = **`Local`**; variables por entorno: `baseUrl`, `token` (vacío), `macAddress`, `deviceFingerprint`, y credenciales de D3.

**Justificación de `defaultEnvironment: Local`**: es el flujo seguro — no exige credenciales de prod (D3) y coincide con `php artisan serve`; apuntar a prod es un opt-in explícito (seleccionar entorno + rellenar credenciales), evitando llamadas accidentales a producción con credenciales vacías.

**Ajuste de script**: el `after-response` de `Auth/Login.yml` hoy usa `bru.setVar('token', ...)`; debe pasar a `bru.setEnvVar('token', ...)` para escribir en la variable `token` del entorno activo.

## Capabilities

### New Capabilities

- `colecciones-api`: requisitos del artefacto de colecciones API (entornos Local/Produccion, convención de URLs `/api/v1`, cobertura de endpoints `api.php`, fidelidad de payloads/roles/estados).

### Modified Capabilities

- None — este cambio no altera ninguna capability de backend existente (`limites`, `cierre-caja`, etc.); solo sincroniza `collections/` con el comportamiento ya publicado.

## Approach

1. **F1**: crear entornos + `defaultEnvironment`; corregir contrato de Límites (quitar campos dormidos, `banca_id` required, GET matriz, DELETE por id, batch `scope`); corregir `/v1` en Cierre de Caja + `{id}` literal; deshardcodear `Ver Juego.yml`; ajustar script de login a `bru.setEnvVar`.
2. **F2**: `/v1` mecánico en ~74 requests; crear 5 carpetas nuevas; actualizar payloads (apuestas `selecciones[]`, pagos montos opcionales, `agencia_id` en Usuarios/Taquillas, reglas con `premios`, auth `X-Panel`); añadir requests faltantes en carpetas existentes (`cierre/actual`, `apariciones`, 3 toggles, `cuadre-caja`); documentar roles/estados por request.

Cada work unit se commitea como `docs(collections): <descripción>` (convención del repo).

## Affected Areas

| Área | Impacto | Descripción |
|---|---|---|
| `collections/opencollection.yml` | Modificado | `defaultEnvironment`, eliminar `baseUrlWeb`, mover variables a entornos |
| `collections/environments/` | Nuevo | `Local.yml`, `Produccion.yml` |
| `collections/Limites/` | Modificado | payloads + GET matriz + DELETE + batch scope + `/v1` |
| `collections/Cierre de Caja/` | Modificado | `/v1` + `{id}` literal |
| `collections/Juegos/Ver Juego.yml` | Modificado | deshardcodear |
| `collections/Auth/Login.yml` | Modificado | `bru.setEnvVar` + headers de auth |
| `collections/` (74 requests) | Modificado | prefijo `/v1` |
| `collections/{Agencias,Tickets,Configuraciones,Logs,Releases}/` | Nuevo | 5 carpetas |
| `collections/{Apuestas,Pagos,Usuarios,Taquillas,Juegos}` | Modificado | payloads actualizados |

## Plan de entrega

PRs encadenados `stacked-to-main` (PR #1 apunta a `main`, cada PR hijo apunta al anterior).

- **PR 1 — F1** (~15 archivos, ~200–300 líneas): entornos + Límites + Cierre/Juegos.
- **PR 2 — F2 slice 1**: `/v1` mecánico en ~74 requests (bajo riesgo).
- **PR 3 — F2 slice 2**: carpetas nuevas + requests faltantes en carpetas existentes.
- **PR 4 — F2 slice 3**: payloads actualizados + docs de roles/estados.

El número final de slices de F2 se confirma en `sdd-tasks` según el forecast de líneas (presupuesto 1600 líneas total).

## Risks

| Riesgo | Likelihood | Mitigación |
|---|---|---|
| R1 — Convención de URLs bloqueante | Resuelto por D2 | opción A fijada |
| R2 — Fuga de hash de password | Resuelto por D3 | WIP no se integra; credenciales vacías + `secret: true` |
| R3 — Contratos de límites sin tests | Med | Validar payloads contra `JuegoController.php` (autoridad), no contra tests |
| R4 — `{id}` literal en Ver Cierre | Med | Corregir en F1 (variable real) |
| R5 — Roles de Tickets/Pagos sin agencia | Med | Documentar roles correctos (SM\|M\|B\|G\|T, sin agencia) |
| R6 — Tamaño | Med | Fases + PRs encadenados (≤400 líneas/PR) |

## Rollback Plan

Cada PR es reversible con `git revert` independiente (los slices son autónomos). La cadena se deshace revirtiendo en orden inverso de PR. La convención de URLs (D2) se fija en F1 y no se reabre en F2, de modo que un revert de F1 revierte entornos + convención juntos sin dejar un estado intermedio inconsistente.

## Dependencies

- `backend/routes/api.php` y controladores de `main` (`d2f8261`) como única autoridad de contratos.
- Formato OpenCollection 1.0.0 / entornos según docs oficiales de Bruno (explore §3.2).

## Success Criteria

- [ ] La colección MUST ejecutarse contra `Local` con solo rellenar `baseUrl` (y credenciales de login para rutas autenticadas).
- [ ] Toda request SHOULD usar `{{baseUrl}}/api/v1/...`; ninguna URL hardcodeada a `lotto.gzuz.dev` ni a Render.
- [ ] Los 16 endpoints faltantes (5 carpetas nuevas + 9 requests sueltos) MUST tener colección.
- [ ] Los payloads de Límites/Apuestas/Pagos/Usuarios/Taquillas/Juegos/Login MUST reflejar el contrato vigente (sin `fraccion`/`limite_tiempo`, con `selecciones[]`, montos opcionales, `agencia_id`, `X-Panel`).
- [ ] Ningún secreto (password/hash/token) MUST quedar commiteado; credenciales vacías + `secret: true`.
- [ ] `defaultEnvironment` SHOULD ser `Local`; `baseUrlWeb` MUST quedar eliminado.
