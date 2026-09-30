# Exploración — `colecciones-api`

> Fase: sdd-explore · Cambio: `colecciones-api` · Base: `d2f8261` (= origin/main)
> Fecha: 2026-09-30 · Formato: OpenCollection 1.0.0 (colecciones Bruno en `collections/`)

---

## 1. Resumen ejecutivo

Las colecciones de `collections/` (81 requests, 16 carpetas) quedaron desactualizadas frente a la API real. El drift es **sistemático y profundo**:

1. **Prefijo de URL roto**: la API se versionó bajo `/api/v1` (commit `baee6b6`, 2026-08-18, **anterior** a la última actualización de colecciones `1491640`, 2026-09-21), pero **77 de 81 requests** siguen usando `{{baseUrl}}/api/...` sin `/v1`. Solo 4 archivos usan `/api/v1/` y 1 quedó hardcodeado a `https://lotto.gzuz.dev/api/v1/juegos` (`collections/Juegos/Ver Juego.yml:12`). Hoy **ninguna colección funciona contra prod ni local** tal como está.
2. **Carpetas de endpoints inexistentes**: faltan carpetas completas para **Agencias** (`backend/routes/api.php:91-94`), **Tickets** (`api.php:166-172`), **Configuraciones** (`api.php:109-112`), **Logs** (`api.php:157-161`) y **Releases** (`api.php:38-43,254-257`).
3. **Contratos desactualizados**: límites envían `fraccion`/`limite_tiempo` eliminados de la API (`backend/app/Models/JuegoLimite.php:41-44`), apuestas no cubren `selecciones[]` multi-selección (`ffc629f`, `backend/app/Plugins/Juegos/Animalitos.php:79-127`), pagos ignoran el contrato autoritativo con montos opcionales (`PagoController.php:21-24`), y faltan roles/estados nuevos (`ganadora`, `vencido`).
4. **Sin entornos**: no existe carpeta `environments/`; `baseUrl` apunta a `http://localhost:8000` y `baseUrlWeb` a `https://lotto-api-6nrc.onrender.com` (`collections/opencollection.yml:21-29`), pero la producción vigente es **`https://lotto.gzuz.dev`** (`docs/deploy.md:41,465`, `Caddyfile:10-11`). La URL de Render es legado.

No hay colecciones huérfanas (sin endpoint): la única (`rendimiento-taquillas`) ya fue eliminada en `6b84cd0`. El problema inverso es el dominante: **endpoints sin colección**.

---

## 2. Inventario de drift completo

### 2.1 Convención de rutas vigente (fuente: `backend/routes/api.php`)

- Todas las rutas viven bajo `Route::prefix('v1')` (`api.php:28`) montado en `/api` (`backend/bootstrap/app.php` `withRouting(api:)`). **URL canónica: `/api/v1/...`** (confirmada en `docs/deploy.md:365,465`, `backend/rutas.md:8` — desactualizado — y `599f362 feat(clients): apuntar taquilla y panel a /api/v1`).
- Middleware dominante: `auth:sanctum` + `verify.mac` (`api.php:60`) salvo auth self-service (`api.php:46-57`) y rutas públicas (`api.php:31-43`).

### 2.2 Tabla de drift por endpoint

Leyenda estado: **OK** = colección existe y contrato al día · **PAYLOAD** = colección existe pero payload/respuesta desactualizado · **FALTA** = endpoint sin colección · **URL** = colección existe pero URL rota (falta `/v1` o hardcodeada).

