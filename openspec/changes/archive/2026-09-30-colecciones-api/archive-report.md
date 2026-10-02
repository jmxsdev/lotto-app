# Archive Report — colecciones-api

**Fecha de archivo**: 2026-09-30
**Artefacto store**: hybrid (openspec + engram)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/colecciones-api` · Rama `feat/colecciones-api` @ `ffca561` (verify-report) → este commit de archive
**Veredicto del verify**: **PASS WITH WARNINGS** (0 CRITICAL / 0 FAIL / 4 SUGGESTIONs)
**Merge a main**: **PENDIENTE por decisión del usuario** — el cambio vive en la rama `feat/colecciones-api` @ `ffca561`, NO merged, NO pusheado. Entrega planificada: PRs encadenados `stacked-to-main` (4 PRs, F1→F2.3) — aún NO creados. Este archive no mergea ni hace push (restricción del encargo).

---

## Resumen del cambio

Modernización del artefacto `collections/` (OpenCollection 1.0.0, colecciones Bruno) para devolverlo a paridad fiel con `backend/routes/api.php` y sus controladores (autoridad de contratos, base `d2f8261`). **F1 (fundacional)**: entornos `Local`/`Produccion` (`collections/environments/`), `defaultEnvironment: Local` en `opencollection.yml`, eliminación de `baseUrlWeb` (legado Render), corrección de Límites (campos dormidos retirados, `banca_id` required, GET matriz, DELETE por id, batch `scope`), `/v1` y `{id}` literal en Cierre de Caja, deshardcodeo de `Ver Juego.yml`, login con `bru.setEnvVar`. **F2 (drift restante)**: prefijo `/api/v1` en ~74 URLs, 5 carpetas nuevas (Agencias, Tickets, Configuraciones, Logs, Releases), payloads vigentes (`selecciones[]`, pagos sin montos, `agencia_id`, reglas con `premios`, auth `X-Panel`/fingerprint) y docs de roles/estados.

**Non-goals respetados**: `backend/`, panel y taquilla intactos; sin colecciones para endpoints no fusionados en `main` (premios/comisiones fuera); sin rediseño de la API (solo refleja lo publicado); sin secretos commiteados (credenciales vacías `secret: true`, hash del WIP descartado).

Implementación: 4 slices (F1, F2.1, F2.2, F2.3). **32/32 tareas completas** (tasks.md: 1.1–1.17, 2.1–2.3, 3.1–3.6, 4.1–4.6).

## Veredicto del verify (estado FINAL al cierre)

Fuente: `verify-report.md` (commit `ffca561`, observación Engram #487, envelope `gentle-ai.verify-result/v1` `verdict: pass_with_warnings`, `requirements 6/6`, `scenarios 13/13`) + hechos de estado final del orquestador. Los números que siguen son los del cierre, no los de snapshots intermedios.

| Métrica | Valor final |
|---------|-------------|
| Verdict | `pass_with_warnings` |
| Blockers / CRITICAL | 0 / 0 |
| Tests (parseo OpenCollection) | **133/133 YAML válidos** (`pnpm dlx js-yaml` sobre `find collections -name "*.yml"`, exit 0, 98,813 bytes) |
| Build (barrido de invariantes F2) | exit 0 — `invalid-urls=0 hardcode=0 idlit=0 render=0 setenv=5 setvar=0 mac=1 secrets=0 requests=109` |
| Invariantes estáticos | **9/9** (rg por invariante del tasks.md §Verificación por WU: prefijo `/v1` en 109/109 urls, sin hardcode, sin `{id}` literal, sin Render, `setEnvVar` 5/5 logins, 0 `setVar('token')`, MAC real solo Local, 0 secretos/fraccion/limite_tiempo) |
| Cobertura de endpoints | **90/90** endpoints de `api.php` (70 rutas + 5 `apiResource` ×5) con request; 27 faltantes resueltos (2 F1 + 25 F2.2) |
| Requests en la colección | **109** (108 de cobertura + 1 payload tripleta de F2.3 — desviación documentada en verify-report y apply-progress #485) |
| Spec compliance | 6/6 REQ · 13/13 escenarios con evidencia — **9 COMPLIANT + 4 PARTIAL** (R1-S1, R1-S2, R4-S2, R6-S2) |
| Coverage | ➖ No aplica (artefacto YAML; verificación = parseo + invariantes + tabla + gate manual) |

Los 4 PARTIAL no son UNTESTED: tienen evidencia estática completa; su capa runtime es el gate humano Bruno UI configurado en el proyecto (sin `bru` CLI en el entorno; design §Estrategia:377).

### SUGGESTIONs (4, no bloqueantes)

1. `Releases/Servir Instalador.yml:10-11` envía `Authorization: Bearer {{token}}` en un endpoint público (`signed:relative`, sin auth Sanctum). Header ignorado por el middleware; omitirlo evitaría confusión sobre el esquema de auth.
2. **`password123` como valor de ejemplo en `Usuarios/Crear Usuario.yml:20` y `Taquillas/Crear Taquilla.yml:24` (`user_password`)**: placeholder demo inofensivo (convención pre-existente en la base `d2f8261`), NO es un secreto real — los invariantes R5 pasan (0 passwords/hashes reales). Opcional: migrar a variable `{{...}}` como los logins.
3. `folder.yml` con `seq` duplicados pre-existentes (Auth/Activacion 1/2, Usuarios 2, Bancas/Grupos 3, Juegos/Pagos 6, Dispositivo/Tasas 8) — cosmético, no introducido por este cambio; las 5 carpetas nuevas (15–19) tienen `seq` único.
4. Discrepancia de conteo administrativo: apply-progress registra "18/18 tareas" vs 32 checkboxes `[x]` reales en tasks.md — estado completo en ambos casos (0 pendientes).

### QA manual pendiente (gate humano — Bruno UI, 5 pasos del verify-report)

Sin `bru` CLI → la parte ejecutable es gate humano (tasks.md:116-118 y design §Estrategia:375):

1. **Login Local**: seleccionar entorno `Local` (ya es el default); rellenar `superEmail`/`superPassword`; ejecutar `Auth/Login` → **200** + `token` en el entorno activo (`bru.setEnvVar`).
2. **Requests de panel**: `Juegos/Listar Juegos`, `Limites/Listar Limites por Juego`, `Cierre de Caja/Listar Cierres` → **200** sin editar archivos (valida R1-S1/R2/R3 en runtime).
3. **Piloto taquilla (herencia de headers root, riesgo D/E)**: `Auth/Login (Taquilla)` con MAC `d8:f3:bc:65:d1:c5` registrada en `taquillas.mac_address` (BD local, en MAYÚSCULAS) + `Juegos/Listar Juegos` → **200** (prueba que `X-Device-MAC`/`X-Device-Fingerprint` del root llegan a la request; si falla → aplicar fallback per-request documentado en design D/E).
4. **Pago autoritativo (R4-S2)**: `Pagos/Registrar Pago (Token required)` sobre una apuesta `pendiente|ganadora` → **201** con `premio` (egreso sin montos).
5. **Producción (conmutación R1-S2)**: seleccionar `Produccion` y ejecutar `Tasas de Cambio/Obtener Tasa Activa (sin token, pública)` → **200** (endpoint público, sin credenciales).

## Decisiones (D1–D4, de proposal.md)

| Decisión | Contenido |
|---|---|
| **D1** | Todo el drift, entregado en dos fases encadenadas `stacked-to-main` (F1 autónoma y verificable; F2 depende de F1, dividida en slices por el límite de revisión de 400 líneas). |
| **D2** | Convención de URLs opción A: `baseUrl` = host pelado; todas las requests usan `{{baseUrl}}/api/v1/...`; eliminar `baseUrlWeb` (Render = legado); deshardcodear `Ver Juego.yml`. |
| **D3** | Credenciales por entorno: variables de login vacías + `secret: true`; el WIP sin commitear del checkout principal NO se integra (hash de password de cuenta inexistente, prohibido en git). |
| **D4** | Entornos estándar: `environments/Local.yml` + `Produccion.yml` (nombres exactos), `defaultEnvironment: Local` (flujo seguro, prod = opt-in explícito), variables `baseUrl`/`token`/`macAddress`/`deviceFingerprint`; login escribe con `bru.setEnvVar('token', ...)` en el entorno activo. |

Design A–K: seguidas al 100% (verificado en verify-report, tabla Coherence: A convención `/v1`, B entornos + default, C credenciales per-rol vacías, D MAC solo Local, E headers de dispositivo en root, F `setEnvVar` + `auth: none` + X-Panel, G batch legacy+scope, H hash WIP descartado, I cobertura 27, J script temporal no commiteado, K clave-cierre en carpeta Cierre de Caja).

## Task Completion Gate

`tasks.md` archivado: **32/32 casillas `[x]`** (1.1–1.17, 2.1–2.3, 3.1–3.6, 4.1–4.6) — sin tareas de implementación sin marcar (verificado: 0 `[ ]`). **Nota de traza**: la observación Engram #482 (`sdd/colecciones-api/tasks`) es el snapshot de la fase sdd-tasks; el artefacto vivo es el archivo `tasks.md` actualizado por apply — autoritativo para el gate. Estado nativo confirmado por `gentle-ai sdd-status`: `taskProgress 32/32 allComplete: true`, `dependencies.archive: ready`, `applyState: all_done`.

## Review Gate

Sin artefactos de review para este candidato (no existe `openspec/changes/colecciones-api/reviews/`): `reviewGate` estructuralmente ausente → archive procede bajo política ordinaria.

## Specs sincronizadas a `openspec/specs/`

| Capability | Acción | Detalle |
|------------|--------|---------|
| colecciones-api | **Creada** (spec principal, capability nueva) | `openspec/changes/colecciones-api/specs/colecciones-api/spec.md` → `openspec/specs/colecciones-api/spec.md`. Delta solo `ADDED` → no destructivo (no aplica `rules.archive` de aviso). Transformación de framing mecánica vía `awk` (solo 2 cambios: inserción de `**Estado**: draft` tras el título y `## ADDED Requirements` → `## Requirements`, convención de specs principales p. ej. `tests-paralelos`/`limites`); `diff` completo muestra ÚNICAMENTE esas líneas de framing y el diff de la región de requirements es **vacío** (verbatim byte-idéntico). 6 requisitos / 13 escenarios. |

