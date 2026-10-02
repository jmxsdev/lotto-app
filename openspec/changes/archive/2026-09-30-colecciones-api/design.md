# Design: Modernización de colecciones de API (`colecciones-api`)

> Fase: sdd-design · Base: `d2f8261` · Formato: OpenCollection 1.0.0 (colecciones Bruno en `collections/`)
> Depende de: `proposal.md` (D1–D4), `specs/colecciones-api/spec.md` (R1–R6), `explore.md` (§2–§8).
> Scope: solo `collections/` (YAML). Sin cambios en `backend/`, panel ni taquilla.

## Enfoque técnico

La colección pasa de "URLs a mano y sin entornos" a un artefacto **conmutable por entorno**: los valores de host/credenciales viven en `collections/environments/` (formato estándar OpenCollection), el root declara `extensions.bruno.defaultEnvironment: Local`, y toda request usa `{{baseUrl}}/api/v1/...` con `baseUrl` de host pelado (D2 del proposal). Los headers de dispositivo (`X-Device-MAC` / `X-Device-Fingerprint`) se declaran una sola vez en `request.headers` del root (`RequestDefaults`, soportado por el schema OpenCollection 1.0.0) y las requests dejan de duplicarlos. La autoridad de contratos es `backend/routes/api.php` (70 líneas de ruta + 5 `apiResource` = **90 endpoints**) y sus controladores, no los tests.

Cifras de partida verificadas en el worktree: **81 requests**, 16 carpetas, **77** URLs sin `/v1`, **1** hardcodeada (`Juegos/Ver Juego.yml`, que además apuntaba al endpoint de lista y no a `show`), **1** `{id}` literal, **0** entornos, **2** YAML inválidos (duplicados de clave en Apuestas), **63 endpoints** cubiertos con 18 requests duplicadas (5 logins = 1 endpoint, etc.) → **27 endpoints faltantes**.

## Decisiones de arquitectura

| # | Decisión | Opciones | Elección | Razón |
|---|---|---|---|---|
| A | Convención de URLs | A: host pelado + `/api/v1/` en path · B: `baseUrl` con `/api/v1/` incluido | **A** (propuesta D2) | Auto-descriptiva, versionado visible, sin riesgo de `//`; el WIP de opción B del checkout principal no se integra |
| B | Entornos | A: carpeta `environments/` estándar + `defaultEnvironment` · C: variables en root | **A** | Selección desde la UI y CLI; el root queda sin host; `defaultEnvironment: Local` evita llamar a prod por accidente |
| C | Variables de credenciales | Per-rol (10) · panel+taquilla (4) · única (2) | **Per-rol** (`superEmail/superPassword`, `master…`, `banca…`, `grupo…`, `taquilla…`) | Los 5 logins existen por rol; con variables compartidas `Login (Master)` puede autenticar silenciosamente como otro rol. Vacías + `secret: true` (D3/R4); vacío falla ruidoso (401), no en silencio |
| D | `macAddress` / `deviceFingerprint` | Conservar valores reales · vaciar todo | **`macAddress=d8:f3:bc:65:d1:c5` solo en `Local`**; `Produccion` vacío; `deviceFingerprint` vacío en ambos | Una MAC no es secreto y ya está commiteada; conservarla mantiene Local ejecutable para el dev actual. Prod es device-specific: nunca reutilizar la MAC de desarrollo. `deviceFingerprint` nunca existió en git: vacío + descripción |
| E | Headers de dispositivo (`X-Device-MAC`/`X-Device-Fingerprint`) | Root (`request.headers`) · por request | **Root** | `verify.mac` exige ambos a la taquilla en TODO endpoint autenticado; el root evita 31+ headers duplicados y requests futuras sin headers. Riesgo de herencia → piloto en el gate F1; fallback documentado (per-request) |
| F | Script de login | `bru.setVar` · `bru.setEnvVar` | **`bru.setEnvVar('token', …)` en los 5 logins**; `auth: none` en los 5; `X-Panel: "true"` entre comillas (string, schema) en los 4 de panel | `setVar` es runtime (no persiste en el entorno activo); R4 exige entorno. `X-Panel` sin comillas es boolean YAML → schema exige string en `value`. Taquilla NO envía `X-Panel` (AuthController: 403 si lo envía) |
| G | Batch de límites | Body con modo `scope` · body legacy + docs | **Legacy en el body + ejemplos `scope` en `docs:`** | Ejecutar un batch con `scope` escribe en todas las entidades visibles (footgun aun en local); el ejemplo queda documentado y opt-in |
| H | Secreto del WIP | Integrar hash de password · descartar | **Descartar** (ya decidido en D3) | `3d02b41a…` corresponde a una cuenta inexistente; prohibido en git. Invariante de grep en verificación |
| I | Cobertura | 26 faltantes de explore · agregar `GET /usuarios/clave-cierre` | **27: 2 en F1 (matriz GET, DELETE) + 25 en F2** | `GET /usuarios/clave-cierre` solo existía documentado dentro del bloque `docs:` del PUT; la spec R3 exige colección por endpoint |
| J | Método para `/v1` | Edición manual 77 archivos · script de reescritura | **Script temporal `rg --pcre2` + `sed`**, no commiteado, con invariantes post | Mecánico y auditable; el diff se revisa con `git diff`. Riesgo de overreach mitigado por filtro PCRE y verificación estática |
| K | Ubicación del GET clave-cierre | Carpeta `Usuarios` · carpeta `Cierre de Caja` | **`Cierre de Caja`** (junto al PUT existente) | El dominio del archivo ya es cierre de caja; mantiene la simetría GET/PUT en la misma carpeta |