| Método + path real | Colección existente | Estado | Evidencia |
|---|---|---|---|
| **Auth** | | | |
| POST `/api/v1/login` | `Auth/Login*.yml` (×5) | **PAYLOAD** | URL sin `/v1`; `X-Panel: true` requerido para roles de panel y prohibido para taquilla (`AuthController.php:53-61`); taquilla exige `X-Device-Fingerprint` (`AuthController.php:38-41`); respuesta `{token, user, role}` |
| GET `/api/v1/user` | `Auth/Get User (Token required).yml` | **URL** | `collections/Auth/Get User (Token required).yml:8` — sin `/v1` |
| POST `/api/v1/logout` | `Auth/Logout (Token required).yml` | **URL** | sin `/v1` |
| GET/PUT `/api/v1/usuarios/clave-cierre` | `Cierre de Caja/Configurar Clave de Cierre.yml` | **OK** | usa `/api/v1/` (`collections/Cierre de Caja/Configurar Clave de Cierre (Token required).yml:8`); contrato coincide con `ClaveCierreController.php:16-55`; roles SM\|M\|B (`api.php:53`) |
| **Usuarios** | | | |
| `apiResource /api/v1/users` (GET/POST/PUT/DELETE) | `Usuarios (…)/*.yml` (×5) | **PAYLOAD** | URLs sin `/v1`; payload nuevo: `agencia_id`, rol `agencia` (`UserController.php:128-138`); destroy restringido a SM\|M (`UserController.php:227-231`); roles ruta SM\|M\|B\|G\|T\|A (`api.php:67-69`) |
| **Bancas** | | | |
| `apiResource /api/v1/bancas` (×5) | `Bancas/*.yml` (×5) | **URL** | sin `/v1`; roles SM\|M (`api.php:74-77`) |
| PATCH `/api/v1/bancas/{banca}/toggle` | — | **FALTA** | `api.php:76` |
| **Grupos** | | | |
| `apiResource /api/v1/grupos` (×5) | `Grupos (…)/*.yml` (×5) | **URL** | sin `/v1`; roles SM\|M\|B (`api.php:82-85`) |
| PATCH `/api/v1/grupos/{grupo}/toggle` | — | **FALTA** | `api.php:84` |
| **Agencias** | | | |
| `apiResource /api/v1/agencias` + toggle | **NO EXISTE carpeta** | **FALTA** | `api.php:91-94`; roles SM\|M\|B\|G\|A; payload `{name, code, grupo_id, active, rif, email, telefono, direccion, estado, municipio}` (`AgenciaController.php:65-79`); agencia solo lee su local (`AgenciaController.php:41-44`) |
| **Taquillas** | | | |
| `apiResource /api/v1/taquillas` (×5) | `Taquillas (…)/*.yml` (×5) | **PAYLOAD** | sin `/v1`; nuevo campo `agencia_id` en update (`TaquillaController.php:238`); roles SM\|M\|B\|G\|A (`api.php:99-102`) |
| PATCH `/api/v1/taquillas/{taquilla}/toggle` | — | **FALTA** | `api.php:101` |
| **Configuraciones** | | | |
| GET/PUT `/api/v1/configuraciones/apuestas-vencimiento` | **NO EXISTE carpeta** | **FALTA** | `api.php:109-112`; GET → `{horas}`, PUT `{horas: 1..8760}` (`ConfiguracionController.php:20-34`); roles SM\|M |
| **Juegos** | | | |
| GET `/api/v1/juegos`, GET `/api/v1/juegos/{juego}` | `Juegos/Listar Juegos.yml`, `Juegos/Ver Juego.yml` | **URL** | `Ver Juego.yml:12` hardcodeada a `https://lotto.gzuz.dev/api/v1/juegos` (debe usar `{{baseUrl}}`) |
| PUT `/api/v1/juegos/{juego}` | `Juegos/Actualizar Juego.yml` | **URL** | sin `/v1`; roles SM\|M (`api.php:120-123`) |
| PATCH `/api/v1/juegos/{juego}/toggle` | `Juegos/Toggle Juego.yml` | **URL** | sin `/v1`; body `{active: bool}` (`JuegoController.php:39-41`) |
| GET `/api/v1/juegos/{juego}/opciones` | `Juegos/Opciones (×3).yml` | **URL** | sin `/v1` |
| GET `/api/v1/juegos/{juego}/horarios` | `Juegos/Horarios (×3).yml` | **URL** | sin `/v1` |
| GET `/api/v1/juegos/{juego}/reglas` | `Juegos/Reglas (×3).yml` | **PAYLOAD** | sin `/v1`; respuesta ahora incluye `premios` del motor de forma aditiva (`JuegoController.php:150-153`) y `modalidades` filtradas (`JuegoController.php:144-148`) |
| **Límites** | | | |
| GET `/api/v1/limites` (matriz por entidad o `?scope=bancas\|grupos\|taquillas`) | — | **FALTA** | `api.php:201`; contrato en `JuegoController.php:248-365` (modo entidad exactamente 1 de `banca_id\|grupo_id\|taquilla_id` → matriz `juego_id:moneda` con `origen`; modo scope con raíz opcional → matriz `entidad_id:juego_id:moneda` con `mixto`); roles SM\|M\|B\|G\|A (`JuegoController.php:252-254`) |
| GET `/api/v1/limites/{juego}` | `Limites/Listar Limites por Juego.yml` | **PAYLOAD** | sin `/v1`; **sin `agencia_id`**: solo `banca_id\|grupo_id\|taquilla_id` (`JuegoController.php:175-179`); el legacy ignora `agencia_id` desde `2cc6693`; roles SM\|M\|B\|G\|A (`JuegoController.php:171-173`) |
| PUT `/api/v1/limites/{juego}` | `Limites/Configurar Limite.yml` | **PAYLOAD** | sin `/v1`; body envía `fraccion` y `limite_tiempo` (`collections/Limites/Configurar Limite.yml:14-15`) que **ya no existen** en validación (`JuegoController.php:379-388`) y están `$hidden` (`JuegoLimite.php:41-44`); `banca_id` ahora **required**; roles SM\|M\|B (`api.php:207-210`) |
| DELETE `/api/v1/limites/{limite}` | — | **FALTA** | `api.php:209`; por id del registro `JuegoLimite` (`JuegoController.php:679-698`); roles SM\|M\|B con autorización jerárquica |
| POST `/api/v1/limites/batch` | `Limites/Configurar Limite (Batch).yml` | **PAYLOAD** | sin `/v1`; envía `fraccion`/`limite_tiempo` (`collections/Limites/Configurar Limite (Batch).yml:15-16,26-27`) ya retirados; **nuevo modo `scope`**: `{scope: {tipo: banca\|grupo\|taquilla\|bancas\|grupos\|taquillas, id?}, limites: [{juego_id, moneda, ...}]}` — en modo scope los ítems NO llevan `banca_id/grupo_id/taquilla_id` (`JuegoController.php:436-482`); guard de 500 entidades (`JuegoController.php:588-590`) |
| **Apuestas** | | | |
| GET `/api/v1/apuestas` | `Apuestas/Listar Apuestas.yml` | **PAYLOAD** | sin `/v1`; filtros nuevos `fecha_desde`, `fecha_hasta`, `estado`, `juego_id`, `sorteo_hora` (`ApuestaController.php:55-70`); respuesta `{data, summary}` |
| POST `/api/v1/apuestas` | `Apuestas/Crear Apuesta (×5).yml` | **PAYLOAD** | sin `/v1`; payload legacy `combinacion` sigue válido para simple, pero falta el contrato **`selecciones[]`** multi-selección same-draw y modalidades single-draw (`ffc629f`, `2cc0bf9`; `Animalitos.php:79-127` — `{modalidad:'tripleta', selecciones:[{animal}×3]}`); `metodo_pago` opcional (`ApuestaStoreRequest.php:25`) |
| GET `/api/v1/apuestas/historial` | `Apuestas/Historial Apuestas.yml` | **PAYLOAD** | sin `/v1`; filtro extra `ticket_code` (`ApuestaController.php:198-200`); paginación `per_page` hasta 100 |
| GET `/api/v1/apuestas/resumen` | `Apuestas/Resumen Estadistico.yml` | **URL** | sin `/v1`; respuesta incluye `tasa_actual` (`ApuestaController.php:248-251`) |
| GET `/api/v1/apuestas/{apuesta}` | `Apuestas/Ver Apuesta.yml` | **URL** | sin `/v1`; carga `detalles`, `pago` (`ApuestaController.php:147-149`) |
| DELETE `/api/v1/apuestas/{apuesta}` | `Apuestas/Eliminar Apuesta.yml` | **URL** | sin `/v1`; soft delete 5 min, body `{motivo?}` (`ApuestaController.php:257-284`) |
| **Tickets** | | | |
| GET/POST `/api/v1/tickets`, GET `/api/v1/tickets/ganadores`, GET/DELETE `/api/v1/tickets/{ticket}` | **NO EXISTE carpeta** | **FALTA** | `api.php:166-172`; roles SM\|M\|B\|G\|T (**sin agencia**); POST body `{lines: [{juego_id, amount_bs?, amount_usd?, combinacion?}], metodo_pago?}` (`TicketController.php:113-122`); validación monedas por taquilla (`TicketController.php:128-145`) |
| **Pagos** | | | |
| POST `/api/v1/pagos` | `Pagos/Registrar Pago (×3).yml` | **PAYLOAD** | sin `/v1`; **montos opcionales** en `egreso`: sin `amount_bs/usd` el backend aplica el premio del motor (`PagoController.php:21-24,106-128`); estados pagables `pendiente\|ganadora` (`PagoController.php:76-81`); `metodo_pago` enum `efectivo\|transferencia\|pago_movil\|punto_venta` (`PagoController.php:34`); respuesta incluye `premio` aplicado (`PagoController.php:202-207`); roles SM\|M\|B\|G\|T (**sin agencia**, `api.php:177`) |
| GET `/api/v1/pagos/{apuesta}` | `Pagos/Ver Pago por Apuesta.yml` | **URL** | sin `/v1` |
| **Cierre de Caja** | | | |
| POST `/api/v1/cierre` | `Cierre de Caja/Crear Cierre.yml` | **OK** | usa `/api/v1/`; body `{arqueo_efectivo_bs?, arqueo_efectivo_usd?, clave_cierre?}` (`CierreController.php:26-30`); respuesta con `reclosed` (201/200) (`CierreController.php:54-56`) |
| GET `/api/v1/cierre` | `Cierre de Caja/Listar Cierres.yml` | **URL** | sin `/v1`; paginación `per_page` (`CierreController.php:60-66`) |
| GET `/api/v1/cierre/actual` | — | **FALTA** | `api.php:189`; preview read-only del período actual (`CierreController.php:73-83`) |
| GET `/api/v1/cierre/semanal` | `Cierre de Caja/Reporte por Rango.yml` | **OK** | usa `/api/v1/` (`…Reporte por Rango.yml:8`); params `fecha\|fecha_desde\|fecha_hasta`, `taquilla_id` opcional (`CierreController.php:88-97`) |
| GET `/api/v1/cierre/{cierre}` | `Cierre de Caja/Ver Cierre.yml` | **URL** | sin `/v1` y con **`{id}` literal** en la URL (`collections/Cierre de Caja/Ver Cierre (Token required).yml:8`) — Bruno enviaría el literal `{id}`; debe ser variable |
| **Resultados** | | | |
| GET `/api/v1/resultados` | `Resultados/Listar Resultados.yml` | **URL** | sin `/v1` |
| GET `/api/v1/resultados/{resultado}` | `Resultados/Ver Resultado.yml` | **URL** | sin `/v1` |
| GET `/api/v1/resultados/{resultado}/apariciones` | — | **FALTA** | `api.php:134`; último 5 sorteos (`ResultadoController.php:63-100`) |
| POST `/api/v1/resultados/scrape` | `Resultados/Ejecutar Scraper (×3).yml` | **URL** | sin `/v1`; body `{juego_id?, fecha?}` (`ResultadoController.php:110-112`); roles SM\|M (`api.php:137-140`) |
| POST `/api/v1/resultados/scrape-all` | `Resultados/Ejecutar Todos los Scrapers.yml` | **URL** | sin `/v1`; body `{fecha?}`; respuesta `total_juegos` (`ResultadoController.php:155-168`) |
| **Reportes** | | | |
| GET `/api/v1/reportes/ventas-totales` | `Reportes/Ventas Totales.yml` | **URL** | sin `/v1` |
| GET `/api/v1/reportes/cuadre-caja` | — | **FALTA** | `api.php:222` |
| GET `/api/v1/reportes/relacion-tickets` | `Reportes/Relación Tickets.yml` | **URL** | sin `/v1` |
| GET `/api/v1/reportes/vencidos` | `Reportes/Vencidos.yml` | **URL** | sin `/v1` |
| **Estadísticas** | | | |
| GET `/api/v1/estadisticas/rendimiento` | `Estadisticas/Rendimiento.yml` | **URL** | sin `/v1` |
| **Tasas de Cambio** | | | |
| GET `/api/v1/exchange-rate/active` (público) | `Tasas de Cambio/Obtener Tasa Activa.yml` | **URL** | sin `/v1`; pública (`api.php:32`) |
| GET `/api/v1/exchange-rates` / `{id}` | `Tasas/Listar Tasas.yml`, `Ver Tasa.yml` | **URL** | sin `/v1`; permiso `view_exchange_rates` (`api.php:237-240`) |
| POST `/api/v1/exchange-rates` | `Tasas/Crear Tasa.yml` | **URL** | sin `/v1`; permiso `manage_exchange_rates` |
| POST `/api/v1/exchange-rates/scrape` | `Tasas/Obtener Tasa del BCV (Scraper).yml` | **URL** | sin `/v1` |
| PUT `/api/v1/exchange-rates/{id}` | `Tasas/Actualizar Tasa.yml` | **URL** | sin `/v1` |
| POST `/api/v1/exchange-rates/{id}/set-active` | `Tasas/Setear Tasa Activa.yml` | **URL** | sin `/v1` |
| **Logs** | | | |
| GET `/api/v1/logs` | — | **FALTA** | `api.php:157-161`; `?per_page`; roles SM\|M |
| **Releases / distribución** | | | |
| GET `/api/v1/update-check` (público) | — | **FALTA** | `api.php:43` |
| GET `/api/v1/releases/latest`, `/download` | — | **FALTA** | `api.php:254-257`; roles SM\|M\|B\|G\|A |
| GET `/api/v1/releases/serve` (URL firmada) | — | **FALTA** | `api.php:38-40` |
| **Públicas** | | | |
| POST `/api/v1/activar` | `Activacion/Activar Taquilla.yml` | **URL** | sin `/v1`; body `{activation_code, mac_address, device_fingerprint}` (`ActivacionController.php:22-31`) |
| POST `/api/v1/dispositivo/verificar` | `Dispositivo/Verificar Dispositivo.yml` | **URL** | sin `/v1`; body `{device_fingerprint}` (`DispositivoController.php:13-15`) |

