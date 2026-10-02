# Archive Report: ci-cd-preservar-taquilla

**Fecha de archivo**: 2026-10-02
**Rama de archivo**: `feat/ci-cd-guard-releases` (worktree `ci-cd-preservar-taquilla`)
**Modo de artefactos**: hybrid (openspec + engram)
**Almacenado en**: `openspec/changes/archive/2026-10-02-ci-cd-preservar-taquilla/` + engram `sdd/ci-cd-preservar-taquilla/archive-report`

## Veredicto

**ARCHIVADO — verify PASS WITH WARNINGS, 0 blockers / 0 criticals.** El ciclo SDD del
cambio `ci-cd-preservar-taquilla` queda cerrado. 4/6 requisitos y 5/7 escenarios
COMPLIANT; los 2 escenarios PARTIAL (REQ-1, REQ-5) fueron aceptados explícitamente por
el orquestador con la equivalencia documentada `releases/feed` ↔ `serve` y la prueba
operativa del operador en producción (ver «Resolución de los PARTIAL»).

**Gate de tareas**: 10/10 tareas completas (1.1–1.4 WU1 · 2.1–2.3 operador · 3.1 WU2 ·
4.1–4.2 verificación). Los checkboxes 4.1/4.2 quedaron sin marcar en `tasks.md` al
persistir `apply-progress` (la Fase 4 pertenece a `sdd-verify`); `verify-report.md`
los atestigua completos («Tareas completas: 10 … 4.1–4.2 esta fase», «Tareas
incompletas: 0») y los hechos finales del orquestador confirman el PASS. **Reconciliación
excepcional en archive**: se marcaron `[x]` 4.1/4.2 con la nota de evidencia, alineando
el artefacto con la convención del repositorio (0 checkboxes sin marcar en los 10
archivos previos) y con la prueba de `verify-report` + hechos finales. Sin esta
reconciliación, la pista de auditoría archivada contendría tareas completas sin marcar.

**Gate de revisión**: `reviewGate` estructuralmente ausente (no se descubrió ningún
artefacto de revisión para este candidato) → archivo bajo política ordinaria del repositorio.

## Resumen de lo entregado

Cero código de aplicación en todo el cambio (solo compose + docs + workflow de CI).

| Work unit | Contenido | Entrega |
|---|---|---|
| **WU1** (`178a68a`) | Corrección del mount del named volume `taquilla_releases` → `/app/storage/app/releases` en `docker-compose.prod.yml` (comentario atado a `WORKDIR=/app`), corrección de `docs/dev/manual-mantenimiento.md` (§2.1, §4.4, registro) y `docs/dev/runbook-ops.md` (paso 5 con `sha256sum` + nota de migración) | **Mergeado en `main`** — PR #55, merge `32d87ed` |
| **Fase 2** (operativa, sin código) | Migración verificable en VPS: rescue + re-publicación de la release vigente (1.0.3) siguiendo el runbook y design §"Migración verificable (VPS)" | Ejecutada por el **operador** en producción |
| **WU2** (`8fc682c`) | Guard post-deploy en `.github/workflows/ci-cd.yml` job `deploy` (REQ-6): `docker inspect` verifica mount destino == `/app/storage/app/releases`; si `update-check` responde 200, compara `sha256(update-check)` vs `sha256sum` del `.exe` en el volumen; fallo → `exit 1` sin rollback de imagen | **Entregado en PR #59 (ABIERTA, aún no mergeada)** |

## PRs

| PR | Estado | Contenido |
|---|---|---|
| **#55** | ✅ **Mergeado** (`32d87ed` en `main`) | WU1: mount + docs |
| **#59** | ⏳ **Abierta** | WU2: guard REQ-6 en CI (commit `8fc682c`) |

## Evidencia de runtime (producción, `https://lotto.gzuz.dev`)

```text
GET /api/v1/update-check                      → 200 {"version":"1.0.3","sha256":"694ff57aea97c1163f7e9af67146a5e4d66f6e1df56d229566eeb98bb0aee10e"}
GET /api/v1/releases/download                 → 401 "No autenticado." (auth:sanctum + rol panel)
GET /api/v1/releases/serve (sin firma)        → 403 "Firma de URL inválida o expirada." (firma = credencial)
GET /api/v1/releases/feed/latest.yml          → 200 (version: 1.0.3, url: Taquilla-Setup-1.0.3.exe, size: 134616307)
GET /api/v1/releases/feed/Taquilla-Setup-1.0.3.exe → 200 (134.616.307 bytes)
sha256 del .exe servido                      → 694ff57aea97c1163f7e9af67146a5e4d66f6e1df56d229566eeb98bb0aee10e
                                             == update-check.sha256 (COINCIDE EXACTO)
```

- **Persistencia**: el deploy con el mount corregido ya se ejecutó en prod (recreación de
  `lotto_api_prod`) y el instalador sigue servido → el volumen sobrevive a la recreación.
- **Consistencia**: `update-check` (DB) y el archivo servido (disco) reportan el mismo
  sha256; nunca se anuncia una versión sin archivo servible.
- **Contrato notify-only**: `update-check` responde solo `version` + `sha256`, sin URL de
  descarga (REQ-3 intacto).
