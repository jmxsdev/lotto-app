# Tasks: Modernización de colecciones de API (`colecciones-api`)

> Fase: sdd-tasks · Base: `d2f8261` · Formato OpenCollection 1.0.0 · Solo `collections/` (YAML).
> Cifras recomputadas desde el worktree: **81 requests**, **77** URLs sin `/v1`, **1** hardcodeada (`Juegos/Ver Juego.yml:8`), **1** `{id}` literal (`Ver Cierre:8`), **2** YAML inválidos (clave `value:` duplicada en `X-Device-Fingerprint`), **90 endpoints** en `api.php` (70 rutas + 5 `apiResource`), **27 faltantes** = 2 en F1 + 25 en F2.2.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1500–1650 (total, 4 PRs) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (F1) → PR 2 (F2.1) → PR 3 (F2.2a/2b) → PR 4 (F2.3) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

```
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Medium
```

Riesgo por slice: F1 ≈ 325 · F2.1 ≈ 300 · **F2.2a/2b ≈ 550 (excede 400)** · F2.3 ≈ 400. PR 3 es el slice mayor; fallback documentado: separar 2a/2b en dos PR si la revisión lo exige.

### Suggested Work Units

| Unit | Goal | PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|----|----------------------|-----------------|-------------------|
| WU-F1 | Entornos + convención + Límites/Cierre/Ver Juego | PR 1 | `pnpm dlx js-yaml collections/environments/*.yml collections/opencollection.yml collections/Auth/*.yml collections/Limites/*.yml "collections/Cierre de Caja"/*.yml "collections/Juegos/Ver Juego.yml"` exit 0 + `rg -c "bru\.setEnvVar\('token'" collections/Auth` → 5 | N/A — sin `bru` CLI; gate humano Bruno UI (piloto F1) | `git revert` PR 1 revierte entornos+convención+headers root juntos |
| WU-F2.1 | `/v1` mecánico + headers de dispositivo heredados | PR 2 | `rg -n --pcre2 'url: "\{\{baseUrl\}\}/api/(?!v1/)' collections` → vacío + parse all `invalid=0` | N/A — sin `bru` CLI; verificación estática | `git revert` PR 2 restaura URLs sin `/v1` y headers por-request (depende de F1) |
| WU-F2.2a/2b | Cobertura: 5 carpetas nuevas + 7 requests sueltos | PR 3 | parse de carpetas nuevas → válido + `find collections -name '*.yml' ! -name 'folder.yml' ! -name 'opencollection.yml' \| grep -v /environments/ \| wc -l` → 108 | N/A — sin `bru` CLI; gate humano (roles/estados) | `git revert` PR 3 elimina carpetas nuevas + sueltos (aditivo, independiente) |
| WU-F2.3 | Payloads vigentes + docs roles/estados + 2 YAML inválidos | PR 4 | parse all `invalid=0` + `rg -n '"fraccion"\|"limite_tiempo"' collections` → vacío + `rg -n 'selecciones' collections/Apuestas` → presente | N/A — sin `bru` CLI; gate humano (pago autoritativo) | `git revert` PR 4 restaura payloads/docs antiguos (depende de F2.2) |

## Partición F2.2 corregida (autoridad: filesystem + `api.php`)

**F2.2a — carpetas nuevas: 5 carpetas, 18 requests + 5 `folder.yml`**

| Carpeta (seq) | Requests | Count |
|---|---|---|
| `Agencias/` (15) | Listar, Crear, Ver, Actualizar, Toggle, Eliminar | 6 |
| `Tickets/` (16) | Listar, Crear, Ganadores, Ver, Anular | 5 |
| `Configuraciones/` (17) | Ver Vencimiento, Actualizar Vencimiento | 2 |
| `Logs/` (18) | Listar Logs | 1 |
| `Releases/` (19) | Última Release, Descargar Instalador, Servir Instalador, Update Check | 4 |