### Flujo de datos (resolución de variables)

```
Bruno UI selector ──► entorno activo (Local | Produccion)
                          │  baseUrl, token, macAddress, deviceFingerprint, credenciales
                          ▼
request.yml ──► url "{{baseUrl}}/api/v1/..."      (host pelado + prefijo visible)
            ├─► request.auth (root) bearer "{{token}}"
            ├─► request.headers (root) X-Device-MAC "{{macAddress}}"
            │                          X-Device-Fingerprint "{{deviceFingerprint}}"
            └─► http.auth: inherit / none

Login (after-response) ──► bru.setEnvVar('token', response.token) ──► entorno activo
                          (la siguiente request usa Bearer {{token}} sin editar archivos)
```

## Entornos — estructura exacta

Nuevos archivos (carpeta `collections/environments/`, un YAML por entorno, formato oficial: `name` + `variables[]` con `name/value/enabled/secret/description`):

### `collections/environments/Local.yml`

```yaml
name: Local
variables:
  - name: baseUrl
    value: http://localhost:8000
    enabled: true
    secret: false
    description: Backend Laravel local (php artisan serve)
  - name: token
    value: ""
    enabled: true
    secret: true
    description: Bearer token; se rellena al ejecutar Auth/Login (bru.setEnvVar)
  - name: macAddress
    value: d8:f3:bc:65:d1:c5
    enabled: true
    secret: false
    description: MAC de una taquilla activada en la BD local (debe coincidir con taquillas.mac_address)
  - name: deviceFingerprint
    value: ""
    enabled: true
    secret: false
    description: Huella del dispositivo (X-Device-Fingerprint); obligatoria para login y uso de taquilla
  - name: superEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (rol super_master)
  - name: superPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (rol super_master)
  - name: masterEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Master)
  - name: masterPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Master)
  - name: bancaEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Banca)
  - name: bancaPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Banca)
  - name: grupoEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Grupo)
  - name: grupoPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Grupo)
  - name: taquillaEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Taquilla)
  - name: taquillaPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Taquilla)
```

### `collections/environments/Produccion.yml`