### 2.3 Resumen cuantitativo

| Métrica | Valor |
|---|---|
| Requests de colección | 81 (16 carpetas + root `opencollection.yml`; 98 archivos YAML en total) |
| Requests con URL sin `/v1` | **77** |
| Requests con `/api/v1/` correcto | 3 (Cierre de Caja) |
| Requests con URL hardcodeada | 1 (`Juegos/Ver Juego.yml`) |
| Requests con `{id}` literal | 1 (`Cierre de Caja/Ver Cierre.yml`) |
| Endpoints **FALTA** (sin colección) | 16 requests en 5 carpetas nuevas (Agencias, Tickets, Configuraciones, Logs, Releases) + 9 en carpetas existentes (limites GET matriz, DELETE limite, cierre/actual, apariciones, 3 toggles, cuadre-caja) |
| Colecciones huérfanas (sin endpoint) | **0** (`rendimiento-taquillas` ya eliminada en `6b84cd0`) |
| Payloads desactualizados | ≥12 (Limites ×2, Apuestas ×6, Pagos ×3, Usuarios, Taquillas, Juegos/reglas, Auth/login) |

### 2.4 Fuera de alcance (verificado, NO está en main)

- **Premios/config de juegos** (`feat/configuracion-juegos*`): la rama `feat/configuracion-juegos-s4` contiene `4f019ca` pero **no está fusionada en main**; `api.php` de main no tiene rutas de premios. El "export" del catálogo es un **comando CLI** `juegos:export` (`backend/app/Console/Commands/JuegosExportCommand.php:11`), no un endpoint.
- **Comisiones** (`feat/comisiones`): `badf261`, `701ddbd` no son ancestros de `d2f8261`; no hay rutas ni controladores de comisiones en el backend de main.
- **Resultados:metricas** (Prometheus): comando/agenda interna, sin API (`7f013d0`, `119e84b`).