**F2.2b — sueltos en carpetas existentes: 7 requests**

| Carpeta | Request |
|---|---|
| `Bancas/` | Toggle Banca |
| `Grupos/` | Toggle Grupo |
| `Taquillas/` | Toggle Taquilla |
| `Cierre de Caja/` | Ver Cierre Actual · Ver Clave de Cierre (GET) |
| `Resultados/` | Ver Apariciones |
| `Reportes/` | Cuadre de Caja |

Totales: **27 faltantes = 2 (F1) + 25 (F2.2)**; F2.2 = 25 requests + 5 `folder.yml` = 30 archivos.

## Fase 1 — WU-F1 (PR 1 → `main`)

- [x] 1.1 Crear `collections/environments/Local.yml` (YAML exacto design §Entornos).
- [x] 1.2 Crear `collections/environments/Produccion.yml` (YAML exacto design §Entornos).
- [x] 1.3 Modificar `collections/opencollection.yml`: quitar `baseUrlWeb` y `request.variables`; añadir `request.headers` (X-Device-MAC/Fingerprint) y `extensions.bruno.defaultEnvironment: Local`.
- [x] 1.4 Modificar `collections/Auth/Login.yml`: url `{{baseUrl}}/api/v1/login`, `auth: none`, body `{{superEmail}}/{{superPassword}}`, `X-Panel: "true"`, `bru.setEnvVar('token', …)`, log sin token.
- [x] 1.5 Modificar `Auth/Login (Master).yml` (masterEmail/masterPassword).
- [x] 1.6 Modificar `Auth/Login (Banca).yml` (bancaEmail/bancaPassword).
- [x] 1.7 Modificar `Auth/Login (Grupo).yml` (grupoEmail/grupoPassword).
- [x] 1.8 Modificar `Auth/Login (Taquilla).yml`: sin `X-Panel`, taquillaEmail/taquillaPassword, `auth: none`, `setEnvVar`.
- [x] 1.9 Modificar `Limites/Listar Limites por Juego.yml`: url `/v1` + `docs:` roles SM|M|B|G|A.
- [x] 1.10 Modificar `Limites/Configurar Limite.yml`: url `/v1`; body sin `fraccion`/`limite_tiempo`; `banca_id` required.
- [x] 1.11 Modificar `Limites/Configurar Limite (Batch).yml`: url `/v1`; body legacy; `docs:` ejemplo `scope`.
- [x] 1.12 Crear `Limites/Listar Limites (Matriz).yml`: `GET /limites?banca_id=1`; `docs:` XOR entidad/scope.
- [x] 1.13 Crear `Limites/Eliminar Limite.yml`: `DELETE /limites/1`; `docs:` roles SM|M|B.
- [x] 1.14 Modificar `Cierre de Caja/Listar Cierres (Token required).yml`: url `/v1`.
- [x] 1.15 Modificar `Cierre de Caja/Ver Cierre (Token required).yml`: url `/v1` y reemplaza `{id}` → `/cierre/1`.
- [x] 1.16 Modificar `Cierre de Caja/Crear Cierre (Token required).yml`: eliminar header `X-Device-MAC` deshabilitado.
- [x] 1.17 Modificar `Juegos/Ver Juego.yml`: url `{{baseUrl}}/api/v1/juegos/1` (deshardcode; apunta a `show`).

## Fase 2 — WU-F2.1 (PR 2 → PR 1)

- [x] 2.1 Script temporal `/v1` (rg `--pcre2` + `sed`, NO commitear) en los **67** archivos sin `/v1` (77 − 10 F1).
- [x] 2.2 Eliminar headers por-request `X-Device-MAC`/`X-Device-Fingerprint` (31 archivos) y bloques `runtime.variables` `macAddress` (20 archivos) vía python3 puntual; colapsa el bloque malformado de las 2 Apuestas.
- [x] 2.3 `git diff --stat` (revisión manual obligatoria, ~67 archivos, 1–4 líneas).