```yaml
name: Produccion
variables:
  - name: baseUrl
    value: https://lotto.gzuz.dev
    enabled: true
    secret: false
    description: API de producción (VPS, Caddy → FrankenPHP)
  - name: token
    value: ""
    enabled: true
    secret: true
    description: Bearer token; se rellena al ejecutar Auth/Login (bru.setEnvVar)
  - name: macAddress
    value: ""
    enabled: true
    secret: false
    description: MAC de la taquilla productiva (solo flujos de taquilla; nunca la MAC de desarrollo)
  - name: deviceFingerprint
    value: ""
    enabled: true
    secret: false
    description: Huella del dispositivo productivo (solo flujos de taquilla)
  - name: superEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (rol super_master)
  - name: superPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (rol super_master)
  - name: masterEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Master)
  - name: masterPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Master)
  - name: bancaEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Banca)
  - name: bancaPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Banca)
  - name: grupoEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Grupo)
  - name: grupoPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Grupo)
  - name: taquillaEmail
    value: ""
    enabled: true
    secret: true
    description: Credencial de Auth/Login (Taquilla)
  - name: taquillaPassword
    value: ""
    enabled: true
    secret: true
    description: Password de Auth/Login (Taquilla)
```

**Gotcha de `macAddress`**: `VerifyMac` normaliza el header a MAYÚSCULAS y compara estricto (`!==`) contra `taquillas.mac_address`; la BD debe almacenar la MAC en mayúsculas. El valor del entorno puede ir en minúsculas (se normaliza). Documentarlo en la `description` al aplicar.

### `collections/opencollection.yml` final

```yaml
opencollection: 1.0.0

info:
  name: LottoApp API - 1
config:
  proxy:
    inherit: true
    config:
      protocol: http
      hostname: ""
      port: ""
      auth:
        username: ""
        password: ""
      bypassProxy: ""

request:
  auth:
    type: bearer
    token: "{{token}}"
  headers:
    - name: X-Device-MAC
      value: "{{macAddress}}"
    - name: X-Device-Fingerprint
      value: "{{deviceFingerprint}}"
bundled: false
extensions:
  bruno:
    defaultEnvironment: Local
    ignore:
      - node_modules
      - .git
```

Cambios: se elimina `baseUrlWeb` (Render = legado) y todo `request.variables` (ahora en entornos); se añaden los headers de dispositivo y `defaultEnvironment: Local` (clave exacta confirmada en docs Bruno: `extensions.bruno.defaultEnvironment`).

## Login — forma exacta

Los **5** logins aplican el mismo patrón (todos escriben `token` en el entorno activo):

```js
const response = res.getBody();
if (res.getStatus() === 200 && response.token) {
    bru.setEnvVar('token', response.token);
    console.log('Token guardado en el entorno activo');
}
```

`Login.yml` conserva además su `test('Login exitoso', …)`. Se elimina el token del `console.log` (higiene de secretos). Headers/bodies por flujo:

| Archivo | `auth` | Headers | Body |
|---|---|---|---|
| `Auth/Login.yml` | `none` | `Content-Type` + `X-Panel: "true"` | `{"email":"{{superEmail}}","password":"{{superPassword}}"}` |
| `Auth/Login (Master).yml` | `none` | `Content-Type` + `X-Panel: "true"` | `{"email":"{{masterEmail}}","password":"{{masterPassword}}"}` |
| `Auth/Login (Banca).yml` | `none` | `Content-Type` + `X-Panel: "true"` | `{"email":"{{bancaEmail}}","password":"{{bancaPassword}}"}` |
| `Auth/Login (Grupo).yml` | `none` | `Content-Type` + `X-Panel: "true"` | `{"email":"{{grupoEmail}}","password":"{{grupoPassword}}"}` |
| `Auth/Login (Taquilla).yml` | `none` | `Content-Type` (sin `X-Panel`) | `{"email":"{{taquillaEmail}}","password":"{{taquillaPassword}}"}` |