---

## 3. Formato OpenCollection 1.0.0 y entornos

### 3.1 Convenciones del repo (confirmadas)

- Root: `collections/opencollection.yml` con `opencollection: 1.0.0`, `info.name`, `config.proxy`, `request.auth.type: bearer` + `token: "{{token}}"` y `request.variables` (`opencollection.yml:1-29`).
- Carpeta: `folder.yml` con `info {name, type: folder, seq}` y `request.auth: inherit` (p. ej. `collections/Auth/folder.yml`).
- Request: `info {name, type: http, seq}`, `http {method, url, headers, body, auth: inherit}`, `settings`, `runtime.scripts` (p. ej. Login captura `token` con `bru.setVar` en `collections/Auth/Login.yml`), `runtime.variables`, y bloques `docs:` (p. ej. `Configurar Clave de Cierre`).
- Commits: `docs(collections): <descripción>` (historial: `1491640`, `5194fc8`, `1631668`) y `docs(collection):` (`585416a`).

### 3.2 Entornos en OpenCollection 1.0.0 (documentación oficial)

Fuente: docs Bruno (`docs.usebruno.com/opencollection-yaml/overview` y `docs.usebruno.com/variables/environment-variables`; spec `spec.opencollection.com`, schema draft-07, versión actual 1.0.0, mantenida por Bruno).