Sin requisitos REMOVED ni MODIFIED → sin merge destructivo. `openspec/config.yaml` (`rules.archive`: "avisar antes de fusionar deltas destructivos") no se dispara.

## Movimiento a archive

`openspec/changes/colecciones-api/` → `openspec/changes/archive/2026-09-30-colecciones-api/` vía `git mv` (6 archivos trackeados: design, explore, proposal, specs/colecciones-api/spec, tasks, verify-report). Readback mecánico obligatorio: `diff -r` snapshot-pre-move (recursivo, `mktemp`) vs tree archivado → **vacío (exit 0, sin diferencias)**, única evidencia de byte-identity. `archive-report.md` (este archivo) es aditivo y quedó excluido de la comparación (no existía en el snapshot).

## Trazabilidad Engram (observaciones del ciclo)

| Artefacto | Observación Engram |
|-----------|--------------------|
| explore | #474 (`sdd/colecciones-api/explore`) |
| proposal | #475 (`sdd/colecciones-api/proposal`) |
| spec (delta) | #476 (`sdd/colecciones-api/spec`) |
| design | #477 (`sdd/colecciones-api/design`) |
| tasks (snapshot inicial; stale vs `tasks.md` vivo) | #482 (`sdd/colecciones-api/tasks`) |
| apply-progress (snapshot intermedio; cuenta 18/18 vs 32/32 reales) | #485 (`sdd/colecciones-api/apply-progress`) |
| verify-report | #487 (`sdd/colecciones-api/verify-report`) |
| archive-report (este artefacto) | `sdd/colecciones-api/archive-report` |