El `X-Device-Fingerprint` del login de taquilla lo aporta el root; si el piloto F1 demuestra que la herencia no funciona en la versión de Bruno del usuario, el fallback es declararlo explícito en este archivo (y en el resto de flujos de taquilla).

## Mapa de edición F1 (fundacional)

| Archivo | Acción | Cambio exacto |
|---|---|---|
| `environments/Local.yml` | Crear | YAML de arriba |
| `environments/Produccion.yml` | Crear | YAML de arriba |
| `opencollection.yml` | Modificar | Quitar `baseUrlWeb` y `request.variables`; añadir `request.headers` (2) y `extensions.bruno.defaultEnvironment: Local` |
| `Auth/Login.yml` | Modificar | URL `{{baseUrl}}/api/v1/login`; `auth: none`; body `{{superEmail}}/{{superPassword}}`; `X-Panel: "true"`; script `setEnvVar`; log sin token |
| `Auth/Login (Master).yml` | Modificar | Ídem con `masterEmail/masterPassword` |
| `Auth/Login (Banca).yml` | Modificar | Ídem con `bancaEmail/bancaPassword` |
| `Auth/Login (Grupo).yml` | Modificar | Ídem con `grupoEmail/grupoPassword` |
| `Auth/Login (Taquilla).yml` | Modificar | URL v1; `auth: none`; body `{{taquillaEmail}}/{{taquillaPassword}}`; sin `X-Panel`; script `setEnvVar` |
| `Limites/Listar Limites por Juego.yml` | Modificar | URL `{{baseUrl}}/api/v1/limites/1`; `docs:` (roles SM\|M\|B\|G\|A; herencia por nivel) |
| `Limites/Configurar Limite.yml` | Modificar | URL `{{baseUrl}}/api/v1/limites/1`; body **sin** `fraccion`/`limite_tiempo` (queda `banca_id`, `moneda`, `limite_minimo`, `limite_maximo`, `porcentaje_pago`, `participacion`); `docs:` (201/200 upsert; `banca_id` required; roles SM\|M\|B) |
| `Limites/Configurar Limite (Batch).yml` | Modificar | URL `{{baseUrl}}/api/v1/limites/batch`; body legacy sin `fraccion`/`limite_tiempo`; `docs:` con ejemplo `scope: {"tipo":"bancas"}` (ítems sin entidades; singular exige `id`; guard 500 → 422) |
| `Limites/Listar Limites (Matriz).yml` | Crear | `GET {{baseUrl}}/api/v1/limites?banca_id=1`; param `scope` deshabilitado (`bancas`); `docs:` XOR entidad/scope y respuesta `{data:{juegos,limites,origen}}` / `{…,entidades,mixto}` |
| `Limites/Eliminar Limite.yml` | Crear | `DELETE {{baseUrl}}/api/v1/limites/1` (id de `JuegoLimite`); `docs:` roles SM\|M\|B + jerarquía |
| `Cierre de Caja/Listar Cierres (Token required).yml` | Modificar | URL `{{baseUrl}}/api/v1/cierre` |
| `Cierre de Caja/Ver Cierre (Token required).yml` | Modificar | URL `{{baseUrl}}/api/v1/cierre/1` (reemplaza `{id}` literal) |
| `Cierre de Caja/Crear Cierre (Token required).yml` | Modificar | Eliminar el header `X-Device-MAC` deshabilitado (lo aporta el root) |
| `Juegos/Ver Juego.yml` | Modificar | URL `{{baseUrl}}/api/v1/juegos/1` (deshardcode; apunta a `show`, no a la lista) |

F1 no toca payloads de Apuestas/Pagos/Usuarios/Taquillas ni los ~66 archivos restantes (van a F2).

## Mapa F2 por slices

### F2.1 — `/v1` mecánico + normalización de headers

Método exacto (script temporal, **no** se commitea):