- **Forma canónica**: carpeta `environments/` dentro de la colección, **un archivo YAML por entorno** (`<nombre>.yml`), sincronizada con el selector de entornos de la UI de Bruno.
- **Formato del archivo de entorno** (YAML):
  ```yaml
  name: Local
  variables:
    - name: baseUrl
      value: http://localhost:8000
      enabled: true
      secret: false
      description: API local (php artisan serve)
    - name: token
      value: ""
      enabled: true
      secret: true
      description: Token Bearer tras login (vacío a propósito)
  ```
  Soporta `extends` (herencia entre entornos, Bruno ≥4.2.0) y `secret: true`.
- **Entorno por defecto**: se declara en el root como `extensions.bruno.defaultEnvironment: <nombre>` dentro de `opencollection.yml`; viaja con la colección y lo usa la CLI con `--env` omitido.
- **Selección**: dropdown "No environment" en la UI; variables por entorno se referencian igual con `{{var}}`.
- **NO existe** una clave `environments:` en el root del formato 1.0.0; la carpeta `environments/` es el mecanismo soportado (mismo patrón que `.bru`).

### 3.3 Diseño de entornos — opciones con tradeoffs

| Opción | Descripción | Pros | Contras | Esfuerzo |
|---|---|---|---|---|
| **A. `environments/` estándar** (recomendada) | `environments/Local.yml` + `environments/Produccion.yml` con `baseUrl`, `token`, `macAddress`, `deviceFingerprint`; `defaultEnvironment` en `opencollection.yml`; URLs de requests como `{{baseUrl}}/api/v1/...` (baseUrl **sin** trailing slash) | 100% estándar OpenCollection; diff git mínimo (solo `opencollection.yml` + 2 archivos nuevos); las URLs quedan auto-descriptivas con el prefijo `/api/v1` visible; 77 requests solo cambian `api`→`api/v1` | Toca los 81 archivos para el prefijo | Bajo (mecánico) |
| **B. `baseUrl` con `/api/v1/` incluido** | `baseUrl = http://localhost:8000/api/v1/` (y `https://lotto.gzuz.dev/api/v1/` en prod); requests sin prefijo (`{{baseUrl}}login`, `{{baseUrl}}juegos`) | Coincide con el experimento local sin commitear del checkout principal (ver §6); menos ruido por request | Riesgo de `//` doble si algún path conserva slash inicial (inconsistente entre `{{baseUrl}}login` y `{{baseUrl}}/juegos` en el WIP); oculta el versionado; no es el patrón mostrado en la doc oficial | Bajo (mecánico) |
| **C. Solo variables en root** (sin carpeta `environments/`) | Mantener `request.variables` en `opencollection.yml` y cambiar valores a mano | Cero archivos nuevos | NO soporta selección desde la app; cada cambio es manual y propenso a errores; no es OpenCollection | Mínimo (pero no resuelve el objetivo) |