- **Harness del guard (WU2)**: 5/5 PASS (`mount_missing`, `sha_ok`, `sha_mismatch`,
  `sin_release`, `anunciada_sin_archivo`) contra el bloque extraído del workflow vivo;
  YAML válido + `bash -n` OK.

## Resolución de los PARTIAL (REQ-1 / REQ-5)

Los escenarios PARTIAL corresponden al literal `GET /api/v1/releases/serve` con URL
firmada, **inverificable públicamente por diseño**: la URL firmada solo la emite
`releases/download`, que exige autenticación Sanctum + rol panel — la firma ES la
credencial (REQ-A2/A3, fuera del alcance de este cambio).

**Decisión del orquestador (hechos finales, post-verify)**: se acepta la **equivalencia**
`releases/feed` ↔ `serve` como verificación operativa, con dos argumentos:

1. **Mismo disco / mismo archivo**: `Storage::disk('releases')` (root =
   `/app/storage/app/releases`) sirve ambos endpoints; el feed (whitelist contra la fila
   actual) devuelve 200 con **sha256 idéntico** al de `update-check`, lo que prueba que el
   archivo es servible, persiste y es consistente.
2. **Prueba operativa del operador en prod**: release 1.0.3 publicada, `update-check`
   respondiendo `{"version":"1.0.3","sha256":"694ff57a…"}` y feed 200 con el mismo sha256.

Queda documentado en `verify-report.md` (a la hora de verificar) que el flujo firmado
literal «no es ejecutable sin autenticación» y que el cambio no era archive-ready en ese
momento hasta una decisión explícita; esa decisión fue tomada por el orquestador en los
hechos finales de esta fase, que es la fuente de mayor autoridad sobre el estado de cierre.

## Especificaciones sincronizadas

| Capability | Acción | Detalles |
|---|---|---|
| `distribucion-instalador-taquilla` | **Creada** (nueva) | Spec completa copiada a `openspec/specs/distribucion-instalador-taquilla/spec.md` (no existía spec principal previa; 6 requisitos, 7 escenarios). Copia mecánica verificada con `diff -r` vacío. |

## Trazabilidad (engram, observaciones leídas/localizadas)

| Artefacto | Observación engram |
|---|---|
| explore | #530 (`sdd/ci-cd-preservar-taquilla/explore`) |
| proposal | #532 (`sdd/ci-cd-preservar-taquilla/proposal`) |
| spec | #534 (`sdd/ci-cd-preservar-taquilla/spec`) |
| design | #537 (`sdd/ci-cd-preservar-taquilla/design`) |
| tasks | #540 (`sdd/ci-cd-preservar-taquilla/tasks`) |
| apply-progress | #541 (`sdd/ci-cd-preservar-taquilla/apply-progress`) |
| verify-report | #544 (`sdd/ci-cd-preservar-taquilla/verify-report`) |
| resumen de ciclo (pre-archive) | #545 |

## Convención de versionado (openspec gitignored)

Los `.md` de `openspec/` están en `.gitignore` (`*.md`, salvo `docs/**/*.md`). La
convención del repositorio es **force-add** (`git add -f`) de las specs principales y de
la carpeta de archivo en el commit de cierre (`docs(sdd): archiva …`), como en los 10
archivos previos (p. ej. `d71481e`). En este archivo se siguió esa convención: el commit
incluye `openspec/specs/distribucion-instalador-taquilla/spec.md` y
`openspec/changes/archive/2026-10-02-ci-cd-preservar-taquilla/`. Los artefactos activos
de `openspec/changes/*` persisten en filesystem + engram (no versionados), como en fases
anteriores.

## Pendientes / limitaciones

1. **WU2 pendiente de merge**: el guard REQ-6 (PR #59) se ejecutará **por primera vez en
   el próximo deploy real a `main`**. Hasta entonces, la verificación post-deploy en CI no
   está activa en producción. Riesgo residual bajo (harness 5/5 con stub de `ssh`; fallo
   conservador `exit 1` sin rollback de imagen).
2. **Named volume sin backup restic**: `taquilla_releases` no está cubierto por el backup
   restic del VPS. Aceptado en proposal (el `.exe` se regenera con build), pero queda como
   limitación conocida: si el volumen se pierde, hay que re-publicar la release.
3. **Flujo firmado `serve` sin verificación operativa literal**: la equivalencia
   feed↔serve fue aceptada; si se quiere evidencia del flujo firmado completo, requiere un
   operador con credenciales (fuera del alcance público de este cambio).
4. **Glob `*.exe` en el guard**: compara el primer `.exe` del glob (`head -1`); seguro con
   el modelo D3 (fila única, `releases:publish` borra archivos previos). Si algún día se
   acumulan huérfanos, convendría filtrar por versión.

## Estado del árbol

- `openspec/changes/ci-cd-preservar-taquilla/` movido íntegro a
  `openspec/changes/archive/2026-10-02-ci-cd-preservar-taquilla/` (verificado con
  `diff -r` vacío contra snapshot pre-move).
- `openspec/specs/distribucion-instalador-taquilla/spec.md` creada (diff vacío contra la
  delta).
- Carpeta de cambios activos ya no contiene `ci-cd-preservar-taquilla`.
- Sin cambios en código de aplicación, docs de producto, ni merges de PR.