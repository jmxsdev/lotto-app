# Apply Progress: ci-cd-preservar-taquilla

## Estado

- **WU1 (tareas 1.1–1.4)**: COMPLETADO — commit `178a68a` en `feat/ci-cd-preservar-taquilla`, mergeado en `main`.
- **Fase 2 (2.1–2.3, migración VPS)**: COMPLETADA por el operador — release 1.0.3 publicada y `update-check` respondiendo `{"version":"1.0.3","sha256":"…"}` (REQ-5 verificado en el VPS).
- **Tarea 3.1 (chequeo CI post-deploy)**: COMPLETADO — WU2, commit `8fc682c` en `feat/ci-cd-guard-releases` (base `origin/main` = `86c7733`). Habilitado tras la Fase 2 verificada.
- **Fase 4 (4.1–4.2, verificación final)**: PENDIENTE — corresponde a la fase `sdd-verify` tras la migración.

## Alcance ejecutado (WU2 — tarea 3.1)

| Tarea | Estado | Resultado |
|---|---|---|
| 3.1 `.github/workflows/ci-cd.yml` job `deploy` — guard REQ-6 post-healthcheck | [x] | Bloque insertado entre el `fi` del healthcheck y `echo "deploy OK"` (commit `8fc682c`). Verifica mount destino == `/app/storage/app/releases` (siempre) y, si `update-check` responde 200, compara su `sha256` con `sha256sum` del `.exe` en el volumen. Fallo → `exit 1` sin rollback de imagen. |

Archivo tocado (solo WU2):

| Archivo | Acción |
|---|---|
| `.github/workflows/ci-cd.yml` | Modificado (+16 líneas: guard REQ-6 en job `deploy`) |
| `openspec/changes/ci-cd-preservar-taquilla/tasks.md` | Modificado (`[x]` 2.1–2.3 con nota de operador, `[x]` 3.1) — no versionado (gitignored) |
| `openspec/changes/ci-cd-preservar-taquilla/apply-progress.md` | Actualizado (merge WU1 + WU2) — no versionado (gitignored) |

> Nota de versionado: el commit `8fc682c` contiene solo `.github/workflows/ci-cd.yml`;
> los artefactos de `openspec/changes/*` persisten en filesystem + engram (gitignored).

## Evidencia de work unit (WU2)

| Evidencia | Valor |
|---|---|
| Comando de test enfocado y resultado | Lint YAML: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci-cd.yml'))"` → **exit 0**. Sintaxis bash del bloque `run` extraído: `bash -n` → **exit 0**. Harness funcional simulando `ssh` contra el VPS (5 escenarios, `/tmp/opencode/guard-test.sh`): mount ausente → `exit 1` + mensaje claro · sha256 coincide → pasa · sha256 difiere → `exit 1` + mensaje · `update-check` 404 (sin release) → pasa (solo mount) · anunciada sin archivo en volumen → `exit 1`. **5/5 PASS** |
| Harness de runtime / escenario | **N/A** — no existe frontera de runtime local para un cambio de workflow CI: el contenedor real (`lotto_api_prod`) vive en el VPS y requiere SSH; el guard se probó con stub de `ssh` (mismos comandos remotos, quoting anidado incluido). La ejecución real ocurre en el próximo deploy a `main`. |
| Frontera de rollback | `git revert 8fc682c` revierte solo `.github/workflows/ci-cd.yml` sin tocar otro trabajo. Sin guard habilitado, el deploy vuelve al comportamiento anterior (sin verificación REQ-6); el volumen `taquilla_releases` no se ve afectado. |

## Alcance ejecutado (WU1)

| Tarea | Estado | Resultado |
|---|---|---|
| 1.1 `docker-compose.prod.yml` mount → `/app/storage/app/releases` + comentario | [x] | `docker compose config` renderiza `source: taquilla_releases, target: /app/storage/app/releases` |
| 1.2 `manual-mantenimiento.md` §2.1 tabla servicio `api` | [x] | Ya no menciona `/var/www/html`; indica ruta real + persistencia entre deploys |
| 1.3 `manual-mantenimiento.md` §4.4 + ref. volúmenes + registro | [x] | Flujo describe mount real en `/app/storage/app/releases`; ref. `:138-143`; registro actualizado |
| 1.4 `runbook-ops.md` §"Taquilla Windows release" paso 5 | [x] | Verificación en `/app/storage/app/releases` + `sha256sum` vs `update-check` + nota de migración |

Archivos tocados (solo WU1, sin `backend/`/`taquilla/`/`panel/`):

| Archivo | Acción |
|---|---|
| `docker-compose.prod.yml` | Modificado |
| `docs/dev/manual-mantenimiento.md` | Modificado |
| `docs/dev/runbook-ops.md` | Modificado |
| `openspec/changes/ci-cd-preservar-taquilla/tasks.md` | Modificado (`[x]` 1.1–1.4, nota en 3.1) — no versionado (gitignored) |
| `openspec/changes/ci-cd-preservar-taquilla/apply-progress.md` | Creado — no versionado (gitignored) |