```bash
# 1) Lista exacta de archivos con URL sin /v1 (PCRE negative lookahead)
rg -l --pcre2 'url: "\{\{baseUrl\}\}/api/(?!v1/)' collections --glob '*.yml'
# 2) Reescritura en SOLO esos archivos (sin doble v1), una línea por archivo
rg -l --pcre2 'url: "\{\{baseUrl\}\}/api/(?!v1/)' collections --glob '*.yml' \
  | while IFS= read -r f; do sed -i 's|url: "{{baseUrl}}/api/|url: "{{baseUrl}}/api/v1/|' "$f"; done
# 3) Eliminar bloques por-request de dispositivo (heredan del root):
#    - headers "X-Device-MAC" (2 variantes: con value y sin value)
#    - bloques runtime.variables con macAddress (20 archivos)
#    vía script python3 puntual; corregir los duplicados de clave de las 2 Apuestas
git diff --stat   # revisión manual obligatoria (~66 archivos, 1-4 líneas por archivo)
```

Alcance: 66 archivos con URL sin `/v1` que no toca F1 (**77 − 11**), más la eliminación de 31 headers `X-Device-MAC` y 20 bloques `runtime.variables` (los mismos archivos), incluidos los 2 YAML inválidos (`Crear Apuesta (Terminales)`, `Crear Apuesta (Triple Zulia)`: su bloque malformado desaparece al quitar los headers). Riesgos: sed sobre líneas `url:` dentro de `docs:` (verificado: no existen referencias `{{baseUrl}}` en docs) y archivos con varias líneas `url:` (el filtro PCRE garantiza que todas son sin `v1`).

### F2.2 — Cobertura de endpoints

**Sub-slice 2a — carpetas nuevas** (4 carpetas + `folder.yml` + 13 requests; `seq` 15–19 tras Estadisticas=14):

| Carpeta (seq) | Requests | Payloads/puntos clave (autoridad) |
|---|---|---|
| `Agencias/` (15) | Listar, Crear, Ver, Actualizar, Toggle, Eliminar | store `{name, code, grupo_id, active, rif?, email?, telefono?, direccion?, estado?, municipio?}` (`AgenciaController:65-79`); toggle sin body; rol `agencia` solo lectura de su local (403 store/update/toggle/destroy) |
| `Tickets/` (16) | Listar, Crear, Ganadores, Ver, Anular | store `{lines:[{juego_id, amount_bs?, amount_usd?, combinacion?}], metodo_pago?}` (solo taquilla: 403 sin `taquilla_id`, `TicketController:105`); ganadores `?fecha=YYYY-MM-DD` required; roles SM\|M\|B\|G\|T (**sin agencia**) |
| `Configuraciones/` (17) | Ver Vencimiento, Actualizar Vencimiento | GET → `{horas}` (fallback 24); PUT `{horas: 1..8760}`; roles SM\|M |
| `Logs/` (18) | Listar Logs | `?per_page=50`; roles SM\|M |
| `Releases/` (19) | Última Release, Descargar Instalador, Servir Instalador, Update Check | latest/download requieren token de panel (5 roles, taquilla 403); `serve` público pero exige URL firmada vigente (`expires`+`signature` del response de download) → `docs:` lo explica; `update-check` público |

**Sub-slice 2b — faltantes en carpetas existentes** (12 requests):

| Carpeta | Request nuevo | Detalle |
|---|---|---|
| `Bancas/` | Toggle Banca | `PATCH /api/v1/bancas/1/toggle`, sin body; SM\|M |
| `Grupos/` | Toggle Grupo | `PATCH /api/v1/grupos/1/toggle`; SM\|M\|B |
| `Taquillas/` | Toggle Taquilla | `PATCH /api/v1/taquillas/1/toggle`; SM\|M\|B\|G\|A |
| `Cierre de Caja/` | Ver Cierre Actual | `GET /api/v1/cierre/actual?taquilla_id=1` (admin; rol taquilla omite y usa su propia caja); rol A incluido |
| `Cierre de Caja/` | Ver Clave de Cierre | `GET /api/v1/usuarios/clave-cierre` → `{clave_configurada}`; SM\|M\|B; sin headers de dispositivo (fuera de `verify.mac`) |
| `Resultados/` | Ver Apariciones | `GET /api/v1/resultados/1/apariciones` + param `posicion` deshabilitado (`triple_a|triple_b|triple_c`, requerido en tripletas; cap fijo 5) |
| `Reportes/` | Cuadre de Caja | `GET /api/v1/reportes/cuadre-caja?fecha_desde=&fecha_hasta=&nivel=banca&moneda=bs`; params `nivel` (banca\|grupo\|agencia\|taquilla) y `tipo_juego` |

