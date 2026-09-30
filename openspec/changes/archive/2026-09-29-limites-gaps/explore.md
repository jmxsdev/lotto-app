# Exploración — cambio `limites-gaps`

> Fase sdd-explore · worktree `limites-gaps` (base `c375786` = origin/main) · SOLO LECTURA.
> Fuente de verdad: código actual del worktree. `docs/PENDIENTE.md` §D (líneas 59-61) usado como índice a validar, no como verdad.
> Idioma: español neutro/profesional (regla de `openspec/config.yaml`); términos RFC 2119 en inglés.

## Resumen ejecutivo

Los 4 gaps de §D se validaron contra el código. Resultado: **2 confirmados como están descritos (Gap 2 y Gap 3), 1 confirmado a medias (Gap 1: el backend YA tiene DELETE y semántica "null → borrar fila"; lo que falta es la acción en el panel), y Gap 4 confirmado con 3 piezas de código muerto adicionales**.

Hallazgos transversales de alto valor:

1. **La herencia en venta es por FILA, no por columna.** `getEffectiveLimit()` (`backend/app/Services/ApuestaService.php:154-183`) devuelve UNA sola fila (la más específica de taquilla → grupo → banca). Una fila propia con columnas NULL **bloquea** la herencia del padre: NULL = "sin restricción", no "hereda". La única forma real de "volver a heredar" es **borrar la fila**. La UI muestra "origen" solo para celdas sin fila propia (`JuegoController.php:333-361`).
2. **No existe permiso `manage_limites`**: la autorización de límites es 100 % por rol (middleware Spatie `role:` + chequeos en el controlador). El 403 de `grupo` NO es silencioso: `bootstrap/app.php:66-69` devuelve `{"message":"No autorizado."}` y el panel lo muestra (alert/modal), pero con mensaje genérico y confuso.
3. **Bug latente del checkbox "Fracción"** (corregido por gatekeeper, ver §Nota de corrección): la regla real es `boolean`, NO required (omitir `fraccion` valida bien). El defecto real: desmarcar el checkbox elimina el campo del ítem (`panel/src/utils/limites.ts:200-207`) → (a) nunca se persiste `fraccion=false`; (b) si fue el único campo tocado de la celda, el ítem queda vacío → 422 (`JuegoLimiteService.php:125-127`). Sin tests que lo ejerciten.
4. **`limite_minimo`/`limite_maximo` son los únicos campos con lectores de negocio** (validación de venta en `ApuestaService`). `porcentaje_pago`, `participacion`, `fraccion`, `limite_tiempo` tienen cero lectores de negocio: solo escritura, validación, UI y serialización.

## Tabla por gap