**Recomendación de exploración**: opción **A** (entornos estándar + `defaultEnvironment`), con nombres `Local` / `Produccion`. `token` vacío por entorno (con instrucción en `description`); **sin secretos hardcodeados** (el checkout principal tiene un hash de password real en `collections/Auth/Login.yml` — ver §6 — que NO debe entrar). Mantener `macAddress`/`deviceFingerprint` como variables por entorno. Resolver la convención `baseUrl` (A vs B) en diseño; la decisión del usuario manda (ver §5, P2).

### 3.4 URLs reales por entorno (evidencia)

| Entorno | URL | Evidencia |
|---|---|---|
| **Producción** | `https://lotto.gzuz.dev` (VPS, Caddy → `api:10000`, FrankenPHP) | `docs/deploy.md:41,465`; `Caddyfile:10-11`; `runbook-ops.md:8,119` |
| Legado prod (obsoleto) | `https://lotto-api-6nrc.onrender.com` | `collections/opencollection.yml:23` (actual `baseUrlWeb`); sin referencias en docs vigentes |
| **Local** | `http://localhost:8000` (backend Laravel vía `php artisan serve`; `docker-compose.yml` solo levanta MySQL/Redis/phpMyAdmin, sin servicio API) | `collections/opencollection.yml:29`; `backend/rutas.md:8` |

---

## 4. Cambios contractuales clave a reflejar (inventario, sin rediseñar)

