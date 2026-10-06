# Pendientes de implementación en los fronts — fuente única

> **Fuente de verdad única** de los ajustes pendientes en `panel/` (admin web) y `taquilla/` (POS Astro+Electron).
> Consolida lo disperso en los demás `.md` de `docs/` (incluye el ex `docs/dev/pendientes-front.md`, fusionado aquí en §7), la memoria Engram del proyecto y el estado real de ramas/worktrees/openspec.
> Última actualización: 2026-10-02. Al cerrar un ítem: marcarlo aquí y no re-abrirlo en otros docs.

## Leyenda

| Campo | Valores |
|---|---|
| Front | `panel` · `taquilla` · `ambos` |
| Prioridad | `P0` roto/bloqueante · `P1` alto (contrato/entrega) · `P2` medio · `P3` mejora · `DEC` decisión de negocio |
| Estado | `abierto` · `en vuelo` · `bloqueado` (por backend o decisión) · `verificación` (implementado, falta probar) |

---

## 0. En vuelo ahora

> **2026-10-02**: VL-01 (iconos-consistentes, PR #23) y VL-02 (configuracion-juegos, PR #49) **ya están mergeados**; §0 queda como histórico.

| ID | Front | Qué | Estado / qué falta | Evidencia |
|---|---|---|---|---|
| VL-01 | ambos | **iconos-consistentes**: migración total a Lucide (chrome UI), subset local de Noto Emoji + Inter local, sin CDN, en taquilla y panel | `en vuelo` — rama `feat/iconos-consistentes` **21 commits adelante de `main`, sin mergear**. Falta: (a) verificación física en Windows 10/11 (`/auditoria-iconos`, sin tofu, fuente local); (b) merge a `main` (decisión: rama única + merge final); (c) `sdd-verify` + archive + sync de specs | worktree `iconos-consistentes` · `tasks.md` 4.9 · `check-icons.mjs` · obs #391/#381 |
| VL-02 | panel | **ciclo `configuracion-juegos`** (exploración hecha): endpoint `PUT /juegos/{juego}/premios` + fix toggle + UI editor de premios | `en vuelo` — solo `explore.md` (commit `ff25794`, worktree `configuracion-juegos`). **5 decisiones de negocio pendientes** (§7 del explore): semántica del body, retroactividad del pago, `vendible`, claves de modalidad válidas, permisos | `openspec/changes/configuracion-juegos/explore.md` · ver PN-01/PN-03 |

---

## 1. Taquilla

> **Cierre 2026-10-02**: TQ-01..06 resueltos en front 1.0.0 (PR #33); TQ-08/09 resueltos en el hotfix (PR #51); TQ-10 auto-update OTA resuelto (PR #56/#57, **1.0.3 publicado** + gate Windows aprobado); comprobante de pago implementado. Las filas se conservan por trazabilidad — ver §5/§6.

| ID | Prioridad | Qué falta | Detalle / contrato | Evidencia |
|---|---|---|---|---|
| TQ-01 | **P0** | **Pago de premios roto** | Hoy manda `{apuesta_id, amount_bs, amount_usd, tipo:'bs'}`; el backend exige `tipo:'egreso'` + `moneda` (`bs\|usd\|mixto`, obligatoria) y montos **opcionales** (el backend aplica el premio del motor). Debe aceptar `estado === 'ganadora'` y mostrar el resultado real del POST (hoy hay "éxito" falso) | `taquilla/src/pages/ganadores.astro:162`, `historial.astro:240` · `docs/dev/integracion-front-motor-premios.md` §1.3, §3.2-T3 |
| TQ-02 | **P0** | Estados y filtros del historial | `getStatusClass` solo conoce `pendiente/pagada/anulada/ganador/perdida`; faltan `ganadora` y `vencido` (badge + filtros). Un ticket con apuestas `ganadora` sin pagar **no está cerrado** | `historial.astro:98-107` · contrato §1.1 |
| TQ-03 | P1 | Display de **tickets sin ganadores** ("perdidos") | No existe estado "perdedor" de ticket: quedan `pendiente` para siempre. Derivar "resuelto sin ganadores" del payload de `GET /tickets` (`ganadoras_count`/`tiene_ganadores` + apuestas) o mini-WU backend (`resuelto`/`estado_display`) | `docs/dev/pendientes-front.md` §A · obs #376 |
| TQ-04 | P1 | Resultados con claves nuevas | La página solo interpreta `triple_a/b/c`, `signo`, `numero`, `nombre_animal`, `color_animal`. Faltan `figuras[]`, `comodin`/`comodin_nombre`, `patronus`, `arrimao`, `pegadito` | `resultados.astro:140-199` · contrato §1.5, §3.2-T5 |
| TQ-05 | P1 | Modalidades nuevas y multi-selección | No existe armado por modalidad para: `cruzado`, `triple_a_b` (Par A+B), `tripleta`, `arrimao`, `pegadito`, `punta`, `una`, `aproximacion`, `signo_terminal`, `signo_solo`, ni `selecciones[]` (single-draw). Opciones de modalidad deben salir del catálogo (`/reglas` expone `premios`) | `dashboard.astro` (0 claves nuevas) · contrato §2, §3.2-T2 |
| TQ-06 | P1 | **Catálogo bundled desactualizado** | `taquilla/src/data/juegos.json` tiene los 21 juegos pero **sin `premios` (0 vs 21), sin `icono` y sin `active`/`vendible`**. Falta: copiar el contrato actual (`docs/juegos.json`), filtrar vendibles (`la-ricachona` hoy visible) y reempaquetar NSIS. La rama `iconos-consistentes` añade el consumo de `icono` | `taquilla/src/data/juegos.json` · `docs/dev/runbook-ops.md` pipeline catálogo · contrato §3.2-T1, §1.4 |
| TQ-07 | — | ~~Ticket impreso: premio posible / estado / glosa~~ **DESCARTADO (2026-09-30)** | Decisión del cliente: el ticket impreso **NO** debe reflejar el premio posible. No se implementa. | — |
| TQ-08 | — | ~~Anulación sin asumir 5 min~~ **RESUELTA (2026-10-02)** | `tiempo_eliminacion_efectivo` expuesto por la API (TDD) y usado por `canDeleteTicket`; sin hardcode; mensaje real del backend. PR #51 (`9dfc334`, `6316def`). | verify PASS · archivado |
| TQ-09 | — | ~~Teclado/accesibilidad~~ **RESUELTA (2026-10-02)** | (a) navegación de tripletas con flechas corregida (`f325bb1`); (b) foco del input Número corregido (`6f23eda`); (c) remap F1–F12/Alt+H verificado en Windows (gate aprobado 2026-10-02); (d) deferrals menores siguen como mejoras (confirm-and-discard F5/F6/F8, keymap configurable). PR #51. | verify PASS · archivado |
| TQ-10 | — | ~~OTA `electron-updater`~~ **RESUELTA (2026-10-02)** | Auto-update OTA implementado: feed público `latest.yml` + Range/206, chequeo al abrir + cada 1 h, aviso **obligatorio** con busy-gate. **1.0.3 publicado** y gate Windows aprobado. PR #56/#57 · obs #549/#550. | archivado 2026-10-02 |
| TQ-11 | — | Multientorno: fix C browser mode **DIFERIDO hasta nuevo aviso (2026-09-30)** | Solo afecta la taquilla en NAVEGADOR (`pnpm run dev`, uso de desarrollo); la taquilla empaquetada siempre usa producción. La verificación Windows del empaquetado es parte del gate del release (1.0.0 ya publicado). | obs #221/#210 |

> **Decisiones del cliente — 2026-09-30 (taquilla):**
> - **Venta offline**: NO se soportará (pedido del cliente). El banner de desconexión se mantiene solo como aviso; no habrá cola de ventas offline.
> - **TQ-07**: descartado — el ticket impreso no debe mostrar el premio posible.
> - **TQ-11**: diferido hasta nuevo aviso — solo aplica al modo navegador (dev), no a la taquilla empaquetada.
> - **Comprobante impreso de pago de premio**: ✅ **RESUELTO (2026-10-02)** — se imprime tras el pago (todas las jugadas con estado ganada/perdida/pendiente y montos reales) y la reimpresión de un ticket pagado sale con los montos registrados. PR #51 (`1866351`, `a50d061`) · gate aprobado.

---

## 2. Panel

| ID | Prioridad | Qué falta | Detalle / contrato | Evidencia |
|---|---|---|---|---|
| PN-01 | **P0** | **Toggle de juego roto** | `PATCH /juegos/{id}/toggle` va **sin body** y con `catch(err) {}` que traga el 422 (el backend exige `{active: bool}`). Mandar `{active: !active}` y mostrar el error real | `panel/src/pages/juegos.astro:28-31` · explore §2.7 |
| PN-02 | P1 | Página de configuración — vencimiento de apuestas | Consumir `GET/PUT /api/v1/configuraciones/apuestas-vencimiento` (`{horas}` 1..8760, default 24; solo `super_master\|master`). No existe página ni referencias en el panel | contrato §4.2-P3 · explore §2.9 |
| PN-03 | P1 | Editor de premios por juego | **Bloqueado por backend**: usar el nuevo `PUT /juegos/{juego}/premios` (merge seguro + espejos + auditoría) del ciclo `configuracion-juegos`; NO usar `PUT /juegos/{id}` con `config` parcial (borra `scraper`). UI: `base`, modalidades, comodines, guardar, auditoría | explore §4/§6 · contrato §4.2-P2 · ver VL-02 |
| PN-04 | P2 | Rótulo "Ganadores Hoy" | El stat muestra `pagada_count` (pagadas), no ganadoras. Corregir semántica o rótulo | `panel/src/pages/dashboard.astro:14,52-53` · contrato §4.2-P4 |
| PN-05 | P2 | Topbar estático | El topbar siempre dice "Dashboard" (`#topbar-title` sin JS que lo actualice) | `panel/src/layouts/AdminLayout.astro:100` · obs #12/#57 |
| PN-06 | P2 | Iconos duplicados del sidebar (📈/📊) | Verificar si queda resuelto por la rama `iconos-consistentes` (migra todo el chrome del panel a Lucide); si persiste, unificar | obs #12 · VL-01 |
| PN-07 | P2 | Gaps de límites | Sin DELETE para limpiar/volver a heredar; la UI habilita `grupo` pero la API responde 403; `agencia_id` en `GET /limites/{juego}` no existe en `juego_limites`; `LimitesTable.astro` muerto (existe, nadie lo importa) | `panel/src/utils/limites.ts` · `components/LimitesTable.astro` · contrato §4.2-P5 · obs #376 |
| PN-09 | P3 | Cierre del ciclo activación-taquilla | Falta verificación manual en `panel.gzuz.dev`, dry-run/apply de `taquillas:sanear-activas` en VPS y archivar el ciclo (PR #7 ya mergeado) | obs #217 |

---

## 3. Transversal — decisiones de negocio que tocan ambos fronts

| ID | Tema | Decisión pendiente | Evidencia |
|---|---|---|---|
| DEC-01 | Terminal Trío | Reglamento 60× vs FAQ 70× (+5× aproximación). Hoy se muestra 60× | `docs/cliente/inconsistencias.md` H12 · `docs/cliente/multiplicadores-juegos.md:15` |
| DEC-02 | Triple Chance | Reglamento 150×/6.000× vs afiche 100×/5.000× (C+Signo). Hoy se muestra el reglamento | `docs/cliente/inconsistencias.md` H23 · `docs/cliente/multiplicadores-juegos.md:22,40` |
| DEC-03 | Dupleta 1.000× y El Patronus | Modalidades fuera del modelo (Dupleta no existe y es rechazada; Patronus sin premio oficial confirmado). Si se soportan: UI de apuesta nueva + motor | `docs/cliente/comparacion-juegos.md:188-189,207` · `docs/cliente/premiacion-juegos.md:63-64` |
| DEC-04 | La Ricachona | `vendible=false`, sin fuente oficial de premios. Los fronts deben ocultarla/deshabilitarla (hoy la taquilla la lista: TQ-06) | `docs/juegos.json` (la-ricachona `active:false`, `vendible:false`) · `docs/cliente/premiacion-juegos.md` |
| DEC-05 | Horarios operativos | Confirmar con el operador: domingos de Táchira/Zamorano, Trío Activo 3 vs 12 sorteos, Caliente 5 vs 3. Se muestran los valores de operación real | `docs/cliente/inconsistencias.md` H9/H15/H18/H19 |
| DEC-06 | Juegos nuevos (terminales faltantes, Granjita Plus, etc.) | Integrarlos o no; cada integración actualiza `docs/juegos.json` → copia bundled + logo + reempaquetado | `docs/cliente/plataformas-juegos.md:174-186` · `docs/cliente/comparacion-juegos.md` |
| DEC-07 | PIN de cierre de caja | Formato 4–8 dígitos (default 6) sin confirmar; afecta 2 UIs (panel y taquilla) | obs #330 (verify cierre-caja-ajustes) |
| DEC-08 | Iconos de comodines | Confirmar ~8–10 emojis aproximados y comodines (`comodin-a`→🦁, `comodin-b`→🐾) | obs #377 |

---

## 4. Verificación pendiente (implementado, falta probar)

| ID | Front | Qué verificar | Evidencia |
|---|---|---|---|
| V-01 | ambos | Render real en Windows 10/11 de `iconos-consistentes` (sin tofu, Inter local, 0 CDN) vía `/auditoria-iconos` | VL-01 · obs #378/#391 |
| V-02 | taquilla | Remap F1–F12/Alt+H/F2→Números en Windows (PR #19 ya mergeado) | obs #375 |
| V-03 | ambos | Cierre de caja: impresión de reporte, modal de clave y página del panel son **manual-only** (sin tests automáticos) | obs #330/#331 |
| V-04 | taquilla | Multientorno: empaquetado siempre prod, fingerprint/MAC real, banner de update notify-only + publicación de instalador | obs #221 |
| V-05 | panel | Activación de taquilla: verificación manual del scroll/UX en `panel.gzuz.dev` | PN-09 |

---

## 5. Ya resuelto — NO re-implementar

- **Backend de pago autoritativo** (`c35a7df`): montos opcionales y aplicación del premio del motor. El pendiente es **solo front** (TQ-01).
- **Catálogo local bundled (REQ-CL-01)**: `dashboard.astro` ya reemplazó `GAMES_CONFIG` y carga `juegos.json` bundled. Lo que queda es **refrescar la copia** (TQ-06), no reescribir el loader.
- **taquilla-venta-agil** (PR #14), **atajos/anulación F10 + ticket multi-juego** (PR #19), **multientorno core** (PR #10), **cierre-caja base+ajustes** (PR #12), **activación de taquilla backend** (PR #7), **resultados-loterías panel**, **resultados-parciales (código)**, **descargar/releases**, **compatibilidad-dashboard-legacy (páginas)**: mergeados en `main`.
- `docs/dev/planificacion.md` sprints 9–12 y `docs/dev/plugins.md` "Scraper Triple Zulia": **stale**, no perseguir.
- `docs/cliente/multiplicadores-juegos.md` "por aplicar" (Monje 50×, Arrejuntado 40×, Chaima 40×): ya aplicado por el motor.
- **Comisiones** (PN-08/BE-06, PR #50, mergeado 2026-10-01): banca, grupo y taquilla cobran su % con **suma cero** (tope acumulado por moneda: `min(tasaEfectiva, max(0, 100 − Σ tasas propias de ancestros))`); configuración en la matriz de límites (master→bancas, banca→grupos, grupos→sus taquillas; rol `grupo` habilitado con scope); página `/comisiones`, columnas en reportes/cuadre y `comision_bs_equivalent` en cierre; default global 2 filas bs/usd (`super_master`).
- **Topes de comisión por tipo** (mergeado 2026-10-05): `topePorTipo` = animalitos 16%, tripletas 25%, otro 100%; tercer término del clamp en `tasaLiquidable`/`tasasLiquidablesBulk` (single==bulk, `tasaEfectiva` sin tocar), validación 422 al configurar por encima del tope (límites por juego/batch, stores de entidad y default global ≤16) y `type` expuesto en los payloads de juegos que consume el panel.

---

## 6. Notas de proceso

- **Worktrees leftover** (contenido ya en `main`, se pueden limpiar cuando convenga): `taquilla-venta-agil`, `investigacion-produccion`, `resultados-parciales-produccion`, `cierre-caja-taquilla`, `resultados-loterias-panel`, `distribucion-taquilla`, `taquilla-multientorno`, `explorar-activacion-taquilla`. Solo `iconos-consistentes` (VL-01) y `configuracion-juegos` (VL-02) tienen trabajo vivo.
- **Cierre 2026-10-02**: pendientes de taquilla **CERRADOS** — TQ-01..06 (front 1.0.0, PR #33), TQ-08/09 (PR #51), TQ-10 auto-update OTA (PR #56/#57, **1.0.3 publicado**, gate Windows aprobado), comprobante de pago implementado. Historial: TQ-07 descartado, TQ-11 diferido, offline descartado por el cliente.
- **Fuentes minadas**: `docs/*.md` (PENDIENTE, pendientes-taquilla, integracion-front-motor-premios, inconsistencias, motor-premios, multiplicadores-juegos, planificacion, runbook-ops, plataformas-juegos, comparacion-juegos, premiacion-juegos, manual-mantenimiento, entre otros) · memoria Engram obs #376/#366/#365/#391/#381/#378/#377/#375/#331/#330/#313/#221/#217/#12/#57 · worktrees/ramas y `openspec/changes/`.

## 7. Pendientes backend / negocio (consolidado del ex `docs/dev/pendientes-front.md`)

> Fusión de `docs/dev/pendientes-front.md` (2026-09-30). Los ítems ya resueltos se movieron a §5.

| ID | Área | Qué falta | Estado |
|---|---|---|---|
| BE-01 | Juegos | **Costo mínimo por juego**: columna `costo_minimo` en `juegos`, validación en `ApuestaStoreRequest` y edición desde el panel | `abierto` |
| BE-02 | Juegos | **Endpoint de auditoría** `GET /api/v1/juegos/{juego}/auditoria` (la tabla `juego_auditoria` ya se escribe; falta el endpoint de consulta) | `abierto` |
| BE-03 | Scrapers | **Scraper por juego** para los `requires_scraper = true`: generalizar el patrón de `ScrapeExchangeRateJob` | `abierto` |
| BE-04 | Permisos | **Permisos finos (Spatie)**: reemplazar middleware `role:` por `permission:` (`view_juegos`, `manage_juegos`, `view_apuestas`, `manage_apuestas`) | `abierto` |
| BE-05 | Tooling | **Bruno collection**: request para `PUT /api/v1/juegos/{juego}/premios` **ya existe** (`collections/Juegos/Actualizar Premios.yml`, ciclo `colecciones-api` / PRs #45–48); falta `GET /auditoria` cuando exista | `abierto` (parcial) |

**Ya resuelto (no re-implementar)**: plugin `TripleZulia` (`TripleZuliaSeeder` registrado en `DatabaseSeeder`); cierre de caja (`/api/v1/cierre*`); tests de `JuegoController::update()`/`toggle()` (`JuegoUpdateTest`, `JuegoToggleTest`).

---

> Mantenimiento: al completar un ítem, marcarlo aquí (o moverlo a §5) y actualizar la fecha. Este archivo es la única fuente; los demás docs quedan como referencia de contrato/detalle.
