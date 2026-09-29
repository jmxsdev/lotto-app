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
| 1 | Retirar `fraccion`/`limite_tiempo` de la API | PR 1 | `DB_DATABASE=lotto_test_motor php artisan test --filter='Limites\|CrearEntidad\|JuegoLimiteService'` | N/A — solo API | Revertir commit WU1 |
| 2 | Ignorar `agencia_id` + borrar `authorizeLimitesWrite` | PR 2 | `DB_DATABASE=lotto_test_motor php artisan test --filter=LimitesApiTest` | N/A — solo API | Revertir commit WU2 |
| 3 | Acción "Limpiar" por fila (panel) | PR 3 | `pnpm run build` (workdir `panel`) | QA manual: Limpiar→DELETE→celda "hereda de …" | Revertir commit WU3 |
| 4 | Roles/nav/componente muerto (panel) | PR 4 | `DB_DATABASE=lotto_test_motor php artisan test --filter=RoleAuthorization` + `pnpm run build` | QA manual: gates por rol | Revertir commit WU4 |

**Naming (fix validador)**: gate canónico de escritura = `canEditLimites = ['super_master','master','banca'].includes(role)` (detalle pages). `puedeConfigurar` (`limites.astro:94`) pierde `grupo`. Opción de tabla `puedeEditar` (default `true`) = mismo gate; `false` ⇒ inputs `disabled` y sin columna Acciones.
**DB**: `DB_DATABASE=lotto_test_motor` es la convención del usuario (phpunit.xml default `lotto_test`); apply confirma que la BD existe.
**Gate externo**: `apply` NO arranca hasta aviso del orquestador.

## WU1 — Retiro de `fraccion`/`limite_tiempo` (backend)

- [ ] 1.1 RED — `LimitesScopedApiTest:127` → `assertArrayNotHasKey('fraccion'/'limite_tiempo')`; `:135` sembrar dormidos en padre y assert `origen.valor` sin claves.
- [ ] 1.2 RED — nuevos: `LimitesApiTest::test_put_ignora_campos_dormidos`; `LimitesScopedApiTest::test_batch_ignora_campos_dormidos`; `LimitesApiTest::test_get_limites_legacy_no_expone_dormidos` (A2); `JuegoLimiteServiceTest` (`CAMPOS` sin dormidos, null→delete, ítem sin campos→422, batch solo dormidos→422).
- [ ] 1.3 GREEN — `JuegoController` :393-394, :421-424, :458-459, :1020-1021, :1032, :1038-1040; `JuegoLimiteService` :27, :45-46; `JuegoLimite` `protected $hidden`.
- [ ] 1.4 GREEN — `CrearEntidadConLimitesTest` :77, :95-96 sin `fraccion`.
- [ ] 1.5 VERIFY — `DB_DATABASE=lotto_test_motor php artisan test --filter='Limites|CrearEntidad|JuegoLimiteService'` + `vendor/bin/pint --test`.

## WU2 — `agencia_id` ignorado + código muerto (backend)

- [ ] 2.1 RED — nuevos `LimitesApiTest::test_get_limites_ignora_agencia_id` (agencia REAL: hoy 500 SQL) y `test_get_limites_agencia_id_inexistente_es_ignorado` (999999: hoy 422 exists).
- [ ] 2.2 GREEN — `JuegoController` quitar rule `:178`, comentario `:214`, filtro `:224-226`; borrar `authorizeLimitesWrite` `:714-722`.
- [ ] 2.3 VERIFY — `DB_DATABASE=lotto_test_motor php artisan test --filter=LimitesApiTest`; `rg authorizeLimitesWrite` → 0; `test_delete_elimina_el_limite` intacto.

## WU3 — Acción "Limpiar" por fila (panel)

- [ ] 3.1 `utils/limites.ts` — `CAMPOS` a 4 columnas (sin `fraccion`/`limite_tiempo`); `valorInicial` sin rama `fraccion`; handler inputs sin checkbox.
- [ ] 3.2 `utils/limites.ts` — opciones `puedeEditar?`/`eliminar?`; inputs `disabled` si `!puedeEditar`; columna "Acciones" solo `modo==='entidad' && puedeEditar && eliminar`; botón solo si `valor?.id`.
- [ ] 3.3 `utils/limites.ts` — `pintar()` enlaza `.limpiar-btn` → `showModal(confirm)` → `eliminar(id)` → 200: `tocadas.delete(clave)` + recarga; error: `showModal(error)` (404 recarga). Import `showModal`.
- [ ] 3.4 Detalle (bancas/grupos/taquillas) — pasar `puedeEditar: canEditLimites` y `eliminar: isCreate ? undefined : (id) => apiFetch('DELETE','/limites/'+id)`.
- [ ] 3.5 VERIFY — `pnpm run build` + QA: fila propia→Limpiar, heredada→sin botón, create→sin botón, 403/404→modal.

## WU4 — Roles/nav/componente muerto (panel)

- [ ] 4.1 RED — checklist QA en rojo (grupo/agencia ven Guardar; nav solo super_master). Pin: `test_agencia_no_configura_limites` (`RoleAuthorizationTest:214`, ya verde). SHOULD nuevo `test_grupo_no_configura_limites` (PUT grupo→403).
- [ ] 4.2 GREEN — `limites.astro` :94-110: `canEditLimites` (sin `grupo`) reemplaza `puedeConfigurar`; grupo/agencia tabla en lectura; `.acciones` oculta si `!canEditLimites`; taquilla "Sin acceso".
- [ ] 4.3 GREEN — detalle pages: fijar `canEditLimites` (grupos `:200` alias muerto; taquillas `:185` sin uso; bancas nuevo) y ocultar `[data-panel="limites"] .panel-actions` si `!canEditLimites`.
- [ ] 4.4 GREEN — `AdminLayout.astro` :255-256 nav `/limites` a 3 roles; borrar `LimitesTable.astro`.
- [ ] 4.5 VERIFY — `DB_DATABASE=lotto_test_motor php artisan test --filter=RoleAuthorization` + `pnpm run build` + QA gates.