Tickets + Agencias suman 11 requests ya en 2a; el total de archivos nuevos de cobertura es 30 (25 requests + 5 `folder.yml`). **Este sub-slice excede el presupuesto de 400 líneas** (~13 requests ≈ 500 líneas por sub-slice con `docs:`); `sdd-tasks` debe confirmar la partición 2a/2b (o registrar `size:exception`) antes de `apply`.

### F2.3 — Payloads vigentes + docs de roles/estados

| Área | Cambio |
|---|---|
| Apuestas | Añadir `Crear Apuesta (Animalitos - Tripleta).yml` con `{"juego_id":1,"combinacion":{"modalidad":"tripleta","selecciones":[{"animal":"perro"},{"animal":"gallina"},{"animal":"lobo"}]},"amount_bs":…,"amount_usd":0,"sorteo_hora":…}` (contrato `Animalitos.php:79-134`; exactamente 3 selecciones same-draw). Filtros en `Listar Apuestas`: `fecha_desde`, `fecha_hasta`, `estado`, `juego_id`, `sorteo_hora`; `Historial` + `ticket_code`, `per_page` |
| Pagos | `Registrar Pago (Token required)` pasa a **egreso sin montos** (backend autoritativo aplica el premio del motor): `{"apuesta_id":1,"moneda":"bs","tipo":"egreso","concepto":"Premio Animalitos"}`; los otros 2 archivos quedan como compatibilidad "con montos"; `docs:` estados pagables `pendiente|ganadora` y respuesta `premio` |
| Usuarios | `Crear Usuario`: `agencia_id` (opcional según rol) y rol `agencia`; `Actualizar`: añadir `agencia_id` como ejemplo; `docs:` destroy solo SM\|M |
| Taquillas | `Crear Taquilla`: `agencia_id` **required** (salvo rol agencia que lo deriva; `TaquillaController:85`); `Actualizar`: `agencia_id` en el body; `docs:` `mac_address`/toggle |
| Juegos | `Reglas (×3)`: `docs:` respuesta con `premios` + `modalidades` filtradas (`JuegoController:144-153`) |
| Auth | `docs:` por archivo: panel `X-Panel: "true"` (SM/M/B/G), taquilla sin `X-Panel` + fingerprint; `X-Device-MAC` en taquilla post-login |
| Cierre | `docs:` estados `reclosed`, 422 de clave; `Ver Cierre Actual` preview read-only |
| Docs de roles/estados | Bloque compacto `docs:` en cada request nuevo y tocado: `Roles: … · Autenticación: Sanctum[+verify.mac] · Estados: …`. Convención de 4 líneas; si excede el presupuesto, `sdd-tasks` lo separa como work unit de solo-docs |

## Interfaces / contratos

- **Variables de entorno** (contrato de nombres): `baseUrl`, `token`, `macAddress`, `deviceFingerprint`, `{rol}Email`, `{rol}Password` con `rol ∈ {super, master, banca, grupo, taquilla}`. Toda request MUST referenciar solo estas variables (más `{{token}}` en auth root).
- **Convención de URL**: `url:` de toda request = `{{baseUrl}}/api/v1/<path>` (host pelado, sin hardcode). Excepciones: ninguna; `releases/serve` usa el mismo formato y su firma se explica en `docs:`.
- **Auth por flujo**: root `auth: bearer {{token}}`; `auth: none` en los 5 logins; `X-Panel` solo panel; fingerprint/MAC para taquilla (root).
- **Documentación de request**: `docs:` con `Roles`, `Autenticación`, `Estados/Respuestas` (español neutro).

