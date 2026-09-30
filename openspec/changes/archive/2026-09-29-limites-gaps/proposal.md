# Propuesta: `limites-gaps` — cierre de gaps de configuración de límites

> Fase sdd-propose · worktree `limites-gaps` (base `c375786` = origin/main) · idioma español neutro (regla `openspec/config.yaml`); términos RFC 2119 en inglés.

## Intención (Why)

Cuatro gaps validados, todos con causa raíz en la inconsistencia UI↔API o en campos dormidos:

1. **Sin acción de limpieza en el panel** (Gap 1): el backend YA expone `DELETE /api/v1/limites/{limite}` (`routes/api.php:209` → `destroyLimite` `JuegoController.php:689-708`, testeado) y `utils/limites.ts:9-12` documenta que "limpiar/heredar = DELETE por fila", pero la UI no expone ninguna acción. La herencia es por FILA: "volver a heredar" solo es posible borrando la fila.
2. **La UI miente sobre permisos** (Gap 2): `puedeConfigurar` (`limites.astro:94`) incluye `grupo` (→ 403 genérico al guardar); `canEditLimites` está definido pero jamás usado (`grupos/detalle.astro:200`, `taquillas/detalle.astro:185`) → Guardar visible a todos los roles. La API autoriza PUT/DELETE/batch solo a `super_master|master|banca`.
3. **`agencia_id` rompe el legacy** (Gap 3): `GET /limites/{juego}` valida (`JuegoController.php:178`) y filtra `where('agencia_id', ...)` (`:224-226`) sobre una tabla SIN esa columna → SQL error 500. Sin lectores legítimos (el panel usa `listarLimites`).
4. **Campos dormidos + código muerto** (Gap 4): `fraccion` y `limite_tiempo` con cero lectores de negocio; `fraccion` arrastra el bug del checkbox (no persiste `false`; 422 por ítem vacío `JuegoLimiteService.php:125-127`). Muertos: `LimitesTable.astro`, `authorizeLimitesWrite` (`:717-722`), nav `/limites` solo super_master.

## Alcance

### In Scope
- **D2** Retirar `fraccion` y `limite_tiempo` de UI y API (BD intacta, sin migración).
- **D3** Alinear roles: Guardar/Limpiar solo `super_master|master|banca`; `grupo`/`agencia` solo lectura; corregir gates muertos; nav `/limites` a `super_master|master|banca`.
- **D4** Eliminar `agencia_id` del legacy `GET /limites/{juego}` (sin deprecar el endpoint).
- **D5** Botón "Limpiar" por fila → `DELETE /api/v1/limites/{limite}`; la celda vuelve a heredar.
- Borrar código muerto (`LimitesTable.astro`, `authorizeLimitesWrite`).
- Tests backend: actualizar los que usan `fraccion`; añadir cobertura de `agencia_id` eliminado y serialización sin los 2 campos.

### Out of Scope (non-goals)
- `porcentaje_pago`/`participacion` (ciclo COMISIONES). · Drop de columnas / migración de BD. · Limpieza por celda/campo. · Agencia como nivel de límites. · Deprecar `limites()` completo. · Zona premios y `taquilla/*`. · Reformateos masivos.

## Decisiones (fijas — con tradeoffs y evidencia)

| Decisión | Contenido | Tradeoff / evidencia |
|---|---|---|
| **D1** campos | Este ciclo posee `fraccion` y `limite_tiempo`; `porcentaje_pago`/`participacion` son de COMISIONES (no tocar) | Cero lectores de negocio de `fraccion`/`limite_tiempo` (solo `limite_minimo/maximo` en `ApuestaService`); `porcentaje_pago`/`participacion` los consume el ciclo paralelo |
| **D2** dormidos | Retirar de UI+API; columnas quedan en BD | Elimina de raíz el bug del checkbox Fracción (no persiste `false`; 422 por ítem vacío); sin migración = bajo riesgo |
| **D3** roles | Alinear UI a API (solo lectura para grupo/agencia) | La API ya lo decide (`routes/api.php:207-215`); gates muertos `grupos/detalle.astro:200`, `taquillas/detalle.astro:185`; `puedeConfigurar` `limites.astro:94` incluye `grupo` indebidamente |
| **D4** agencia_id | Eliminar param de `limites()` (val. `:178`, filtro `:224-226`) | Endpoint sigue sin el param (un cliente que lo envíe simplemente no filtra); NO toca usos legítimos de taquillas (`:202`, `:586`, `:813`, `:988`) |
| **D5** limpiar | Botón por fila → DELETE existente | `id` disponible en `serializarLimite:1015`; la celda vuelve a heredar del ancestro; NO se cambia la semántica de celda vacía ni el batch null→borra-fila |

