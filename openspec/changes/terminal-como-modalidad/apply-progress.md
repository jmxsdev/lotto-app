# Apply Progress: terminal-como-modalidad

Rama: `feat/terminal-como-modalidad` (feature-branch chain; PR 1 base = tracker).
Modo de implementación: Standard (no hay runner de tests de front; `apply.tdd: true`
apunta a `composer test`, que es backend y lo ejecuta el usuario).

## Estado por work unit

| WU | Alcance | Estado |
|----|---------|--------|
| WU1 | Fase 1 fronts + release (backend intacto) | **Implementado y verificado** (check-pure 663/0; e2e 9/9) |
| WU2 | Fase 2 backend, migración, catálogo 20, logo | **No iniciado** |
| WU3 | Fase 2 limpieza de contrato TS + specs/docs | **No iniciado** |

## Commit

- Mensaje: `feat(fronts): oculta la sección Terminales en taquilla y panel (WU1)`
- Hash: `24b166b`.

## Archivos modificados (WU1)

| Archivo | Acción | Cambio |
|---------|--------|--------|
| `taquilla/src/pages/dashboard.astro` | Modificado | `ordenTabs()` sin `terminales`; `labels` sin `terminales`; icono de tab `activeTab === 'animalitos' ? icono('dices') : icono('target')` |
| `taquilla/src/pages/resultados.astro` | Modificado | Eliminado el botón `data-tab="terminales"` |
| `panel/src/pages/resultados-loterias.astro` | Modificado | Eliminado el botón `data-tab="terminales"` |
| `taquilla/package.json` | Modificado | `version` 1.1.0 → 1.2.0 |
| `CHANGELOG.md` | Modificado | Nueva sección `[1.2.0] — 2026-11-05` (Keep a Changelog, español) antes de `[1.1.0]`; `[Unreleased]` y `[1.1.0]` intactos |
| `openspec/changes/terminal-como-modalidad/tasks.md` | Modificado | Tareas 1.1–1.4 marcadas `[x]` |

## Invariantes preservados (Fase 1)

- Catálogo sin cambios: `taquilla/src/data/juegos.json` sigue en 21 juegos y
  byte-idéntico a `docs/juegos.json`.
- `taquilla/src/utils/catalogo.ts` intacto: `TipoJuego`/`TIPOS_VALIDOS` siguen
  aceptando `terminales` y `derivarFamilia` conserva la rama.
- Ramas legacy de ticket en `dashboard.astro` intactas: `familia === 'terminal'`
  (2348) y `juegoType === 'terminales'` (2514), alcanzables por tickets viejos.
- Backend sin cambios (seeder, plugin, premios, scraper, migraciones).
- `ticket.ts`, `recibos.ts`, `historial.astro`, `check-pure.mjs`, logos/manifest
  sin tocar.

## Work Unit Evidence (WU1)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `cd taquilla && node scripts/check-pure.mjs` → **663 checks, 0 fallos** (usuario, 2026-11-05). |
| Runtime harness command/scenario and exact result | `cd taquilla && pnpm e2e` → **9/9 passed (54.7s)** tras migrar `lotto_e2e` (`2026_11_03_000001_drop_role_from_users_table` + `2026_11_04_000001_add_created_by_to_users_table`). Escenario: login, venta single/multi, premio, anulación, grilla, cierre, tipo de pago y smoke. |
| Build | `npm run build` ejecutado por el usuario antes de check-pure (prompt 7s); `dist` presente en el e2e. |
| Rollback boundary | `git revert` del commit WU1: restaura las 3 pestañas y la versión 1.1.0; no toca backend, catálogo ni contrato TS. Rollout de rollback: publicar `1.2.1` (electron-updater no baja versiones). |

## Verificación WU1 (completada)

```bash
cd taquilla && npm run build          # OK
cd taquilla && node scripts/check-pure.mjs   # 663 checks, 0 fallos
cd taquilla && pnpm e2e               # 9/9 passed
```

Attempt ledger: `settle` WU1 → `passed`; token `sha256:1a708d…`; commit `24b166b`.

## Desviaciones del diseño

Ninguna. Los cambios coinciden con `design.md` § «Fase 1 — fronts + release» y con
las tareas 1.1–1.4.

## Notas para WU2

- WU2 no está iniciado. Nada de migración, seeder, plugin, premios, scraper,
  catálogo 20, logo ni `check-pure.mjs`.
- **No rebase onto #89**: #89 sigue en su worktree sin mergear; mezclarlo en este
  tracker violaría el aislamiento de ciclos. La migración usa
  `2026_11_06_000001`, posterior a `2026_11_05_000001/000002` de #89, así que no
  hay colisión de timestamp ni de orden.
- WU2 se ejecuta en dos pasos: (a) el agente implementa migración + código +
  tests y deja el árbol listo; (b) el usuario corre `php artisan migrate` +
  `juegos:export`; (c) el agente copia el catálogo 20, elimina logo/manifest,
  ajusta `check-pure` y commitea WU2. El usuario ejecuta los tests backend.
