# Apply Progress: terminal-como-modalidad

Rama: `feat/terminal-como-modalidad` (feature-branch chain; PR 1 base = tracker).
Modo de implementación: Standard (no hay runner de tests de front; `apply.tdd: true`
apunta a `composer test`, que es backend y lo ejecuta el usuario).

## Estado por work unit

| WU | Alcance | Estado |
|----|---------|--------|
| WU1 | Fase 1 fronts + release (backend intacto) | **Implementado, pendiente de verificación del usuario** |
| WU2 | Fase 2 backend, migración, catálogo 20, logo | **No iniciado** |
| WU3 | Fase 2 limpieza de contrato TS + specs/docs | **No iniciado** |

## Commit

- Mensaje: `feat(fronts): oculta la sección Terminales en taquilla y panel (WU1)`
- Hash: se reporta en el envelope de la fase (un commit no puede contener su propio
  hash). Identificable con `git log -1 --format='%h %s'` sobre esta rama.

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
| Focused test command and exact result | **Pendiente (usuario)**: `cd taquilla && node scripts/check-pure.mjs`. El agente no ejecuta tests por instrucción explícita del orquestador. |
| Runtime harness command/scenario and exact result | **Pendiente (usuario)**: `cd taquilla && npm run e2e` (requiere harness y BD `lotto_e2e`). Escenario: dashboard sin pestaña Terminales y navegación entre Animalitos/Tripletas con teclado. |
| Rollback boundary | `git revert` del commit WU1: restaura las 3 pestañas y la versión 1.1.0; no toca backend, catálogo ni contrato TS. Rollout de rollback: publicar `1.2.1` (electron-updater no baja versiones). |

## Comandos de verificación pendientes (los ejecuta el usuario)

```bash
cd taquilla && npm run build
cd taquilla && node scripts/check-pure.mjs
cd taquilla && npm run e2e    # solo si el harness y la BD están disponibles
```

## Desviaciones del diseño

Ninguna. Los cambios coinciden con `design.md` § «Fase 1 — fronts + release» y con
las tareas 1.1–1.4.

## Notas para WU2

- WU2 no está iniciado. Nada de migración, seeder, plugin, premios, scraper,
  catálogo 20, logo ni `check-pure.mjs`.
- Antes de WU2: rebase del tracker (#89) y regeneración del catálogo después del
  rebase, según `tasks.md` § «Dependencias y orden».