## Commits de la rama

| Commit | Contenido |
|--------|-----------|
| `36ac449` | docs(sdd): exploración del cambio colecciones-api |
| `331c1c7` | docs(sdd): propuesta del cambio colecciones-api |
| `907d515` | docs(sdd): specs del cambio colecciones-api |
| `bb2edab` | docs(sdd): diseño del cambio colecciones-api |
| `850afcb` | docs(sdd): tareas del cambio colecciones-api |
| `cb99fac` | docs(collections): entornos prod/local y convencion /api/v1 con limites al dia (F1) |
| `853b043` | docs(collections): prefijo /api/v1 en requests restantes y headers de dispositivo heredados (F2.1) |
| `8f25c20` | docs(collections): agrega agencias, tickets, configuraciones, logs, releases y requests faltantes (F2.2) |
| `7ecce22` | docs(collections): actualiza payloads vigentes y docs de roles (F2.3) |
| `ffca561` | docs(sdd): verify-report del cambio colecciones-api |
| este commit | docs(sdd): archiva colecciones-api (32/32, verify PASS WITH WARNINGS) |

## Contenido del archive

- proposal.md ✅ (D1–D4)
- specs/colecciones-api/spec.md ✅ (delta verbatim)
- design.md ✅ (A–K)
- tasks.md ✅ (32/32)
- explore.md ✅ (input de propose)
- verify-report.md ✅ (envelope `pass_with_warnings`)
- archive-report.md ✅ (este archivo, aditivo)

## Pendientes post-archive

1. **Gate humano Bruno UI PENDIENTE** (QA manual): 5 pasos del verify-report — login Local, requests de panel (Juegos/Limites/Cierre), piloto taquilla con MAC (herencia de headers root), pago autoritativo, conmutación a Produccion con tasa pública. El verify es PASS WITH WARNINGS con 0 CRITICAL; la confirmación visual humana es el único pendiente.
2. **Entrega planificada NO ejecutada**: PRs encadenados `stacked-to-main` (4 PRs: PR1 F1 → `main`, PR2 F2.1 → PR1, PR3 F2.2a/2b → PR2, PR4 F2.3 → PR3) — aún NO creados; la rama `feat/colecciones-api` no está merged ni pusheada.
3. SUGGESTIONs opcionales: header `Authorization` sobrante en `Releases/Servir Instalador.yml`; `password123` demo en payloads (inofensivo, migrable a variable); `seq` duplicados pre-existentes en `folder.yml`; reconciliar conteo administrativo 18/18 vs 32/32 en futuros reportes.