> Nota de versionado: los artefactos activos de `openspec/changes/*` están en
> `.gitignore` (`*.md` salvo `docs/**/*.md`); persisten en filesystem + engram,
> como en las fases anteriores del cambio. El commit `178a68a` contiene solo los
> 3 archivos de WU1.

## Evidencia de work unit (WU1)

| Evidencia | Valor |
|---|---|
| Comando de test enfocado y resultado | `docker compose -f docker-compose.prod.yml config` (con `.env.production` temporal vacío, gitignored, eliminado tras validar) → **exit 0**; el servicio `api` renderiza `volumes: [{type: volume, source: taquilla_releases, target: /app/storage/app/releases}]`. Criterio de tarea 1.1 cumplido. |
| Harness de runtime / escenario | **N/A** — no existe frontera de runtime local para un cambio compose/docs: el contenedor real (`lotto_api_prod`) vive en el VPS y requiere SSH; su verificación (`docker exec lotto_api_prod ls -la /app/storage/app/releases`) es la Fase 2 del operador. |
| Frontera de rollback | `git revert 178a68a` revierte `docker-compose.prod.yml` + las dos docs sin tocar otro trabajo. El named volume `taquilla_releases` nunca se destruye con `up -d`/rollback (design.md §Rollback): **nunca** usar `down -v`. |

## Decisiones de diferimiento

- **Fase 2 (2.1–2.3)**: operativa con SSH al VPS; la ejecutó el operador siguiendo
  el runbook (paso 5 + nota de migración) y el design.md §"Migración verificable (VPS)".
  **Resultado**: release 1.0.3 publicada y `update-check` respondiendo
  `{"version":"1.0.3","sha256":"…"}` → REQ-5 verificado.
- **Tarea 3.1 (chequeo CI post-deploy)**: diferida hasta la Fase 2 verificada (design.md
  línea 49) — habilitarla antes habría hecho fallar el primer deploy con el fix: la DB
  anunciaba una versión cuyo archivo se perdió (vivía en la capa del contenedor viejo),
  así que `update-check` respondía 200 sin archivo en el volumen → `exit 1`. Con REQ-5
  verificado en el VPS, el guard quedó habilitado en el WU2 (commit `8fc682c`).
- **Tareas 4.1/4.2**: corresponden a `sdd-verify`; `composer test` queda fuera del alcance
  de apply (ver abajo).

## Strict TDD / testing

- `strict_tdd: true` en `openspec/config.yaml` con `test_command: composer test`, PERO este
  cambio NO contiene código de aplicación (solo compose/docs/CI): no se inventaron tests ni
  se tocó `backend/`/`taquilla/`/`panel/`. `composer test` queda fuera de alcance — no hay
  código PHP nuevo que probar (`ReleaseController` intacto, REQ-3 sin cambios; design.md §Testing).
- Evidencia de work unit presentada en las tablas superiores en lugar de ciclo TDD RED/GREEN,
  por ausencia de frontera de código. El RED test de la threat matrix (Nuevo shell/SSH CI,
  read-only) se cubre con el harness de 5 escenarios del WU2 (stub de `ssh`).

## Desviaciones del design

WU1: ninguna. La implementación coincide con design.md (decisión 1 y orden de archivos 1–3). El
comentario del compose se colocó en línea previa al mount (no inline) por legibilidad del YAML
y para que `docker compose config` lo preserve sin tocar el valor.

WU2: ninguna. El guard sigue la decisión 3 (inspect + sha256 condicional) y el estilo del
workflow (`set -euo pipefail`, `ssh $SSH_OPTS "$TARGET"`, mensajes en español, `|| true`
donde el fallo del chequeo debe convertirse en `exit 1` claro).

## Riesgos descubiertos

- `docker compose config` falla sin `.env.production` presente (env_file obligatorio); en el
  VPS existe, pero validaciones locales del workflow necesitan un placeholder vacío.
- El `.exe` vigente puede no existir en el contenedor viejo (open question del design): se
  resuelve en el paso pre-deploy de la Fase 2; el runbook ya cubre el rescue por `scp` + re-publicar.
- (WU2) Con `set -o pipefail`, si el `ssh` de `update-check` falla dentro de `EXP=$(ssh … | sed …)`,
  la pipeline falla y `set -e` aborta el deploy sin mensaje propio (exit 255 de ssh). Es un fallo
  conservador y aceptable (verificación no ejecutable → deploy fallido, alineado con REQ-6); en la
  práctica es improbable porque solo ocurre cuando `CODE=200` (el endpoint respondió instantes antes).
- (WU2) El glob `*.exe` asume a lo sumo un instalador en el volumen; `releases:publish` borra los
  archivos previos (D3), así que el `head -1` del guard es seguro. Si hubiera archivos huérfanos
  acumulados, el guard podría comparar el primero en orden de glob: no es el caso esperado.