| Gap | ¿Validado? | Evidencia (archivo:línea) | Nota |
|---|---|---|---|
| 1. Sin DELETE para limpiar/volver a heredar | **Parcial** | Backend SÍ tiene: `DELETE /api/v1/limites/{limite}` (`backend/routes/api.php:209`) → `destroyLimite` (`backend/app/Http/Controllers/Api/JuegoController.php:689-708`), testeado (`backend/tests/Feature/LimitesApiTest.php:256-318`). Semántica "null explícito → borrar fila" en `JuegoLimiteService.php:110-123`. Panel NO: cero llamadas DELETE a `/limites`; `panel/src/utils/limites.ts:9-12` documenta que "limpiar/heredar se hace por DELETE por fila" pero no implementa ninguna acción | El gap real es la **ausencia de acción de limpieza en la UI**, no la ausencia de endpoint |
| 2. UI habilita `grupo`; API 403 | **Sí** | UI: `panel/src/pages/limites.astro:94` (`puedeConfigurar` incluye `grupo`) y botón Guardar activo; además `canEditLimites` definido pero NUNCA usado en `panel/src/pages/grupos/detalle.astro:200` y `panel/src/pages/taquillas/detalle.astro:185` → pestaña Límites con Guardar visible para todos los roles en edición. API: PUT/DELETE `role:super_master\|master\|banca` (`routes/api.php:207-210`), POST batch idem (`:213-215`) | 403 no silencioso pero con mensaje genérico ("No autorizado.") |
| 3. GET /limites/{juego} acepta `agencia_id` sin columna | **Sí** | Filtro: `JuegoController.php:178` (validación) y `:224-226` (`where('agencia_id', ...)`). Columna NO existe: migración `backend/database/migrations/2026_08_11_000002_create_juego_limites_table.php:24-38`; fillable `JuegoLimite.php:13-25` | `where('agencia_id')` sobre tabla sin la columna → error SQL 500 si se usa. El endpoint que usa el panel (`listarLimites`) NO acepta `agencia_id` (`JuegoController.php:261-266`) |
| 4. Código muerto | **Sí** | `LimitesTable.astro` sin imports (grep `LimitesTable` → 0 resultados en todo el repo). Nav `/limites` solo super_master (`panel/src/layouts/AdminLayout.astro:256`); la página soporta master/banca/grupo (`limites.astro:94`) | +2 piezas muertas: `authorizeLimitesWrite` (`JuegoController.php:717-722`, cero callers) y el endpoint legacy `limites()` no lo usa el panel |

## Flujo actual de guardado y herencia de límites

### Guardado (3 vías)

```
Panel (utils/limites.ts — present-fields-only, solo campos tocados)
│
├─ [A] PUT /api/v1/limites/{juego}   (updateLimites: JuegoController.php:376-430)
│     banca_id requerido + grupo_id/taquilla_id opcionales + moneda + 6 campos
│     → updateOrCreate($clave, only([...]))  — null explícito ESCRIBE NULL (no borra fila)
│     → valida restrictividad hijo ≤ padre si grupo/taquilla (398-408)
│
├─ [B] POST /api/v1/limites/batch    (batchLimites: :436-529, en transacción)
│     modo legacy (ítems con entidades) o modo scope (alcance expandido, padre-primero)
│     → JuegoLimiteService::aplicarItemLimite (:89-143)
│     → null explícito en CUALQUIER campo → DELETE de la fila completa (:110-123)
│
└─ [C] Creación de entidad (BancaController:103-105, GrupoController:166-168,
      TaquillaController:198-200)
      → validarItems + persistirParaEntidad (misma semántica que [B])
```

`DELETE /api/v1/limites/{limite}` (`destroyLimite`, :689-708): borra la fila por id; autorización jerárquica (master solo sus bancas :697-699; banca solo la suya :701-703).

### Herencia (2 lectores)

**Lectura de negocio (venta)** — `ApuestaService`:

```
getEffectiveLimit(taquilla, juego, moneda)  →  JuegoLimite único (:154-183)
  cascada: fila taquilla  >  fila grupo  >  fila banca  >  null
  (orderByRaw 'taquilla_id IS NOT NULL DESC, grupo_id IS NOT NULL DESC')
      │
      ├─ validarCostoMinimo (:53-89)   → usa limite_minimo (moneda 'bs') como "costo mínimo"
      └─ validarMonedaYLimites (:235-282) → validarContraLimite (:287-308)
           limite_maximo: monto > max → rechazo (:293-297)
           limite_minimo: monto < min → rechazo (:300-303)
           fila ausente o campo NULL → sin restricción (:289-291)
```

**Lectura de display (panel)** — `listarLimites` (`JuegoController.php:253-370`):

```
matriz juego×moneda de una entidad (banca|grupo|taquilla)
  filas propias + filas de ancestros (filasDeEntidad :824-872)
  celda SIN fila propia → origen = ancestro más cercano (grupo > banca) (:333-361)
  celda CON fila propia → valor directo; NO hay origen ni herencia parcial por columna
```

### Semántica de niveles

