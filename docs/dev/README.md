# docs/dev — Documentación de desarrollo y seguimiento

> Índice de los documentos **técnicos, de operación y de seguimiento** del proyecto.
> Para la documentación orientada a **decisiones de negocio / cliente**, ver [`../cliente/README.md`](../cliente/README.md).
> Artefactos oficiales descargados (PDFs de reglamentos, afiches, licencias): [`../reglamentos/`](../reglamentos/README.md).
> Export del catálogo que consume el código: [`../juegos.json`](../juegos.json).

## Cómo usar y actualizar estos documentos (para agentes e implementadores)

1. **Estado vigente = una sola fuente.** Todo pendiente de `panel/` + `taquilla/` (fronts) y de
   backend/negocio vive en **`pendientes-front.md`**. No re-abrir ítems en otros docs: si un doc de
   contrato/arquitectura menciona algo pendiente, debe **enlazar** a `pendientes-front.md`.
2. **Al cerrar un ítem**: marcarlo en `pendientes-front.md` y moverlo a su sección
   **§5 "Ya resuelto — NO re-implementar"**. Actualizar la fecha ("Última actualización").
3. **IDs de seguimiento**: `TQ-xx` (taquilla), `PN-xx` (panel), `VL-xx` (en vuelo), `V-xx`
   (verificación manual), `DEC-xx` (decisión de negocio, cruza a `docs/cliente/`), `BE-xx` (backend/negocio).
4. **Los docs "históricos/stale" NO se actualizan**: se conservan como contexto. Están marcados con
   el aviso ⚠️ al inicio. Si su contenido vigente importa, se promueve al doc canónico correspondiente.
5. **Comentarios de código**: al referenciar un doc, usar la ruta nueva (`docs/dev/...` o
   `docs/cliente/...`).

## Documentos

| Archivo | Rol | Propósito | ¿Actualizar? |
|---|---|---|---|
| [`pendientes-front.md`](pendientes-front.md) | **Canónico · fuente única** | Pendientes de ambos fronts, decisiones de negocio (§3), backend/negocio (§7, fusión del ex `docs/PENDIENTE.md`), verificación manual y "ya resuelto" | ✅ **Sí, siempre** |
| [`motor-premios.md`](motor-premios.md) | Canónico · técnico | Arquitectura, reglas de cálculo, endpoints y operación del motor de premios (`config.premios`) | ✅ Al cambiar el motor |
| [`integracion-front-motor-premios.md`](integracion-front-motor-premios.md) | Canónico · contrato | Contrato backend↔fronts (payloads, estados, monedas, catálogo) + plan de implementación | ✅ Al cambiar el contrato |
| [`manual-mantenimiento.md`](manual-mantenimiento.md) | Canónico · operativo | Mantenimiento: migraciones en prod, release de Taquilla, activación de dispositivos, CORS, secrets | ✅ Al cambiar el proceso |
| [`runbook-ops.md`](runbook-ops.md) | Canónico · operativo | Runbook de VPS: deploy, rollback, scheduler, suite en paralelo, checklist PC nueva | ✅ Al cambiar la operación |
| [`deploy.md`](deploy.md) | Canónico · guía | Despliegue completo en VPS desde cero (paso a paso) | ✅ Al cambiar el setup |
| [`estructura.md`](estructura.md) | Canónico · referencia | Mapa del monorepo y propósito de cada carpeta | ✅ Al cambiar la estructura |
| [`plugins.md`](plugins.md) | Canónico · how-to | Cómo crear plugins de juego y scrapers (§4 Triple Zulia: ya resuelto) | ✅ Al agregar un juego/scraper |
| [`pendientes-taquilla.md`](pendientes-taquilla.md) | Detalle | Detalle de accesibilidad/teclado de la taquilla (bajo TQ-09); el estado canónico es `pendientes-front.md` §1 | ⚠️ Solo detalle |
| [`planificacion.md`](planificacion.md) | ⚠️ **Histórico (stale)** | Planificación original por sprints y estimaciones | ❌ No |
| [`estrategia-scrapers-premios.md`](estrategia-scrapers-premios.md) | ⚠️ **Histórico** | Estrategia del ciclo de scrapers → motor de premios (ambos cerrados) | ❌ No |

## Estructura

```
docs/
├── dev/            ← este índice (técnico, operación, seguimiento)
├── cliente/        ← consulta para decisiones de negocio / cliente
├── reglamentos/    ← PDFs/afiches/licencias oficiales + README
└── juegos.json     ← export del catálogo (lo consume el código; espejo de PremiosOficiales)
```

> Nota de `.gitignore`: `*.md` está ignorado globalmente, con excepción de `docs/**/*.md`.
