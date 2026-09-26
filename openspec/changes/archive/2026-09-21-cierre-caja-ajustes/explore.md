# Exploration: cierre-caja-ajustes

Cambio sucesor de `cierre-caja-taquilla` (archivado 2026-09-17, PR #12 abierto sin release, misma rama `feat/cierre-caja-taquilla`). Ajustes operativos al cierre de caja: período abierto explícito, reportes por rangos, un cierre por día calendario con clave de cierre, y configuración de la clave desde el panel admin.

## Estado actual (verificado en código)

### Backend
- `POST /api/v1/cierre` → `CierreController::store()` (`backend/app/Http/Controllers/Api/CierreController.php:29-52`) siempre crea fila nueva vía `CierreService::crearCierre()` (`backend/app/Services/CierreService.php:31-65`); `resolveFechaInicio()` toma el último cierre por `fecha_fin` o la primera apuesta (`CierreService.php:330-349`). Sin detección de "ya hay cierre hoy".
- `GET /api/v1/cierre/semanal` → `CierreController::semanal()` (`CierreController.php:95-130`) YA soporta `fecha_desde`+`fecha_hasta` arbitrarios (XOR con `fecha`, ambos inclusivos a nivel de día) vía `resolveVentanaSemanal()` (`CierreController.php:254-292`); probado en `backend/tests/Feature/CierreCajaTest.php:1009-1036`. El rollup `reporteSemanal()` (`CierreService.php:224-301`) devuelve totales, desglose fusionado, `ventana_cubierta` y `cierres[]` (solo id, taquilla_id, fecha_inicio, fecha_fin, total_ventas_bs, total_efectivo_bs — `CierreService.php:292-299`).
- `GET /api/v1/cierre/actual` → `previsualizar()` (`CierreService.php:312-325`): preview read-only; no indica si ya existe un cierre hoy.
- Autorización jerárquica por rol: `scopeCierresPara()` (`CierreController.php:150-189`), `resolveTaquillaParaCierre()` (`CierreController.php:299-361`), `assertTaquillaEnAlcance()` (`:195-243`). Rutas: grupo `role:super_master|master|banca|grupo|taquilla|agencia` (`backend/routes/api.php:164-172`).
- Modelo `User` (`backend/app/Models/User.php`): roles duales — columna `role` (fillable `:21-31`) + Spatie (`HasRoles` `:14`; seeder asigna ambos, `backend/database/seeders/UsersSeeder.php:22-25`). Alcances de jerarquía: `masterBancaIds()` (`:112-117`), `masterBancaChainScope()` (`:142-151`), `masterCanAccessBanca()` (`:173-176`). Jerarquía banca→master vía `bancas.master_id` (fillable `backend/app/Models/Banca.php:14`; migración `2026_08_28_000004_add_master_id_to_bancas_table`).
- Patrón de hash de credenciales: `Hash::make` al crear/actualizar (`backend/app/Http/Controllers/Api/UserController.php:146,211-212`), `Hash::check` en login (`backend/app/Http/Controllers/Api/AuthController.php:22`). `$hidden` de `User` (`User.php:38-41`) excluye `password`/`remember_token` de la serialización.
- Migraciones: `cierres_caja` (`2026_07_10_035540_create_cierres_caja_table.php:11-26`) con `fecha_fin` nullable; arqueo/desglose/índice `cierres_caja_taquilla_fecha_fin_index` en `2026_09_17_000001_add_metodo_pago_and_arqueo.php:39-46`. `users` base (`0001_01_01_000000_create_users_table.php:14-22`) + roles/relaciones (`2026_07_10_035612_add_roles_and_relations_to_users_table.php`). **No existe `clave_cierre` ni `reclosed_*` en ninguna parte** (grep sin resultados).
- Timezone de la app: `America/Caracas` (`backend/config/app.php:68`) → `now()` y `Carbon::parse` ya operan en Caracas.

### Taquilla
- `taquilla/src/pages/cierre.astro`: card "Resumen del período actual" (`:10-18`), render `Período: X → Y · Tasa` (`:360`); card "Arqueo de caja" (`:21-36`); resultado (`:39-48`); historial (`:51-56`); card "Reporte semanal" con UN input date + `?fecha=` (`:58-68`, `cargarSemanal()` `:594-606`, `renderSemanal()` `:608-647`); ejecución con confirm modal `¿Ejecutar el cierre de caja?` (`ejecutarCierre()` `:407-446`); `todayCaracas()` (`:586-592`); impresión por cierre vía `window.electron.printCierre` (`:559-583`).
- `window.showModal` (`taquilla/src/layouts/MainLayout.astro:164-192`) soporta `info|success|error|confirm` — SIN soporte de input (la clave de cierre requiere extenderlo o un modal dedicado).
- Impresión: `taquilla/electron/main/ipcHandlers.cjs` — `generateCierreHtml()` para un cierre individual (`:89-165`), handler `print-cierre` (`:266-275`), `printHtml()` con detección POS + fallback a diálogo (`:167-243`); expuesto en `preload.cjs:6`. **No hay impresión de reporte por rango.**
- `taquilla/src/utils/apiFetch.ts` clasifica errores `network|timeout|unauthorized|forbidden|rate-limited|http` (`:4-24`) — el 422 del backend cae en `http` y muestra `message`.

### Panel
- Páginas: sin página de perfil/seguridad; `panel/src/pages/usuarios.astro` es el modelo (página + modal + `apiFetch`) (`:1-29`). Sidebar construido en JS en `panel/src/layouts/AdminLayout.astro` (`addLink`/`addDropdownItem` `:148-164`; sección de links `:166-291`). `panel/src/utils/api.ts` — `apiFetch` con token `panel_token` (`:5-33`). `panel/src/pages/cuadre.astro` es el cuadre de ventas (`/reportes/cuadre-caja`), NO el cierre de caja.

### Colecciones (Postman-like)
- `collections/Cierre de Caja/`: `Crear Cierre (Token required).yml` (POST sin `clave_cierre`), `Cierre Semanal (Token required).yml` (documenta `fecha_desde`+`fecha_hasta`), `Ver Cierre`, `Listar Cierres`, `folder.yml`.

## Brechas (gaps)

1. **Período abierto explícito**: la card y el render no comunican que el período es ABIERTO desde el último cierre hasta ahora; puede parecer "la semana".
2. **Reportes por rangos**: el backend ya soporta rangos arbitrarios, pero la UI solo tiene un date input (modo semana); falta el segundo calendario, el listado de cierres del rango (ya viene en `cierres[]`) y la impresión del reporte.
3. **Un cierre por día**: no existe; un segundo cierre del mismo día crea fila duplicada. Falta la bifurcación crear→actualizar, la clave obligatoria y los campos de auditoría del re-cierre.
4. **Clave de cierre**: no existe almacenamiento, endpoint de configuración ni validación de cadena.
5. **UI del panel**: no hay página para configurar la clave.
6. **Impresión**: no hay `print-reporte`.
7. **Colecciones/tests**: desactualizados respecto a los nuevos comportamientos.

## Opciones con tradeoffs

### O1 — Un cierre por día: dónde bifurcar
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. En `CierreService::crearCierre()` (helper `resolveCierreHoy()`) | Un solo punto de entrada; la transacción ya existe; tests directos | El service necesita la clave validada antes (dependencia nueva) | Media |
| B. En `CierreController::store()` antes de llamar al service | El controller ya resuelve la taquilla autorizada | Lógica de negocio en el controller (rompe patrón actual); difícil testear el update sin pasar por HTTP | Media-Alta |

**Recomendación: A** — helper en `CierreService` que detecte el cierre de hoy (rango `[now()->startOfDay(), +1d)` sobre `fecha_fin`; app timezone ya es Caracas) y bifurque: crear (201, `reclosed:false`) vs actualizar conservando `fecha_inicio` original (200, `reclosed:true`). Validación de clave como paso previo dentro de la misma transacción (ver O2).

### O2 — Clave de cierre: almacenamiento y validación
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. Columna `users.clave_cierre` (hash bcrypt, nullable) + `Hash::check` contra la cadena hacia arriba | Patrón ya usado (password); un hash por usuario; sin tabla nueva; `$hidden` evita serialización | Si un candidato no tiene clave, hay que decidir el fallo | Baja |
| B. Tabla aparte `claves_cierre` (user_id, hash) | Separación de concerns | Overkill para un solo hash por usuario; joins extra; más migraciones | Alta |

**Recomendación: A** — migración `users.clave_cierre` (string nullable) + `cierres_caja.reclosed_by`/`reclosed_at` (auditoría; nunca guardar la clave). Candidatos de la cadena de la taquilla: (1) usuarios `role=banca` con `banca_id` = `taquilla->grupo->banca_id`; (2) `role=master` con `id` = `Banca.master_id` de esa banca (`User::masterCanAccessBanca`/`masterBancaIds` como referencia); (3) todos los `role=super_master`. `Hash::check` contra cualquiera de ellos; si ninguno tiene clave configurada → 422 con mensaje claro (decisión de bloqueo, ver Q2). Método dedicado `validarClaveCierre(int $taquillaId, string $clave)` en el service (testeable sin HTTP).

### O3 — Endpoint self-service de la clave
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. `PUT /api/v1/usuarios/clave-cierre` (auth:sanctum + verify.mac, middleware `role:super_master|master|banca`) + `GET` para estado | Ruta fuera de `apiResource users` (admin-managed, más roles); self-service puro; sigue el patrón de grupos de rol en `routes/api.php:64,72,187` | Dos rutas nuevas | Baja |
| B. Reusar `PUT /users/{user}` (UserController::update) | Sin rutas nuevas | Permite a un admin cambiar la clave de OTRO usuario (no es self-service puro); mezcla concerns; expone más roles | Media |

**Recomendación: A** — `GET` devuelve solo `{clave_configurada: bool}`; `PUT` recibe `clave_actual` + `clave_nueva` (min 4-6 dígitos, decisión en spec) y hace `Hash::make`. Patrón de autorización jerárquica ya resuelto por el middleware de rol (un banca solo cambia SU clave — el endpoint opera sobre `$request->user()`).

### O4 — Nombre del endpoint de reporte por rango
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. Mantener `GET /cierre/semanal` (ya soporta rangos) | Cero churn backend; tests y colecciones existentes siguen valiendo; el renombre es solo copy UI | El nombre "semanal" desentona con "reportes por rangos" | Nula |
| B. Añadir alias `GET /cierre/reporte` | Claridad de nombre | Churn de rutas/colecciones/tests sin valor funcional; dos nombres para lo mismo | Baja |

**Recomendación: A** — sin release y con el rango ya soportado, renombrar el endpoint es churn sin beneficio. El requisito se cumple en la UI (título, dos calendarios) y en las colecciones (renombrar el archivo de colección).

### O5 — Impresión del reporte por rango
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. Ampliar `cierres[]` del reporte con el shape completo del cierre + nuevo `generateReporteHtml()` + `print-reporte` | Un solo IPC nuevo; reutiliza `printHtml`/POS; imprime totales + desglose + listado | Payload del reporte crece (acceptable) | Media |
| B. Imprimir solo totales del rango con el payload actual | Cero cambios backend | Reporte impreso pobre (sin desglose por cierre) | Baja |

**Recomendación: A** — ampliar el map de `cierres[]` (`CierreService.php:292-299`) con los campos completos (desglose incluido), nuevo `generateReporteHtml` + `ipcMain.handle('print-reporte')` + `printReporte` en `preload.cjs`. `renderSemanal()` sigue funcionando (ignora campos extra).

### O6 — UI del panel para la clave
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. Página nueva `panel/src/pages/clave-cierre.astro` + 1 link en `AdminLayout.astro` (solo roles super_master|master|banca) | Archivo nuevo (cero fricción con ciclos paralelos); sigue el patrón `usuarios.astro` | 1 línea de edición en `AdminLayout.astro` (posible conflicto con worktree panel) | Baja |
| B. Modal dentro de `usuarios.astro` | Sin página nueva | Mezcla admin-managed con self-service; página más grande; toca un archivo probablemente tocado por el ciclo del panel | Media |

**Recomendación: A** — página nueva autónoma (fetch `GET`/`PUT /usuarios/clave-cierre`); el único toque a `AdminLayout.astro` es 1 línea de nav, claramente localizada. No tocar `panel/src/utils/api.ts`.

### O7 — Prompt de clave en la taquilla
| Opción | Pros | Contras | Complejidad |
|--------|------|---------|-------------|
| A. Extender `showModal` con `type:'input'` (MainLayout.astro) | Reusable (futuros prompts); cambio pequeño y localizado | Toca un layout compartido por todas las páginas de la taquilla | Baja |
| B. Modal inline dedicado en `cierre.astro` | Cero riesgo en otras páginas | Duplica lógica modal; más código en la página | Media |

**Recomendación: A** — `showModal` con variante input (`{ message, type:'input', placeholder }`). Flujo en `ejecutarCierre()`: si `GET /cierre/actual` devuelve `cierre_hoy` (campo nuevo aditivo del preview), el confirm dice "Cierre del día X ya registrado — se actualizará desde el último cierre" + input de clave; el body del POST lleva `clave_cierre`. El backend valida igualmente (422 si falta y es re-cierre).

## Alcance recomendado (para sdd-propose)

1. **Migración**: `users.clave_cierre` (string nullable) + `cierres_caja.reclosed_by` (FK users, nullable) + `cierres_caja.reclosed_at` (timestamp nullable).
2. **Backend**:
   - `CierreService`: `resolveCierreHoy()` + bifurcación crear/actualizar (idempotente); `validarClaveCierre($taquillaId, $clave)` (cadena hacia arriba); response `200`+`reclosed:true` en actualización; `previsualizar()` devuelve `cierre_hoy` aditivo.
   - `CierreController::store()`: validar `clave_cierre` opcional en input; pasar al service.
   - Endpoint self-service `GET`/`PUT /usuarios/clave-cierre` (roles super_master|master|banca; `Hash::make`/`Hash::check`; `$hidden` incluye `clave_cierre`).
3. **Taquilla** (`cierre.astro` + `MainLayout.astro`): copy del período ABIERTO con rango visible; card "Reportes por rangos" con dos calendarios + listado + botón imprimir; prompt de clave en re-cierre (modal input).
4. **Electron**: `generateReporteHtml` + `print-reporte` + `printReporte` en preload.
5. **Panel**: página `clave-cierre.astro` + 1 link de nav (roles super_master|master|banca).
6. **Colecciones**: `Crear Cierre` + `clave_cierre` y semántica de re-cierre; renombrar `Cierre Semanal` → `Reporte por Rango` (con `fecha_desde`/`fecha_hasta`); nueva `Configurar Clave de Cierre`.
7. **Tests**: `CierreCajaTest` (re-cierre mismo día: 1 fila, `fecha_fin` extendido, totales recalculados desde `fecha_inicio` original, idempotencia; sin clave → 422; clave incorrecta → 422; clave correcta desde banca/master/super_master → 200; primer cierre libre; día siguiente → fila nueva) + tests nuevos de clave (hash almacenado, no serializado, endpoint self-service, alcance de cadena, banca de otra banca rechazada).

## Riesgos

- **Coordinación con el worktree del panel**: `AdminLayout.astro` y `utils/api.ts` son compartidos con un posible ciclo paralelo del panel. Mitigación: página nueva + 1 línea de nav; NO tocar `api.ts`. Mantener los cambios del panel mínimos y localizados.
- **Hashing/validación de clave**: bcrypt obligatorio; nunca serializar el hash (añadir a `$hidden`); definir longitud mínima y si el cambio exige clave actual (Q1). Candidatos de la cadena sin clave configurada → decisión de bloqueo (Q2).
- **Datos existentes con múltiples cierres el mismo día**: solo demo, sin release → sin backfill; el query de "cierre de hoy" toma el último por `fecha_fin` y el update conserva su `fecha_inicio` (comportamiento correcto hacia adelante).
- **Límite de día con timezone**: la app ya corre en `America/Caracas` (`config/app.php:68`); usar rango explícito `[startOfDay, +1d)` sobre `fecha_fin` (no `whereDate`, que compara el valor crudo UTC).
- **Churn de renombrar el endpoint**: mantener `GET /cierre/semanal` evita tocar rutas/colecciones/tests existentes; el renombre es solo copy UI (O4).

## Preguntas abiertas

- **Q1**: ¿El cambio de clave en el panel debe exigir la clave actual (self-service seguro, recomendado) o solo la nueva?
- **Q2**: Si ningún candidato de la cadena hacia arriba tiene clave configurada, ¿el re-cierre se bloquea con 422 (recomendado) o se permite sin clave (fallback)?
- **Q3**: ¿`cierres[]` del reporte por rango debe incluir el desglose completo para imprimir (recomendado) o basta con totales?
- **Q4**: ¿Se mantiene `GET /cierre/semanal` como único endpoint de rango (recomendado) o se añade alias `/cierre/reporte`?
- **Q5**: Formato/longitud de la clave (p. ej. 4-8 dígitos numéricos vs alfanumérica) — decisión de negocio pendiente.
- **Q6**: Roles admin (master/banca) que ejecutan el cierre de una taquilla ajena a su rol: ¿la clave a validar es siempre la de la cadena de ESA taquilla (recomendado, regla única)? El master es candidato de su propia cadena, así que puede usar su propia clave.

## Ready for Proposal

Sí. Las decisiones vinculantes del usuario ya están tomadas (período abierto, rangos con dos calendarios, un cierre por día con clave, panel incluido, misma rama/PR). sdd-propose debe resolver las preguntas Q1-Q6 con el usuario o con defaults recomendados, y fijar el alcance de la migración + endpoints.