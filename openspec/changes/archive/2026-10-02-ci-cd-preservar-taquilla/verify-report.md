```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:c87cc45f07425dbf54f1c80664c0422d2fef77d90e954da530c8d9c0886166e3
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 4/6
scenarios: 5/7
test_command: bash /tmp/opencode/guard-test.sh
test_exit_code: 0
test_output_hash: sha256:61fdfc0796cac5fda5b99bbd60fa0436ad6f0705a23609e5a5e907bd7b8d47c8
build_command: /tmp/opencode/yamlvenv/bin/python -c "import yaml; yaml.safe_load(open('.github/workflows/ci-cd.yml'))" && bash -n /tmp/opencode/guard.sh
build_exit_code: 0
build_output_hash: sha256:5c37c87a7aba4ea5970a5b91d805577d69b4fb697fbfc2e9bacfadb6d990057e
```

# Verify Report: ci-cd-preservar-taquilla

**Cambio**: `ci-cd-preservar-taquilla`
**Commits verificados**: `178a68a` (WU1, mergeado en `main` vía PR #55 → `32d87ed`) + `8fc682c` (WU2, rama `feat/ci-cd-guard-releases`, base `origin/main` = `86c7733`)
**Especificación**: `specs/distribucion-instalador-taquilla/spec.md` · **Modo**: Standard (cero código de aplicación en todo el cambio: compose + docs + workflow)

## Completeness

| Métrica | Valor |
|---|---|
| Tareas totales (tasks.md) | 10 |
| Tareas completas | 10 (1.1–1.4 WU1 · 2.1–2.3 operador · 3.1 WU2 · 4.1–4.2 esta fase) |
| Tareas incompletas | 0 |
| Nota 4.1 | `composer test` **N/A por constraint del orquestador**: el cambio toca cero código de aplicación (`backend/`, `taquilla/`, `panel/` intactos; diff = compose + 2 docs + workflow). No se instaló vendor ni se inventaron tests. |

## Build & Tests Execution

**Build / validación sintáctica**: ✅ exit 0
```text
YAML OK, jobs: ['tests', 'front-checks', 'build', 'deploy']
BASH_N OK (bloque REQ-6 extraído del workflow vivo)
```
(`build_output_hash` = `sha256:5c37c87a…`)

**Test enfocado (harness WU2)**: ✅ 5/5 PASS — exit 0
```text
PASS  mount_missing (exit=1, msg ok)
PASS  sha_ok (exit=0, msg ok)
PASS  sha_mismatch (exit=1, msg ok)
PASS  sin_release (exit=0, msg ok)
PASS  anunciada_sin_archivo (exit=1, msg ok)
```
El harness (`/tmp/opencode/guard-test.sh`) extrae el bloque REQ-6 **del workflow vivo** (mismo archivo verificado, commit `8fc682c`) y simula `ssh` contra el VPS con 5 escenarios: mount ausente → `exit 1`; sha coincide → pasa; sha difiere → `exit 1`; `update-check` 404 (sin release) → pasa solo con mount; anunciada sin archivo → `exit 1`. (`test_output_hash` = `sha256:61fdfc07…`)

**Evidencia de runtime (REQ-1 / REQ-5)** — endpoints públicos de `https://lotto.gzuz.dev`:
```text
GET /api/v1/update-check            → 200 {"version":"1.0.3","sha256":"694ff57aea97c1163f7e9af67146a5e4d66f6e1df56d229566eeb98bb0aee10e"}
GET /api/v1/releases/download       → 401 "No autenticado." (auth:sanctum + rol panel)
GET /api/v1/releases/serve (sin firma) → 403 "Firma de URL inválida o expirada." (firma = credencial)
GET /api/v1/releases/feed/latest.yml → 200 (version: 1.0.3, url: Taquilla-Setup-1.0.3.exe, size: 134616307)
GET /api/v1/releases/feed/Taquilla-Setup-1.0.3.exe → 200 (134.616.307 bytes)
sha256 del .exe servido             → 694ff57aea97c1163f7e9af67146a5e4d66f6e1df56d229566eeb98bb0aee10e
                                     == update-check.sha256 (COINCIDE EXACTO)
```
La descarga firmada (`download` → URL temporal → `serve`) **no es ejecutable sin autenticación** (por diseño: la firma ES la credencial, REQ-A2/A3, fuera del alcance del cambio). El feed público (`releases/feed/{file}`, whitelist contra la fila actual) sirve **el mismo archivo desde el mismo disco** (`Storage::disk('releases')`, root = `/app/storage/app/releases`): 200 + sha256 idéntico = prueba de runtime de que el instalador es servible, persiste (el contenedor fue recreado en el deploy con el mount corregido y el archivo sigue servido) y es consistente con `update-check`.

**Coverage**: ➖ No aplica (sin código en el cambio).

## Spec Compliance Matrix

| Requisito | Escenario | Evidencia | Resultado |
|---|---|---|---|
| REQ-1 Persistencia tras deploy | Persistencia tras deploy | Runtime (vía feed público, mismo disco): `.exe` servido 200, 134.616.307 bytes, sha256 == `update-check.sha256` (exacto); deploy con mount corregido ya ejecutado en prod y el archivo sigue servido → persiste. El literal `serve` con URL firmada no es ejecutable públicamente (la URL firmada solo la emite `download`, auth Sanctum + rol panel) | ⚠️ PARTIAL |
| REQ-2 Publicación en la ruta real | Nueva publicación | Cadena estática: disco `releases` root = `storage_path('app/releases')` (`filesystems.php:52`) == mount target (`compose:52`); `releases:publish` ejecutado por el operador (2.2, release 1.0.3) y resultado verificado en runtime (update-check 200 + feed 200 + sha coincide); fila única por modelo D3 (`current()`) | ✅ COMPLIANT |
| REQ-3 Contrato notify-only sin cambios | Contrato notify-only intacto | Runtime: `update-check` responde solo `version` + `sha256` (sin URL de descarga); estático: `ReleaseController::updateCheck()` (líneas 87-99) + rutas sin URL; diff de WU1/WU2 no toca `backend/` | ✅ COMPLIANT |
| REQ-4 Montaje y documentación del volumen | Volumen montado en la ruta real | `docker-compose.prod.yml:52` → `taquilla_releases:/app/storage/app/releases` + comentario atado a `WORKDIR=/app`; validado por `docker compose config` (WU1, exit 0) y re-inspeccionado en esta fase | ✅ COMPLIANT |
| REQ-4 Montaje y documentación del volumen | Documentación actualizada | `manual-mantenimiento.md` §2.1 (línea 49), §4.4 (líneas 195/200/211) y registro; `runbook-ops.md` paso 5 (líneas 126-147) + nota de migración; **cero** referencias a `/var/www/html` en docs | ✅ COMPLIANT |
| REQ-5 Migración consistente de la release vigente | Migración sin release previa | Fase 2 completada por el operador (2.1–2.3, rescue + re-publicar según design y runbook); runtime: `update-check` 200 + feed 200 + sha coincide → `serve` deja de devolver 404 (mismo disco/archivo; `serve` aborta 404 si el archivo no existe). Literal `serve` no ejecutable públicamente (ver REQ-1) | ⚠️ PARTIAL |
| REQ-6 Verificación post-deploy | Chequeo post-deploy en CI | Harness 5/5 PASS sobre el workflow vivo; YAML válido; `bash -n` OK; bloque ubicado entre el `fi` del healthcheck (línea 179) y `echo "deploy OK"` (línea 196); falla con `exit 1` **sin** rollback de imagen (el bloque de rollback solo está en la rama del healthcheck) | ✅ COMPLIANT |

**Resumen de cumplimiento**: 5/7 escenarios COMPLIANT, 2/7 PARTIAL (REQ-1, REQ-5 — literal `serve` con URL firmada no verificable sin auth), 0 NOT VERIFIED.

## Correctness (Static Evidence)

| Requisito | Estado | Notas |
|---|---|---|
| REQ-1 | ✅ Implementado + runtime vía feed | sha256 servido == `update-check` (exacto); `serve` literal pendiente de verificación operativa con URL firmada |
| REQ-2 | ✅ Implementado + verificado | Publish ejecutado por el operador; disco == mount target |
| REQ-3 | ✅ Implementado | Código intacto; contrato notify-only confirmado en runtime |
| REQ-4 | ✅ Implementado | Mount corregido (compose:52) y documentado; sin referencias a la ruta vieja |
| REQ-5 | ✅ Implementado + verificado | Migración ejecutada (2.1–2.3); consistencia probada en runtime |
| REQ-6 | ✅ Implementado + probado | Guard REQ-6 en job `deploy`, 5/5 escenarios del harness |

## Coherence (Design)

| Decisión | ¿Seguida? | Notas |
|---|---|---|
| 1 · Opción A (corregir ruta a `/app/storage/app/releases`) | ✅ Sí | `compose:52`; validada por `docker compose config` |
| 2 · Migración rescue + re-publicar | ✅ Sí | Design §"Migración verificable (VPS)": copy-on-mount descartado (`.gitignore` excluye `releases/` de la imagen); operador re-publicó 1.0.3 post-deploy |
| 3 · Chequeo CI (SHOULD) — inspect + sha256 condicional | ✅ Sí | WU2 `8fc682c`: mount destino (siempre) + sha256 solo si `update-check` 200; `exit 1` sin rollback de imagen; habilitado tras Fase 2 verificada (design:49) |
| 4 · Documentación en el mismo cambio | ✅ Sí | WU1: §2.1, §4.4, registro, runbook paso 5 |
| Comentario compose en línea previa (no inline) | ✅ Sí | Desviación cosmética documentada en apply-progress |

## Issues Found

**CRITICAL**: None

**WARNING**:
- REQ-1 y REQ-5 en ⚠️ PARTIAL: el literal `GET /api/v1/releases/serve` con URL firmada no es verificable sin autenticación (la URL firmada la emite `download`, que exige Sanctum + rol panel). La verificación pública equivalente (feed, mismo disco y archivo) es 200 con sha256 idéntico, así que la evidencia runtime es sólida; resta solo la ejecución operativa del flujo firmado (o decisión explícita del orquestador de aceptar la equivalencia, dado que la firma ES la credencial por diseño REQ-A2/A3).
- El guard REQ-6 se ejecutará por primera vez **en el próximo deploy real a `main`** (el harness cubre la lógica con stub de ssh; la ejecución real ocurre en CI). Riesgo residual bajo, documentado en apply-progress (pipefail + ssh 255 → deploy conservadoramente rojo).

**SUGGESTION**:
- Docblock duplicado en `ReleaseController::serve()` (líneas 57-66): preexistente, fuera del alcance de este cambio.
- El guard compara el primer `*.exe` del glob (`head -1`): seguro con el modelo D3 (fila única, `releases:publish` borra archivos previos); si algún día se acumulan huérfanos, convendría filtrar por `$EXP`.

## Bloqueadores / Critical

Ninguno. No hay contradicción entre spec, design e implementación; WU1 y WU2 coinciden con design.md; apply-progress no reporta desviaciones funcionales; Fase 2 completada por el operador con release 1.0.3 servida y consistente.

## Veredicto

**PASS WITH WARNINGS** — Implementación completa y verificada: WU1 mergeado en `main` (PR #55), Fase 2 ejecutada por el operador (1.0.3 publicada), WU2 (guard REQ-6) con harness 5/5 PASS, y evidencia runtime de que la release publicada es servible, persiste y es consistente (feed 200 + sha256 idéntico a `update-check`). Los 2 escenarios PARTIAL (REQ-1, REQ-5) solo requieren el literal `serve` firmado, no ejecutable públicamente por diseño de auth; no son defectos del cambio. **El cambio NO está fully compliant (4/6 requisitos, 5/7 escenarios), por lo que NO se recomienda `sdd-archive` todavía**: resta la verificación operativa del flujo firmado (operador con credenciales/SSH) o una decisión explícita del orquestador que acepte la equivalencia feed↔serve documentada.

## Nota de admisión del validador

`gentle-ai sdd-verify-validate --requirements 6 --scenarios 7` se ejecutó contra estos bytes. Con conteos honestos `requirements: 4/6`, `scenarios: 5/7` y `verdict: pass_with_warnings`, la regla mecánica del validador mapea evidencia incompleta a `fail` (mismo comportamiento que en el verify anterior de este cambio, documentado en la versión previa de este reporte). El orquestador instruyó explícitamente persistir este reporte con conteos honestos y veredicto `pass_with_warnings` (precedente establecido en la iteración anterior del cambio: los escenarios no verificables por diseño no deben convertir el cambio en FAIL). Si el validador deniega la admisión, el reporte no es "archive-ready" hasta completar la verificación operativa de REQ-1/REQ-5 (literal `serve` firmado).