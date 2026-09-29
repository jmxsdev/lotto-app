# Proposal: Configuración de juegos — edición de premios con snapshot no retroactivo

**Estado**: draft · **Fase**: propose · **Fecha**: 2026-09-26
**Alcance**: backend (Laravel 13/PHP 8.3) + panel (Astro). `taquilla/` NO se toca (otro agente).

---

## Why

1. **El panel no puede editar premios.** Hoy solo lista el espejo legacy `config.premio_multiplo`; la fuente de verdad del dinero (`config.premios`) no tiene UI ni endpoint dedicado. Editarla exige tocar BD a mano.
2. **El toggle está roto en silencio.** `juegos.astro` manda `PATCH /juegos/{id}/toggle` sin body `{active}` y con `catch(err) {}`; el 422 del backend (`JuegoController::toggle` exige `active|boolean`) se traga. El usuario cree que configura y no pasa nada.
3. **`PUT /juegos/{id}` es un reemplazo total de `config`.** Mandar `{config:{premios}}` borra `scraper`, `modalidades_permitidas` y demás claves.
4. **La retroactividad es un riesgo de negocio.** El pago recalcula con el `config.premios` vigente al pagar (`PagoController::calcularPremio` → `PremiosEngine::calcular`). Sin snapshot, editar premios altera apuestas ya vendidas y aún no pagadas.

---

## What Changes

- **Endpoint dedicado** `PUT /api/v1/juegos/{juego}/premios` (roles `super_master|master`): el body ES el objeto `premios` completo (`base` + `modalidades` + `comodines`). Reemplazo **atómico** de `config.premios` con **merge de alto nivel** sobre `config` (preserva `scraper`, `modalidades_permitidas`, etc.), sincronización de espejos legacy reutilizando `PremiosOficiales::configPara()` (`premio_multiplo` = base, `config.modalidades` espejo, `config.comodines`), y auditoría `JuegoAuditoria` (`accion` = `premios`).
- **Validación de claves de modalidad** contra `plugin->obtenerModalidades()` **∪** catálogo oficial (`PremiosOficiales`) — no bloquear claves válidas del catálogo que el plugin no liste.
- **Snapshot de premios por apuesta (sin retroactividad).** Nueva columna JSON en `detalle_apuestas` que persiste `config.premios` al vender. `verificarGanadores` y `PagoController::calcularPremio` usan ese snapshot (fallback legacy a `config.premios` actual para apuestas vendidas antes de la feature). `PremiosEngine` recibe un **override de `config`** para liquidar contra el snapshot.
- **Fix P1 del toggle**: mandar `{active: !active}` y reemplazar el `catch` vacío por render de error visible.
- **UI editor P2** (`juegos.astro` o `juegos/[id].astro`): campo `base`, lista `modalidades` (clave/valor), lista `comodines` (tipo/valor/acumulativo), guardar → `PUT /juegos/{id}/premios`, y sección de auditoría (`auditoria[].user`).
- **`vendible`** se mantiene como espejo derivado de `active` (sin columna ni semántica nueva; el toggle sigue siendo la palanca).
- **Re-export** de `docs/juegos.json` + nota de coordinación de la copia bundled de la taquilla (`taquilla/src/data/juegos.json`).
- **Tests de deuda** de `JuegoController::update()` y `toggle()` (con auditoría) pendientes en `docs/PENDIENTE.md`.

---

## Capabilities

> Contrato con sdd-spec: qué specs se crean o modifican.

### New Capabilities

- `configuracion-premios`: endpoint dedicado de edición de premios (validación + merge seguro + espejos + auditoría), fix del toggle (P1), snapshot de premios por apuesta y editor de premios del panel (P2).

### Modified Capabilities

- `motor-premios`: la liquidación (`verificarGanadores`) y el pago (`PagoController`) pasan a leer el **snapshot de `config.premios`** vigente al vender (con fallback legacy), en lugar del `config.premios` actual — cambia el comportamiento de "Premios config-driven por juego" y "Pago validado contra el motor".

---

## Approach

Reutilizar la lógica de sincronización de espejos ya existente en `PremiosOficiales::configPara()` (extraerla a un servicio si el controlador lo necesita sin duplicar `ESPEJO_MODALIDADES`). El endpoint valida, hace `array_merge(config_existente, ['premios' => nuevo])`, sincroniza espejos, audita y responde.

Para el snapshot: al vender (`ApuestaService::createApuesta`), guardar `config.premios` en la nueva columna `detalle_apuestas.premios_snapshot`; `PremiosEngine` acepta un premios opcional (override de config) usado por `verificarGanadores` y `PagoController` cuando el detalle trae snapshot. Estrategia de entrega: **feature-branch-chain** con slices bajo el presupuesto de revisión de 400 líneas.

---

## Impact