## Fase 3 — WU-F2.2a/2b (PR 3 → PR 2)

- [x] 3.1 Crear `collections/Agencias/` + `folder.yml` (seq 15) + 6 requests (Listar/Crear/Ver/Actualizar/Toggle/Eliminar).
- [x] 3.2 Crear `collections/Tickets/` (seq 16) + 5 requests (Listar/Crear/Ganadores/Ver/Anular); roles sin agencia.
- [x] 3.3 Crear `collections/Configuraciones/` (seq 17) + 2 requests (Ver/Actualizar Vencimiento).
- [x] 3.4 Crear `collections/Logs/` (seq 18) + 1 request (Listar Logs).
- [x] 3.5 Crear `collections/Releases/` (seq 19) + 4 requests (Última/Descargar/Servir/Update Check).
- [x] 3.6 Crear sueltos: Bancas Toggle, Grupos Toggle, Taquillas Toggle, Cierre Actual, Clave Cierre (GET), Apariciones, Cuadre de Caja.

## Fase 4 — WU-F2.3 (PR 4 → PR 3)

- [x] 4.1 Apuestas: crear `Crear Apuesta (Animalitos - Tripleta).yml` (`selecciones[]`); filtros en `Listar Apuestas` + `Historial`.
- [x] 4.2 Pagos: `Registrar Pago (Token required)` → egreso sin montos (premio autoritativo); `docs:` estados `pendiente|ganadora`.
- [x] 4.3 Usuarios: `agencia_id` + rol `agencia`; Taquillas: `agencia_id` required.
- [x] 4.4 Juegos: `Reglas (×3)` `docs:` respuesta con `premios` + `modalidades`.
- [x] 4.5 Auth/Cierre: `docs:` roles/estados por archivo (convención 4 líneas).
- [x] 4.6 Reparar/verificar los 2 YAML inválidos (`Crear Apuesta (Terminales).yml`, `Crear Apuesta (Triple Zulia).yml` — clave `value:` duplicada en `X-Device-Fingerprint`).

## Verificación por WU (comandos reales)

Parseo (debe salir `invalid=0`): `fail=0; while IFS= read -r f; do pnpm dlx js-yaml "$f" >/dev/null 2>/dev/null || { echo "INVALID $f"; fail=1; }; done < <(find collections -name '*.yml'); echo "invalid=$fail"`

Invariantes `rg` (cierre F2):
- `rg -n --pcre2 'url: "\{\{baseUrl\}\}/api/(?!v1/)' collections` → vacío
- `rg -n 'url:\s*https?://' collections` → vacío · `rg -n '\{id\}' collections` → vacío · `rg -n 'lotto-api-6nrc' collections` → vacío
- `rg -c "bru\.setEnvVar\('token'" collections` → 5 · `rg -n "bru\.setVar\('token'" collections` → vacío
- `rg -n 'd8:f3:bc:65:d1:c5' collections` → solo `environments/Local.yml`
- `rg -n '"fraccion"|"limite_tiempo"|3d02b41a' collections` → vacío
- Requests: `find collections -name '*.yml' ! -name 'folder.yml' ! -name 'opencollection.yml' | grep -v /environments/ | wc -l` → **108** (81 + 27)

## Gate humano (QA pendiente, Bruno Local)

Sin `bru` CLI instalado → el flujo ejecutable es gate humano: (1) seleccionar `Local`; (2) rellenar `superEmail/superPassword`, ejecutar `Auth/Login` → 200 + token; (3) `Juegos/Listar Juegos`, `Limites/Listar Limites por Juego`, `Cierre de Caja/Listar Cierres` → 200 sin editar archivos; (4) **piloto F1**: login taquilla (dispositivo registrado) + request → 200 (prueba herencia de headers root; fallback per-request si falla); (5) `Produccion` + `Tasas de Cambio/Obtener Tasa Activa` (pública) → 200.