1. **Prefijo `/api/v1`**: obligatorio en las 77 URLs rotas + deshardcodear `Ver Juego`.
2. **Límites post `limites-gaps`**: `fraccion`/`limite_tiempo` retirados de payloads y respuestas (`$hidden`, `JuegoLimite.php:41-44`); `GET /limites` matriz (modo entidad XOR `scope`, `origen`/`mixto`, `JuegoController.php:248-365`); `PUT /limites/{juego}` con `banca_id` requerido; `DELETE /limites/{limite}` por id; `POST /limites/batch` con modo `scope` (`{tipo, id?}`) y ítems sin entidades en modo scope; roles: GET SM\|M\|B\|G\|A, escritura SM\|M\|B (`api.php:198-215`).
3. **Multi-selección**: `POST /apuestas` y `POST /tickets` aceptan `combinacion.selecciones[]` (tripleta same-draw `{modalidad:'tripleta', selecciones:[{animal}×3]}`) y modalidades single-draw; validación en plugins (`Animalitos.php:79-127`, `Tripletas.php`) y `ApuestaStoreRequest.php:20-26`.
4. **Pagos autoritativos**: montos opcionales en `egreso`; backend aplica el premio del motor; solo `pendiente|ganadora` son pagables; respuesta `premio` aplicado (`PagoController.php:21-24,76-81,102-128,202-207`).
5. **Estados nuevos**: apuestas/tickets `ganadora`, `vencido`, `perdida`, `pagada` (`PagoController.php:151,166-176`); ticket cascada `pagada` cuando todas resueltas.
6. **Jerarquía agencias**: rol `agencia` en la mayoría de endpoints (lee su local); `agencia_id` en payloads de Taquilla/Usuario; Tickets y Pagos **excluyen** agencia (`api.php:166,177`).
7. **Vencimiento configurable**: `GET/PUT /configuraciones/apuestas-vencimiento` (`{horas}`), SM\|M (`ConfiguracionController.php:20-34`).
8. **Clave de cierre**: self-service `{clave_configurada}`; PIN 4-8 dígitos; `clave_actual` requerida solo si ya existe (`ClaveCierreController.php:16-55`).
9. **Reglas con premios**: `GET /juegos/{juego}/reglas` responde `premios` del motor + `modalidades` permitidas (`JuegoController.php:144-153`).
10. **Auth**: `X-Panel: true` para roles de panel; taquilla con fingerprint (`AuthController.php:30-42,53-61`); login taquilla NO debe enviar `X-Panel`.
11. **Filtros/paginación**: `estado`, `juego_id`, `sorteo_hora`, `ticket_code`, `fecha_desde/hasta` en apuestas; `per_page` en varios listados.

---

## 5. Preguntas para el usuario (antes de proponer)

1. **Alcance**: ¿todo el drift (§2) o solo **Límites + entornos** (como sugiere el título del cambio)? Si es todo, entra Fase 2 con 5 carpetas nuevas.
2. **Convención de URLs**: ¿`baseUrl` **sin** trailing slash + paths `/api/v1/...` (opción A) o `baseUrl` con `/api/v1/` incluido + paths sin prefijo (opción B, como el WIP sin commitear)? ¿Se elimina `baseUrlWeb`?
3. **Nombres/URLs de entornos**: ¿`Local` / `Produccion`? ¿Prod = `https://lotto.gzuz.dev` (vigente) y se descarta `lotto-api-6nrc.onrender.com`?
4. **Tokens**: ¿`token` vacío por entorno con instrucciones (recomendado) o algún mecanismo de carga automática (script after-response de login → `bru.setVar` ya existe en `Login.yml`)?
5. **Ediciones locales sin commitear del checkout principal** (`git -C /home/gzuz/Documentos/lotto-app diff -- collections/`, 7 archivos): ¿**integrar** (parecen ser la intención de la opción B), **descartar**, o **ignorar** (trabajar sobre `d2f8261` en el worktree)? Nota: contienen un **hash de password real** en `Auth/Login.yml` que no debería commitearse.
6. **Carpetas nuevas**: ¿se crean Agencias/Tickets/Configuraciones/Logs/Releases en este cambio o en otro posterior?

---

## 6. Contexto extra — ediciones locales sin commitear (checkout principal)

`git -C /home/gzuz/Documentos/lotto-app diff -- collections/` (7 archivos; NO modificados ni integrados — decisión del usuario):

