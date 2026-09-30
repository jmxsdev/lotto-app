# Tasks: `limites-gaps` — cierre de gaps de configuración de límites

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | ~300–400 (WU1 ~130, WU2 ~50, WU3 ~90, WU4 ~70) |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (WU1) → PR 2 (WU2) → PR 3 (WU3) → PR 4 (WU4) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Retirar `fraccion`/`limite_tiempo` de la API | PR 1 | `DB_DATABASE=lotto_test_limites php artisan test --filter='Limites\|CrearEntidad\|JuegoLimiteService'` | N/A — solo API | Revertir commit WU1 |
| 2 | Ignorar `agencia_id` + borrar `authorizeLimitesWrite` | PR 2 | `DB_DATABASE=lotto_test_limites php artisan test --filter=LimitesApiTest` | N/A — solo API | Revertir commit WU2 |
| 3 | Acción "Limpiar" por fila (panel) | PR 3 | `pnpm run build` (workdir `panel`) | QA manual: Limpiar→DELETE→celda "hereda de …" | Revertir commit WU3 |
| 4 | Roles/nav/componente muerto (panel) | PR 4 | `DB_DATABASE=lotto_test_limites php artisan test --filter=RoleAuthorization` + `pnpm run build` | QA manual: gates por rol | Revertir commit WU4 |

**Naming (fix validador)**: gate canónico de escritura = `canEditLimites = ['super_master','master','banca'].includes(role)` (detalle pages). `puedeConfigurar` (`limites.astro:94`) pierde `grupo`. Opción de tabla `puedeEditar` (default `true`) = mismo gate; `false` ⇒ inputs `disabled` y sin columna Acciones.
**DB**: este ciclo usa **`lotto_test_limites`** (BD privada del ciclo; decisión 2026-09-29 por contención con la sesión `comisiones` sobre `lotto_test_motor`; `phpunit.xml` default `lotto_test`). Comando: `DB_DATABASE=lotto_test_limites php artisan test`.
**Gate externo**: `apply` NO arranca hasta aviso del orquestador.

## WU1 — Retiro de `fraccion`/`limite_tiempo` (backend)

- [x] 1.1 RED — `LimitesScopedApiTest:127` → `assertArrayNotHasKey('fraccion'/'limite_tiempo')`; `:135` sembrar dormidos en padre y assert `origen.valor` sin claves.
- [x] 1.2 RED — nuevos: `LimitesApiTest::test_put_ignora_campos_dormidos`; `LimitesScopedApiTest::test_batch_ignora_campos_dormidos`; `LimitesApiTest::test_get_limites_legacy_no_expone_dormidos` (A2); `JuegoLimiteServiceTest` (`CAMPOS` sin dormidos, null→delete, ítem sin campos→422, batch solo dormidos→422).
- [x] 1.3 GREEN — `JuegoController` :393-394, :421-424, :458-459, :1020-1021, :1032, :1038-1040; `JuegoLimiteService` :27, :45-46; `JuegoLimite` `protected $hidden`.
- [x] 1.4 GREEN — `CrearEntidadConLimitesTest` :77, :95-96 sin `fraccion`.
- [x] 1.5 VERIFY — `DB_DATABASE=lotto_test_limites php artisan test --filter='Limites|CrearEntidad|JuegoLimiteService'` + `vendor/bin/pint --test`. ✅ 60/60 passed, 493 assertions; pint sin violaciones (BD privada `lotto_test_limites`, contención resuelta).

## WU2 — `agencia_id` ignorado + código muerto (backend)

- [x] 2.1 RED — nuevos `LimitesApiTest::test_get_limites_ignora_agencia_id` (agencia REAL: hoy 500 SQL) y `test_get_limites_agencia_id_inexistente_es_ignorado` (999999: hoy 422 exists).
- [x] 2.2 GREEN — `JuegoController` quitar rule `:178`, comentario `:214`, filtro `:224-226`; borrar `authorizeLimitesWrite` `:714-722`.
- [x] 2.3 VERIFY — `DB_DATABASE=lotto_test_limites php artisan test --filter=LimitesApiTest` → 16/16 (42 assertions); `rg authorizeLimitesWrite` → 0; `test_delete_elimina_el_limite` intacto.

## WU3 — Acción "Limpiar" por fila (panel)

- [x] 3.1 `utils/limites.ts` — `CAMPOS` a 4 columnas (sin `fraccion`/`limite_tiempo`); `valorInicial` sin rama `fraccion`; handler inputs sin checkbox.
- [x] 3.2 `utils/limites.ts` — opciones `puedeEditar?`/`eliminar?`; inputs `disabled` si `!puedeEditar`; columna "Acciones" solo `modo==='entidad' && puedeEditar && eliminar`; botón solo si `valor?.id`.
- [x] 3.3 `utils/limites.ts` — `pintar()` enlaza `.limpiar-btn` → `showModal(confirm)` → `eliminar(id)` → 200: `tocadas.delete(clave)` + recarga; error: `showModal(error)` (404 recarga). Import `showModal`.
- [x] 3.4 Detalle (bancas/grupos/taquillas) — pasar `puedeEditar: canEditLimites` y `eliminar: isCreate ? undefined : (id) => apiFetch('DELETE','/limites/'+id)`.
- [x] 3.5 VERIFY — `pnpm run build` (26 páginas, 2.43s) ✅ + QA manual PENDIENTE (sin runner de panel): fila propia→Limpiar, heredada→sin botón, create→sin botón, 403/404→modal.

## WU4 — Roles/nav/componente muerto (panel)

- [x] 4.1 RED — checklist QA en rojo (grupo/agencia ven Guardar; nav solo super_master). Pin: `test_agencia_no_configura_limites` (`RoleAuthorizationTest:214`, ya verde). NUEVO `test_grupo_no_configura_limites` (PUT grupo→403; pasa por middleware existente).
- [x] 4.2 GREEN — `limites.astro` :94-110: `puedeConfigurar` sin `grupo` (sm|master|banca) + `puedeVer` (sm|master|banca|grupo|agencia); grupo/agencia tabla en lectura (`puedeEditar: false`); `.acciones` oculta si `!puedeConfigurar`; taquilla "Sin acceso".
- [x] 4.3 GREEN — detalle pages: `canEditLimites` verificado en las 3 (bancas local NUEVO, grupos :200 = canEditGrupo sm|master|banca, taquillas :185) y `[data-panel="limites"] .panel-actions` oculto si `!canEditLimites`.
- [x] 4.4 GREEN — `AdminLayout.astro` :255-256 nav `/limites` a `['super_master','master','banca']` + comentario; borrado `LimitesTable.astro` (0 imports).
- [x] 4.5 VERIFY — `DB_DATABASE=lotto_test_limites php artisan test --filter=RoleAuthorization` → 8/8 (19 assertions) + `pnpm run build` → 26 páginas, 3.80s. QA manual PENDIENTE: grupo/agencia sin Guardar/Limpiar (tabla en lectura); nav `/limites` visible a sm/master/banca y oculta a grupo/agencia.