- **`banca_id` es NOT NULL** (`migración:27`): toda fila cuelga de una banca. La jerarquía real es **banca → grupo → taquilla**. **NO existe nivel master ni super_master en `juego_limites`**: esos roles operan escribiendo filas a nivel banca. (Supuesto del prompt "taquilla→grupo→banca→master→super_master" es FALSO en código.)
- **`agencia` NO es nivel de límites**: la spec `openspec/specs/jerarquia-agencias/spec.md:13` la define como identidad ("SHALL NOT configurar monedas/vigencia/tiempo/límites en esta iteración (passthrough)"). El alcance agencia se resuelve vía sus taquillas (`JuegoController.php:194-203`, `:585-586`, `:812-814`, `:988`).
- **Fila ausente = heredar**: en venta, cascada al ancestro; en display, origen del ancestro. **Fila presente con NULL = sin restricción (bloquea herencia)**: la cascada elige UNA fila, no mezcla columnas.
- **Borrar una fila intermedia (grupo)**: las taquillas caen al siguiente ancestro (banca) tanto en venta (`getEffectiveLimit`) como en display (origen nivel banca). Sin pérdida de integridad. Es el mecanismo natural de "volver a heredar".

### Lo que haría falta para "volver a heredar" (Gap 1)

1. Evidencia de que el service interpreta la ausencia como herencia: `JuegoLimiteService.php:110-123` (null → delete fila), `ApuestaService.php:289-291` (fila ausente → sin restricción) y `JuegoController.php:333-361` (sin fila propia → origen del ancestro). **La ausencia de fila ES la herencia.**
2. El panel ya documenta la intención (`utils/limites.ts:9-12`: "limpiar/heredar se hace por DELETE por fila") pero no expone la acción. Falta: botón "Limpiar/heredar" por fila que llame `DELETE /api/v1/limites/{limite}` (se requiere el `id` de la fila, disponible en la serialización `serializarLimite`, `JuegoController.php:1015`).
3. **Matiz crítico a decidir**: en batch, enviar `null` en UN campo borra TODA la fila (no solo el campo). En PUT, `null` escribe NULL (no borra). Dos semánticas distintas para el mismo gesto. La UI debería elegir una y comunicarla.

## Matriz de roles UI vs API

### API (rutas, `backend/routes/api.php`)

| Rol | GET /limites y /limites/{juego} (:198-203) | PUT/DELETE (:207-210) | POST batch (:213-215) |
|---|---|---|---|
| super_master | ✅ (todo) | ✅ | ✅ |
| master | ✅ (solo sus bancas, `JuegoController.php:188-191`, `:961-973`) | ✅ (solo sus bancas, `:697-699`, `:733-738`) | ✅ |
| banca | ✅ (su cadena, `:192-193`) | ✅ (solo su banca, `:701-703`, `:741-742`) | ✅ |
| grupo | ✅ (su grupo + su banca + sus taquillas, `:204-211`) | ❌ 403 | ❌ 403 |
| agencia | ✅ (su banca/grupo/taquillas, `:194-203`) | ❌ 403 | ❌ 403 |
| taquilla | ❌ 403 (`:171-173`) | ❌ | ❌ |

Sin permiso Spatie específico: `RolesAndPermissionsSeeder.php` no define ningún permiso de límites; el rol `grupo` solo gestiona sus taquillas (`:89-96`). La autorización es por `role:` middleware + `in_array($user->role, ...)` en el controlador.

### Panel

| Superficie | Roles con Guardar visible | Roles que el backend acepta | ¿Mismatch? |
|---|---|---|---|
| `pages/limites.astro` (:94) | super_master, master, banca, **grupo** | super_master, master, banca | ✅ grupo ve botón → 403 al guardar |
| `bancas/detalle.astro` (pestaña Límites) | sin gate (canEditBanca solo aplica a formulario de información, `:190`, `:293`; el botón de límites no se deshabilita) | super_master, master, banca | ⚠️ sin gate explícito (depende de la API) |
| `grupos/detalle.astro` | **todos los roles** (`canEditLimites` definido en :200, jamás usado) | super_master, master, banca | ✅ grupo (y cualquiera) ve Guardar → 403 |
| `taquillas/detalle.astro` | **todos los roles** (`canEditLimites` definido en :185, jamás usado) | super_master, master, banca | ✅ grupo/agencia ven Guardar → 403 |