- `Auth/Get User (Token required).yml`, `Auth/Login (Taquilla).yml`, `Auth/Login.yml`, `Resultados/Ejecutar Todos los Scrapers.yml`, `Resultados/Ver Resultado.yml`: URL `{{baseUrl}}/api/...` → `{{baseUrl}}...` (sin `/api`), coherente con `baseUrl = https://lotto.gzuz.dev/api/v1/` (opción B de §3.3). `Login (Taquilla)` elimina `auth: none`.
- `Juegos/Ver Juego.yml`: hardcode → `{{baseUrl}}/juegos`.
- `Auth/Login.yml`: `X-Panel: true` (quoted) y **password hash real** `3d02b41a…` (NO commitear).
- `Resultados/Ejecutar Todos los Scrapers.yml`: `fecha` cambiada a `2026-09-09`.
- `collections/opencollection.yml`: elimina `baseUrlWeb`, `baseUrl = https://lotto.gzuz.dev/api/v1/`.

Conclusión: el usuario ya experimentó la opción B localmente; la decisión de §5-P2/P5 debe cerrar si ese experimento es la base del cambio o solo contexto.

---

## 7. Riesgos

- **R1 — Decisión de convención bloqueante**: si no se cierra A vs B (§5-P2), el cambio de URLs puede rehacerse; afecta a los 81 requests.
- **R2 — Hash de password en git**: el WIP del checkout principal incluye un hash real; riesgo de fuga si se integra sin limpiar (§5-P5).
- **R3 — Contratos de límites sin tests**: `updateLimites`, `destroyLimite`, `batchLimites`, `listarLimites` no tienen tests que los cubran (CodeGraph: "no covering tests found"); el payload de las colecciones debe validarse contra el controlador, no contra tests.
- **R4 — `{id}` literal en Ver Cierre**: la colección enviaría el literal `{id}`; si se toca Cierre, corregir.
- **R5 — Inconsistencia de roles**: Tickets/Pagos excluyen `agencia` mientras casi todo lo demás lo incluye; las colecciones de Tickets nuevas deben marcar roles correctos o el usuario recibe 403 confuso.
- **R6 — Alcance vs tamaño**: Fase 2 completa ≈ 25+ requests nuevos + 77 URLs + payloads; supera cómodamente el presupuesto de revisión de 400 líneas de un solo PR (requiere fases/PRs encadenados).

---

## 8. Propuesta de fases

**Fase 1 — Entornos + Límites** (fundacional, autónoma, verificable):
1. `environments/Local.yml` + `environments/Produccion.yml` + `defaultEnvironment` en `opencollection.yml`; decidir A/B y eliminar `baseUrlWeb`.
2. Carpeta `Limites/` al día: quitar `fraccion`/`limite_tiempo` de `Configurar Limite.yml` y `Configurar Limite (Batch).yml`; agregar `GET /limites` (matriz, modo entidad y `scope`), `DELETE /limites/{limite}`, y ejemplos batch con `scope`; corregir `/v1` en los 3 archivos.
3. Corregir `/v1` en los 4 archivos de Cierre de Caja + deshardcodear `Juegos/Ver Juego.yml` (para que la colección completa vuelva a ejecutarse).
4. Commit `docs(collections):` por work unit. Alcance: ~12 archivos tocados + 2-3 nuevos.

**Fase 2 — Drift restante** (depende de P1):
1. `/v1` en los ~74 requests restantes (mecánico).
2. Carpetas nuevas: `Agencias/` (CRUD+toggle), `Tickets/` (index/store/ganadores/show/destroy), `Configuraciones/` (vencimiento), `Logs/`, `Releases/` (latest/download/serve/update-check).
3. Payloads: apuestas `selecciones[]` (ejemplos por familia), pagos montos opcionales, taquillas/usuarios `agencia_id`, reglas con `premios`, filtros de apuestas.
4. Requests faltantes en carpetas existentes: `cierre/actual`, `apariciones`, toggles (bancas/grupos/taquillas), `reportes/cuadre-caja`.
5. Docs de roles/estados por request (bloques `docs:`).

**Estimación de alcance**: Fase 1 ≈ 15 archivos (~200-300 líneas de diff); Fase 2 ≈ 100+ archivos (requiere dividirse en 2-3 PRs encadenados por el límite de 400 líneas).

---

## 9. Pendientes de exploración (incertidumbre declarada)

- Formato de entornos confirmado contra docs oficiales de Bruno (webfetch §3.2); la **versión de Bruno del usuario** no se verificó — `extends` requiere ≥4.2.0 (no se usa en la recomendación).
- `backend/rutas.md` está desactualizado (2026-07-10) y no se usó como fuente; la autoridad es `api.php`.