## Estrategia de verificación

Herramientas verificadas en el entorno: `rg`, `sed`, `python3` (3.14, **sin** PyYAML), `node` v24, `pnpm` 11.25 con red (`pnpm dlx js-yaml` probado: parsea y falla con exit 1 en YAML inválido/duplicado), `php` (sin extensión yaml), `docker`; **no** hay `bru`/Bruno CLI instalado → el flujo Bruno es **gate humano en la UI**, no un runner.

| Capa | Qué prueba | Comando / método |
|---|---|---|
| Estático · parseo | Todos los YAML parsean (falla en claves duplicadas) | `fail=0; while IFS= read -r f; do pnpm dlx js-yaml "$f" >/dev/null 2>/dev/null || { echo "INVALID $f"; fail=1; }; done < <(find collections -name '*.yml'); echo "invalid=$fail"` → `invalid=0` |
| Estático · URL versionada | Cero URLs sin `/v1` | `rg -n --pcre2 'url: "\{\{baseUrl\}\}/api/(?!v1/)' collections` → vacío |
| Estático · sin hardcode | Cero hosts en `url:` (el host solo vive en `environments/`) | `rg -n 'url:\s*https?://' collections` → vacío |
| Estático · sin `{id}` | Cero literales | `rg -n '\{id\}' collections` → vacío |
| Estático · legado | Render eliminado | `rg -n 'lotto-api-6nrc' collections` → vacío |
| Estático · script login | Todos usan `setEnvVar` | `rg -n "bru\.setVar\('token'" collections` → vacío; `rg -c "bru\.setEnvVar\('token'" collections` → 5 |
| Estático · secretos | Sin passwords reales / hash del WIP | `rg -n '"password": "password"|3d02b41a' collections` → vacío; revisión de `git diff` de F1 (credenciales solo como `{{…}}` + `secret: true`) |
| Estático · MAC acotada | La MAC local no se propaga | `rg -n 'd8:f3:bc:65:d1:c5' collections` → solo `environments/Local.yml` |
| Conteo | Endpoints vs `api.php` | `rg -c 'Route::(get\|post\|put\|patch\|delete\|apiResource)\(' backend/routes/api.php` → **70**; `rg -c 'Route::apiResource' …` → **5**; endpoints = 70 − 5 + 5×5 = **90**. Requests: `find collections -name '*.yml' ! -name 'folder.yml' ! -name 'opencollection.yml' | grep -v /environments/ | wc -l` → **81** hoy, **108** al cierre de F2 (81 + 27) |
| Cobertura semántica | Cada endpoint tiene request | Tabla de mapeo (27 faltantes listados arriba) revisada contra `api.php`; no hay invariante automático fiable de path↔archivo, se revisa en el gate |
| Manual (gate humano) | Flujo ejecutable | Bruno UI: (1) seleccionar `Local`; (2) rellenar `superEmail/superPassword` y ejecutar `Auth/Login` → 200 y token en entorno; (3) `Juegos/Listar Juegos`, `Limites/Listar Limites por Juego`, `Cierre de Caja/Listar Cierres` → 200 sin editar archivos; (4) **piloto F1**: login de taquilla con dispositivo registrado + `Juegos/Listar Juegos` → 200 (prueba herencia de headers del root; si falla → fallback per-request); (5) seleccionar `Produccion` y ejecutar `Tasas de Cambio/Obtener Tasa Activa` (pública) → 200 |

Sin tests unitarios posibles para YAML: la verificación objetiva es la combinación de parseo + invariantes grep + tabla de cobertura + gate manual; no se inventan runners (no hay suite que ejecute `collections/`).