Nota: en create mode los límites se guardan junto con la entidad (sin botón propio; `applyCreateNotices` en los tres detalle) — coherente porque los stores de Banca/Grupo/Taquilla validan el rol de creación.

El 403 no es silencioso: `bootstrap/app.php:66-69` → JSON 403 `{"message":"No autorizado."}`; `apiFetch` lanza `Error` con ese mensaje (`panel/src/utils/api.ts:21-27`); las páginas lo muestran con `alert` (`limites.astro:169`) o `showModal` (detalle pages). Mensaje genérico y confuso para el usuario final.

### Direcciones posibles (sin decidir)

1. **Alinear UI a API**: ocultar/deshabilitar la pestaña y el botón Guardar de límites para `grupo`/`agencia` (y corregir `limites.astro:94`). Bajo riesgo, coherente con la spec de agencia (passthrough). Mantiene la lectura de límites para grupo/agencia (la API ya la permite).
2. **Abrir API a grupo** (y ¿agencia?): añadir `grupo` a los middleware de PUT/DELETE/batch y permitir que configure sus propios límites (su banca/grupo/taquillas). Implica definir alcance de escritura del rol grupo (hoy solo de lectura), decidir si agencia también escribe, y actualizar `RolesAndPermissionsSeeder`/tests. Mayor alcance, choca con la spec jerarquia-agencias si se incluye a agencia.

## Gap 3 — `agencia_id` en GET /limites/{juego}

- **Localización exacta**: validación del query param `JuegoController.php:178`; aplicación `:224-226` (`$query->where('agencia_id', $filtros['agencia_id'])` sobre el query de `JuegoLimite`). La tabla NO tiene la columna (migración `:24-38`; fillable `JuegoLimite.php:13-25`) → SQL error (columna desconocida) → 500 si se usa. Sin test que la ejercite (`LimitesScopedApiTest.php` usa `taquilla_id`, no `agencia_id`).
- **Contraste**: el endpoint moderno `listarLimites` (`GET /api/v1/limites?…`) NO acepta `agencia_id` (`:261-266`) y el panel solo usa ese (`panel/src/pages/limites.astro:123`, detalle pages). El alcance de agencia se resuelve por sus taquillas (`:194-203`, `:812-814`).
- **Respuesta de la spec**: `jerarquia-agencias/spec.md:7` y `:13`: agencia es nivel intermedio grupo→taquilla, SOLO identidad, no configura límites. La columna "correcta" no sería `agencia_id` en `juego_limites` sino la traducción agencia → taquillas.

### Opciones y consecuencias

| Opción | Consecuencias |
|---|---|
| **A. Eliminar el param** `agencia_id` de `limites()` (alinear con `listarLimites`) | Endpoint legacy queda consistente con el modelo; cualquier cliente que lo use dejaría de filtrar por agencia (hoy igualmente fallaría). Bajo esfuerzo. |
| **B. Implementarlo**: traducir `agencia_id` → `whereIn('taquilla_id', Taquilla::where('agencia_id', …)->pluck('id'))` | Reproduce el alcance de rol existente (`:202`); mantiene compatibilidad del param. Decide si "agencia" DEBE filtrar por sus taquillas o por su grupo/banca. Medio esfuerzo. |
| **C. Deprecar el endpoint** `limites()` completo (el panel no lo usa; solo `collections/Limites/` y tests) | Reduce superficie; `listarLimites` lo cubre. Requiere mover tests. |