| Área | Impacto | Descripción |
|------|---------|-------------|
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modificado | Nuevo método `updatePremios()` + validación + auditoría `accion=premios` |
| `backend/routes/api.php` | Modificado | Ruta `PUT /juegos/{juego}/premios` en grupo `super_master\|master` |
| `backend/app/Services/PremiosEngine.php` | Modificado | Override de `config` para liquidar contra snapshot |
| `backend/app/Services/ApuestaService.php` | Modificado | Persistir `premios_snapshot` al vender |
| `backend/app/Services/JuegoPluginManager.php` | Modificado | Pasar snapshot a `PremiosEngine` |
| `backend/app/Http/Controllers/Api/PagoController.php` | Modificado | `calcularPremio` usa snapshot (fallback legacy) |
| `backend/database/migrations/` | Nuevo | Columna `premios_snapshot` (JSON nullable) en `detalle_apuestas` |
| `backend/tests/` | Nuevo/Modificado | Feature del endpoint + snapshot + deuda `update()`/`toggle()` |
| `panel/src/pages/juegos.astro` (o `juegos/[id].astro`) | Modificado | Fix toggle P1 + editor P2 + auditoría |
| `docs/juegos.json` + nota coordinación taquilla | Modificado | Re-export tras editar premios |

---

## Out of Scope

- **Comisiones** (`docs/PENDIENTE.md` §E) — ciclo aparte.
- **Gaps de límites** (`docs/PENDIENTE.md` §D).
- **Ventana de vencimiento** (P3 de `integracion-front-motor-premios.md`) — otro ciclo.
- **Cambios en `taquilla/`** — otro agente (solo nota de coordinación).
- `GET /juegos/{id}/auditoria` dedicado (PENDIENTE §2) — WU natural posterior, no bloquea.

---

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|--------------|------------|
| Retroactividad (editar premios altera apuestas vendidas sin pagar) | Alta | Snapshot de premios al vender + fallback legacy; auditoría |
| Inconsistencia de espejos (premio_multiplo/modalidades/comodines viejos) | Media | Sincronización reutilizando `PremiosOficiales::configPara()` |
| `JuegosJsonTest` falla si no se re-exporta; taquilla con copia bundled vieja | Media | Re-export + actualización de copia bundled en el mismo WU |
| Claves de modalidad inválidas → premio 0 silencioso | Media | Validación contra plugin ∪ catálogo oficial |
| Comodines malformados que el motor ignora | Media | Validación de `tipo`/`premio_multiplo`/`acumulativo` |
| `la-ricachona` sin `base` editable | Baja | Rechazo 422 explícito (sin fuente oficial) |
| Concurrencia (editar premios durante ventas/pagos) | Baja | Último-write-wins (sin lock) + auditoría como trazabilidad |
| Permisos incorrectos | Baja | Guard `super_master\|master` (mismo grupo de rutas) |

---

## Rollback Plan

- El endpoint y la columna `premios_snapshot` son aditivos: revertir la migración (`php artisan migrate:rollback`) y quitar la ruta deja el sistema en el estado previo sin pérdida de datos.
- El snapshot es opcional (nullable): apuestas vendidas antes siguen liquidando con `config.premios` actual (fallback legacy), por lo que revertir no rompe pagos.
- Re-export de `docs/juegos.json` es regenerable (`php artisan juegos:export`); la copia bundled de la taquilla se restaura re-copiando el archivo anterior.
- Auditoría `JuegoAuditoria` registra `before/after` de cada edición de premios → permite reconstruir el estado previo de `config.premios`.

---

## Dependencies

- `PremiosOficiales::configPara()` (existente) como fuente de sincronización de espejos.
- Coordinación con el agente de `taquilla/` para actualizar la copia bundled de `docs/juegos.json` (sin tocar su código).

---

## Success Criteria

- [ ] `PUT /juegos/{juego}/premios` edita premios con merge seguro (preserva `scraper`/`modalidades_permitidas`), sincroniza espejos y audita (`accion=premios`).
- [ ] Editar premios NO altera apuestas vendidas antes (liquidación y pago usan snapshot; fallback legacy en verde).
- [ ] Toggle del panel funciona (badge cambia y persiste; error visible en fallo).
- [ ] Editor P2 edita `base`/`modalidades`/`comodines` y muestra errores de validación.
- [ ] `JuegosJsonTest` y suite completa (`DB_DATABASE=lotto_test_motor php artisan test`) en verde tras re-export.
- [ ] Tests de deuda de `update()`/`toggle()` cubiertos.

---

## Review Workload Forecast

- `Chained PRs recommended: Yes`
- `400-line budget risk: High`
- `800-line budget risk: High/Exceeded (≈1.000-1.300 total)`
- `Decision needed before apply: No (decisiones tomadas)`

Slices preliminares (feature-branch-chain, cada uno autónomo y < 400 líneas de revisión):

| Slice | Contenido | Estimación (líneas) | < 400 |
|-------|-----------|---------------------|-------|
| **S1** | Endpoint `PUT /juegos/{juego}/premios` (validación + merge + espejos + auditoría + ruta) + tests Feature (200/422/403, espejos, auditoría, reflejo en `reglas`/export) | ~350-400 | Sí (cortar tests de integración a S1b si supera) |
| **S2** | Snapshot foundation: columna `premios_snapshot` + persistencia en `ApuestaService` + override en `PremiosEngine` + uso en `verificarGanadores` y `PagoController` + fallback legacy + tests | ~250-350 | Sí |
| **S3** | Fix P1 toggle + tests deuda `update()`/`toggle()` + re-export `docs/juegos.json` + nota coordinación taquilla | ~120-180 | Sí |
| **S4** | UI editor P2 (form base/modalidades/comodines + guardar + auditoría) | ~150-250 | Sí |

Cada slice queda por debajo de las 400 líneas de revisión (S1 puede partirse en S1a endpoint+validación y S1b tests de integración si el corte queda limpio).