## Work units, commits y PRs

Convención del repo: `docs(collections): <descripción>` (historial `1491640`, `5194fc8`). Un commit por work unit, en la rama del slice.

| WU | Commit propuesto | Alcance | PR |
|---|---|---|---|
| F1 | `docs(collections): entornos local/produccion y correcciones de limites, cierre y juegos` | 2 env + opencollection + 5 logins + Limites (3+2) + Cierre (3) + Ver Juego | PR 1 → `main` |
| F2.1 | `docs(collections): prefijo /api/v1 y headers de dispositivo heredados` | 66 archivos URL + limpieza headers/runtime | PR 2 → PR1 |
| F2.2a | `docs(collections): carpetas de agencias, tickets, configuraciones, logs y releases` | 13 requests + 5 folders | PR 3 → PR2 |
| F2.2b | `docs(collections): requests faltantes (toggles, cierre actual, apariciones, cuadre, clave)` | 12 requests | PR 4 → PR3 |
| F2.3 | `docs(collections): payloads vigentes y documentacion de roles/estados` | payloads + docs | PR 5 → PR4 |

Cadena `stacked-to-main` (cada PR apunta al anterior; el último merge a `main`). Guard de revisión (input para `sdd-tasks`): `Decision needed before apply: Yes`; `Chained PRs recommended: Yes`; `400-line budget risk: Medium` (F1 ≈ 300–350; F2.1 ≈ 250–330; F2.2a/2b ≈ 450–600 cada uno; F2.3 ≈ 400+). El número final de slices lo cierra `sdd-tasks` según el forecast real.

## Riesgos y rollback

| Riesgo | Likelihood | Mitigación |
|---|---|---|
| Herencia de `request.headers` del root no opera en la versión de Bruno del usuario | Media | Piloto obligatorio en el gate F1 (login taquilla + request con MAC); fallback: headers por request (documentado, re-scope de F2.1) |
| Script de reescritura toca texto no-URL o duplica `/v1` | Baja | Filtro PCRE + `git diff --stat` obligatorio + invariante post (`api/(?!v1/)` vacío) |
| `macAddress` local no coincide con la BD (case-sensitive tras `strtoupper`) | Media | Descripción en el entorno + gate: la MAC debe existir en `taquillas.mac_address` en mayúsculas; si no, rellenar en el entorno activo |
| Credenciales vacías → falsos negativos en pruebas de rol | Media | `secret: true` con descripciones por rol; vacío falla 401 ruidoso (no autentica como otro rol) |
| Sub-slices 2a/2b exceden 400 líneas | Alta | Chained PRs + forecast en `sdd-tasks`; opción de `size:exception` explícita |
| Fuga del hash del WIP | Baja | D3: no se integra; invariante `rg '3d02b41a'` → vacío; revisión de `git diff` |

**Rollback**: cada PR es revertible con `git revert` independiente. Revertir en orden inverso (F2.3 → F2.1 → F1). F1 revierte juntos entornos + convención + root headers, sin estado intermedio inconsistente; F2.1 depende de F1 y no debe revertirse antes que F1. Sin migración de datos (solo YAML).

## Threat Matrix

`N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.` El cambio solo escribe YAML de colecciones; los scripts de reescritura son temporales, locales y no commiteados.

## Open Questions

- [ ] Confirmar la versión de Bruno del usuario (herencia de `request.headers` del root); el gate F1 lo resuelve empíricamente.
- [ ] Confirmar la partición final de F2.2 en 2a/2b (o `size:exception`) en `sdd-tasks`.
- [ ] `deviceFingerprint` de una taquilla registrada en Local para el piloto (si no hay ninguna, el piloto de herencia se limita a requests de panel y el fallback se activa por defecto).
- [ ] Alcance de los bloques `docs:` de roles/estados: todos los requests vs. solo nuevos/tocados (decisión de presupuesto en `sdd-tasks`).