## Gap 4 — código muerto

| Pieza | Evidencia | Estado |
|---|---|---|
| `panel/src/components/LimitesTable.astro` | grep `LimitesTable` en todo el repo → 0 imports; expone `window.__limitesTabla` (solo se auto-referencia) | **Muerto** — el patrón actual es `crearTablaLimites` desde las páginas |
| Nav `/limites` | `AdminLayout.astro:256` — solo `role === 'super_master'` | Visible solo para super_master; la página `limites.astro` soporta master/banca/grupo (accesible por URL directa, `:94`) |
| `authorizeLimitesWrite()` | `JuegoController.php:717-722` — definido, cero callers en app/tests | **Muerto** |
| Endpoint legacy `limites()` (GET /limites/{juego}) | No lo llama el panel (grep `limites/` en panel → solo POST batch); solo `collections/Limites/Listar Limites por Juego.yml` y `LimitesApiTest` | Candidato a deprecar (además contiene el bug de `agencia_id`) |

## Inventario de campos de `juego_limites`

| Columna | Migración (tipo/nullable/default) | Se escribe en | Se valida en | Se muestra (UI/API) | Lectores de negocio reales |
|---|---|---|---|---|---|
| `limite_minimo` | `decimal(12,2)` nullable (`migración:31`) | `JuegoLimiteService::CAMPOS:27`; `updateLimites:421-424`; `batchLimites:454` | nullable numeric min:0 (service `:41`, controller `:389`, `:454`) | UI número (`panel/src/utils/limites.ts:49`); API `serializarLimite:1016` | ✅ **Operativo**: `ApuestaService::validarCostoMinimo:53-89`; `validarContraLimite:300-303` |
| `limite_maximo` | `decimal(12,2)` nullable (`:32`) | ídem | ídem (`:42`, `:390`, `:455`) | UI (`limites.ts:50`); API `:1017` | ✅ **Operativo**: `validarContraLimite:293-297` |
| `porcentaje_pago` | `decimal(5,2)` nullable (`:33`) | ídem (`:27`, `:422`, `:456`) | nullable numeric 0..100 (`:43`, `:391`, `:456`) | UI (`limites.ts:51`); API `:1018` | ❌ **Cero** (solo test `CrearEntidadConLimitesTest.php:77,95` y collections) |
| `participacion` | `decimal(10,2)` nullable (`:34`) | ídem (`:27`, `:423`, `:457`) | nullable numeric 0..100 (`:44`, `:392`, `:457`) | UI (`limites.ts:52`); API `:1019` | ❌ **Cero** (`ApuestaService.php:565` es variable LOCAL de reporte de ventas, no la columna) |
| `fraccion` | `tinyInteger` default 0 (`:35`) | ídem (`:27`, `:424`, `:458`) | `boolean` en service `:45`, PUT `:393`, batch `:458` (no required; omitirlo valida bien) | checkbox UI (`limites.ts:53`); API `:1020` | ❌ **Cero** + bug: desmarcar borra el campo (`limites.ts:200-207`) → `false` nunca persiste; si era el único campo tocado, ítem vacío → 422 (`JuegoLimiteService:125-127`). Verificado en código |
| `limite_tiempo` | `integer` nullable, comment 'minutes' (`:36`) | ídem (`:27`, `:425`, `:459`) | nullable integer min:1 (`:46`, `:394`, `:459`) | UI (`limites.ts:54`); API `:1021` | ❌ **Cero**; no existe concepto "corte" en el backend fuera de premios (prohibido) — la hipótesis "minutos de corte antes del sorteo" no tiene implementación actual |

## Preguntas para el usuario