## Capacidades (contrato con sdd-spec)

### New Capabilities
- `limites`: configuración de límites de juego — roles de escritura/lectura (MUST: `super_master|master|banca` escriben; `grupo`/`agencia` solo lectura), limpieza/herencia por fila (DELETE), campos expuestos en API/UI sin `fraccion`/`limite_tiempo`, y nav `/limites` por rol.

### Modified Capabilities
- None. El cambio ALINEA con `jerarquia-agencias` (agencia passthrough) y `panel-jerarquia` sin alterar sus requisitos.

## Enfoque

Diffs quirúrgicos (sin reformatear código ajeno):
- **Backend** `JuegoController.php` + `JuegoLimiteService.php`: quitar `fraccion`/`limite_tiempo` de validadores (`:393-394`, `:458-459`, service `:45-46`), `CAMPOS` (`service:27`), `only([...])` (`:421-424`) y serialización (`:1020-1021`, `:1038-1039`); quitar `agencia_id` (`:178`, `:224-226`); borrar `authorizeLimitesWrite` (`:717-722`).
- **Panel**: `utils/limites.ts` (quitar 2 columnas de `CAMPOS:48-55`; añadir acción Limpiar por fila usando `id`), gates (`limites.astro:94`, `grupos/detalle.astro:200`, `taquillas/detalle.astro:185`, `bancas/detalle.astro`), nav (`AdminLayout.astro:256`), BORRAR `LimitesTable.astro`.
- **Tests**: `CrearEntidadConLimitesTest.php:77,95`, `LimitesScopedApiTest.php:127`; revisar `LimitesApiTest.php` (DELETE ya testeado); añadir cobertura `agencia_id` (no filtra / no 500) y serialización sin campos.

## Áreas afectadas

| Área | Impacto | Descripción |
|---|---|---|
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modificado | quitar `agencia_id`, `fraccion`, `limite_tiempo`; borrar `authorizeLimitesWrite` |
| `backend/app/Services/JuegoLimiteService.php` | Modificado | quitar `fraccion`/`limite_tiempo` de `CAMPOS` y validadores |
| `panel/src/utils/limites.ts` | Modificado | quitar 2 columnas; añadir acción Limpiar por fila |
| `panel/src/pages/limites.astro`, `bancas/detalle.astro`, `grupos/detalle.astro`, `taquillas/detalle.astro` | Modificado | gates de rol |
| `panel/src/layouts/AdminLayout.astro` | Modificado | nav `/limites` |
| `panel/src/components/LimitesTable.astro` | Eliminado | muerto (0 imports) |
| `backend/tests/...` | Modificado | actualizar y añadir cobertura |

## Riesgos

| Riesgo | Prob. | Mitigación |
|---|---|---|
| **Gate externo de apply**: no se codea/testea hasta aviso del orquestador (~50 min, cambio en ejecución de suite) | Alta | Documentado como gate externo (no blocker del cambio); el ciclo avanza explore→proposal→spec→design→tasks; `apply` espera aviso |
| **Sin tests de `JuegoLimiteService`** (8 callers, sin cobertura propia) | Media | Añadir cobertura al tocar serialización/semántica |
| **Doble semántica null**: PUT escribe NULL; batch null→borra fila | Media | NOTA sin cambio: D5 usa DELETE (no null), evitando la ambigüedad; no se unifica |
| **Regresión de serialización** al quitar campos | Baja | Sin lectores de negocio (validado); tests de serialización nuevos |

## Plan de rollback

Revertir el commit del worktree (cambios quirúrgicos por archivo, sin migración de BD). Los campos retirados de la API no alteran la BD; restaurar `only([...])`/`CAMPOS`/serialización y los gates/nav a su estado previo. Las columnas `fraccion`/`limite_tiempo` nunca se tocaron.

## Dependencias

- Gate externo: `apply` bloqueado hasta aviso del orquestador (cambio en ejecución de suite).
- Ninguna dependencia de librería/infra.

## Criterios de aceptación (alto nivel)

- [ ] MUST: `GET /limites/{juego}` no acepta `agencia_id` (ignorado sin 500).
- [ ] MUST: API y UI no exponen `fraccion` ni `limite_tiempo`.
- [ ] MUST: Guardar/Limpiar visible solo a `super_master|master|banca`; `grupo`/`agencia` solo lectura.
- [ ] MUST: nav `/limites` visible a `super_master|master|banca`.
- [ ] MUST: botón "Limpiar" por fila llama `DELETE /api/v1/limites/{limite}` y la celda vuelve a heredar.
- [ ] MUST: `authorizeLimitesWrite` y `LimitesTable.astro` eliminados.
- [ ] SHOULD: tests actualizados y en verde; cobertura nueva de `agencia_id` y serialización.