### P1. Gap 3 — `agencia_id` en GET /limites/{juego}: ¿borrar o implementar?
- **A. Eliminar el param** (y/o deprecar el endpoint legacy `limites()`): bajo esfuerzo, consistente con `listarLimites` y con la spec (agencia = passthrough, no filtra límites por sí misma).
- **B. Implementarlo** traduciendo agencia → sus taquillas: mantiene compatibilidad, pero introduce una forma de filtrar que duplica el alcance de rol existente.
- Tradeoff: A reduce superficie muerta (recomendada si nadie usa el endpoint); B solo si hay clientes que dependen del param (hoy fallaría, así que improbable).

### P2. Semántica de `fraccion` y `limite_tiempo` (cero lectores de negocio)
- Hipótesis (a confirmar): `fraccion` = venta fraccionada (¿el juego admite apuestas fraccionadas?); `limite_tiempo` = minutos de corte antes del sorteo (comentario de la migración: 'minutes').
- Opciones: (a) implementar su semántica (nuevos lectores en `ApuestaService` u otro), (b) ocultarlos de la UI y la API hasta que tengan dueño de negocio, (c) eliminarlos. **Además** (corregido por gatekeeper): el checkbox `fraccion` no puede guardar `false` y puede dejar un ítem vacío → 422; decidir si se retira el campo del panel o se arregla el payload (semántica de celda vacía / enviarlo siempre).

### P3. Gap 2 — dirección de alineación
- **A. Alinear UI a API** (ocultar guardado para grupo/agencia; arreglar `limites.astro:94` y los `canEditLimites` muertos): bajo riesgo, consistente con spec.
- **B. Abrir API a grupo** (y ¿agencia?): define un nuevo permiso de escritura de límites para niveles intermedios; mayor alcance, toca middleware, controlador y tests; choca con la spec de agencia si se la incluye.

### P4. Gap 1 — alcance de "volver a heredar"
- ¿Botón "Limpiar/heredar" **por fila** (DELETE /limites/{limite})? ¿O limpieza **por celda** (enviar null → borra la fila completa, no la celda)? ¿Ambos?
- Decidir la semántica de la celda vacía en la tabla: hoy "vacío = no tocar" (`limites.ts:9-12`), lo que impide limpiar un valor sin borrar la fila. Y unificar el doble comportamiento null (PUT escribe NULL; batch borra fila).

## Riesgos

- **Checkbox `fraccion` defectuoso (corregido por gatekeeper)**: la regla no es REQUIRED; el defecto real es que desmarcarlo nunca persiste `false` y puede dejar un ítem vacío → 422. Aplica a cualquier flujo del panel que use el checkbox.
- **Semántica de NULL por columna vs por fila**: cualquier cambio de UI debe documentar que una fila con NULL bloquea la herencia del padre; "volver a heredar" requiere borrar la fila.
- **Endpoints legacy vs moderno**: `limites()` y `listarLimites()` conviven; tocar uno sin el otro crea inconsistencias de filtros.
- **Zona COMISIONES**: `porcentaje_pago`/`participacion` los consume el ciclo paralelo; este cambio NO debe asumir su semántica (solo reportar evidencia, como aquí).
- **Sin tests que cubran `JuegoLimiteService`** (blast radius CodeGraph: 8 callers, sin covering tests) — cualquier cambio de semántica de guardado debe añadir cobertura.

## Nota de corrección (gatekeeper, 2026-09-29)

Verificación del orquestador en código tras la exploración: la regla de `fraccion` NO es "REQUIRED"; es `boolean` (omitir el campo valida bien; `null` explícito sí falla). El defecto real del checkbox: desmarcarlo elimina el campo del ítem (`panel/src/utils/limites.ts:200-207`) → (a) nunca se persiste `fraccion=false`, y (b) si fue el único campo tocado, el ítem queda vacío → 422 (`JuegoLimiteService.php:125-127`). Puntos corregidos: resumen ejecutivo #3, inventario (`fraccion`), pregunta P2 y riesgos. El resto del documento fue spot-checkeado y quedó sin cambios (rutas/roles, `agencia_id`, DELETE, gates muertos, payload present-fields-only).