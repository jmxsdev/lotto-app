```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:a35b194d30fffc135afd91fb21a4ffe45349f91e2b9615eb67973247557f50de
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 13/13
test_command: find collections -name "*.yml" -print0 | xargs -0 -n1 pnpm dlx js-yaml
test_exit_code: 0
test_output_hash: sha256:b631389c6443ce021169041bff6bccdaf274044dfaa587e758b81f7f17e588a6
build_command: python3 -c "import glob,re;fs=[f for f in glob.glob('collections/**/*.yml',recursive=True)];tx=[open(f,encoding='utf-8').read() for f in fs];lb=chr(123);rb=chr(125);q=chr(34);sq=chr(39);u=re.compile('url: '+q+lb+lb+'baseUrl'+rb+rb+'/api/(?!v1/)'+q);h=re.compile('url:[ \t]*https?://');i=re.compile(lb+'id'+rb);e=re.compile('lotto-api-6nrc');s=re.compile(r'bru\.setVar\('+sq+'token'+sq+r'\)');m=re.compile('d8:f3:bc:65:d1:c5');x=re.compile(q+'fraccion'+q+'|'+q+'limite_tiempo'+q+'|3d02b41a');n1=sum(1 for t in tx if u.search(t));n2=sum(1 for t in tx if h.search(t));n3=sum(1 for t in tx if i.search(t));n4=sum(1 for t in tx if e.search(t));n5=sum(1 for t in tx if 'bru.setEnvVar('+sq+'token'+sq in t);n6=sum(1 for t in tx if s.search(t));n7=sum(1 for t in tx if m.search(t));n8=sum(1 for t in tx if x.search(t));n9=len([f for f in fs if not f.endswith('folder.yml') and not f.endswith('opencollection.yml') and '/environments/' not in f]);print('invalid-urls=%d hardcode=%d idlit=%d render=%d setenv=%d setvar=%d mac=%d secrets=%d requests=%d'%(n1,n2,n3,n4,n5,n6,n7,n8,n9))"
build_exit_code: 0
build_output_hash: sha256:98971e1937d4103ec35c7f9eeb5431cdfee29c29f02f6a1871f9708ed59757dd
```

## Verification Report

**Change**: colecciones-api
**Version**: spec v1 (openspec/changes/colecciones-api/specs/colecciones-api/spec.md)
**Mode**: Standard (sin runner para `collections/`; verificación estática + gate humano Bruno UI — sin `bru` CLI en el entorno)
**Branch**: `feat/colecciones-api` (worktree exclusivo `/home/gzuz/Documentos/lotto-app-worktrees/colecciones-api`)
**Commits verificados**: `cb99fac` (F1), `853b043` (F2.1), `8f25c20` (F2.2), `7ecce22` (F2.3) + docs SDD `36ac449`…`850afcb`

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total (tasks.md) | 32 (1.1–1.17, 2.1–2.3, 3.1–3.6, 4.1–4.6) |
| Tasks complete | 32 |
| Tasks incomplete | 0 |

> Nota: el apply-progress (Engram #485) los agrupa como "18/18"; el conteo real de checkboxes `[x]` en tasks.md es 32/32, con 0 pendientes. Sin tareas incompletas → verificación completa habilitada.

### Build & Tests Execution

**Build (barrido de invariantes de cierre F2, comando declarado en el envelope)**: ✅ Passed — exit 0, salida:

```text
invalid-urls=0 hardcode=0 idlit=0 render=0 setenv=5 setvar=0 mac=1 secrets=0 requests=109
```

Cada invariante del tasks.md §Verificación por WU, con su comando rg y resultado (evidencia detallada, ejecutada en este worktree):

```text
$ rg -n --pcre2 'url: "\{\{baseUrl\}\}/api/(?!v1/)' collections   → (vacío)  ✅  invalid-urls=0
$ rg -n 'url:\s*https?://' collections                            → (vacío)  ✅  hardcode=0
$ rg -n '\{id\}' collections                                      → (vacío)  ✅  idlit=0
$ rg -n 'lotto-api-6nrc' collections                              → (vacío)  ✅  render=0
$ rg -c "bru\.setEnvVar\('token'" collections                    → 5 líneas  ✅  setenv=5
$ rg -n "bru\.setVar\('token'" collections                        → (vacío)  ✅  setvar=0
$ rg -n 'd8:f3:bc:65:d1:c5' collections                           → solo collections/environments/Local.yml:14  ✅  mac=1
$ rg -n '"fraccion"|"limite_tiempo"|3d02b41a' collections         → (vacío)  ✅  secrets=0
$ find collections -name '*.yml' ! -name 'folder.yml' ! -name 'opencollection.yml' | grep -v /environments/ | wc -l
→ 109  ✅  requests=109 (desviación documentada: 108 cobertura + 1 payload tripleta F2.3)
```

**Tests (parseo OpenCollection de los 133 YAML)**: ✅ 133/133 parsean — exit 0

```text
$ find collections -name "*.yml" -print0 | xargs -0 -n1 pnpm dlx js-yaml
exit=0 · stderr vacío · salida = dump JSON concatenado de los 133 YAML (98,813 bytes)
```

**Coverage**: ➖ No aplica (artefacto YAML sin suite de tests unitarios; la verificación objetiva es parseo + invariantes + tabla de cobertura + gate manual, según design §Estrategia de verificación:377).

### Spec Compliance Matrix

| Requirement | Scenario | Evidencia | Resultado |
|-------------|----------|-----------|-----------|
| R1 Entornos Local y Produccion | Entorno Local resuelve baseUrl | `environments/Local.yml:4` (baseUrl) + todas las urls `{{baseUrl}}/api/v1/...`; ejecución pendiente | ⚠️ PARTIAL (gate humano) |
| R1 | Conmutación sin editar archivos | `Local.yml`/`Produccion.yml` + `opencollection.yml:29` (`defaultEnvironment: Local`); selector UI pendiente | ⚠️ PARTIAL (gate humano) |
| R2 Convención de URLs | Prefijo versionado | 109/109 urls `{{baseUrl}}/api/v1/...`; invariante 1 vacío | ✅ COMPLIANT |
| R2 | Sin hardcode ni `{id}` literal | Invariantes 2–4 vacíos (`url:\s*https?://`, `{id}`, `lotto-api-6nrc`) | ✅ COMPLIANT |
| R3 Cobertura de endpoints | Endpoints cubiertos | Tabla abajo: 90/90 endpoints de `api.php` con request (27 faltantes resueltos: 2 F1 + 25 F2.2) | ✅ COMPLIANT |
| R3 | Roles documentados | `docs:` en todos los requests nuevos/tocados (p. ej. `Tickets/Crear Ticket.yml:33` sin agencia; `Pagos/Registrar Pago (Token required).yml:28` SM\|M\|B\|G\|T) | ✅ COMPLIANT |
| R4 Contratos de payload y respuesta | Límites sin campos retirados | `Limites/Configurar Limite.yml:17-24` (sin `fraccion`/`limite_tiempo`, `banca_id` presente) vs `JuegoController.php:379-388`; `:32` docs required vs `JuegoController.php:380` | ✅ COMPLIANT |
| R4 | Pago autoritativo | `Pagos/Registrar Pago (Token required).yml:17-22` (egreso sin montos) + `:30` estados; ejecución del flujo pendiente | ⚠️ PARTIAL (gate humano pago autoritativo) |
| R4 | Multi-selección | `Apuestas/Crear Apuesta (Animalitos - Tripleta).yml:19-26` (`combinacion.selecciones[]`, 3 animales) | ✅ COMPLIANT |
| R5 Credenciales y secretos | Sin secretos en git | Invariante 8 vacío; 11 `secret: true` por entorno (`Local.yml`, `Produccion.yml`); credenciales `""`; 0 tokens/hashes reales | ✅ COMPLIANT |
| R5 | Login escribe en entorno activo | `bru.setEnvVar('token', ...)` en 5/5 logins (invariante 5); 0 `bru.setVar('token')` (invariante 6) | ✅ COMPLIANT |
| R6 Calidad del artefacto | YAML válido | Parseo 133/133 `invalid=0` (incluye los 2 YAML reparados en F2.1: Terminales/Triple Zulia) | ✅ COMPLIANT |
| R6 | Flujo ejecutable Local | Invariantes R2/R3 estáticos pasan; ejecución en Bruno UI pendiente | ⚠️ PARTIAL (gate humano) |

**Compliance summary**: 13/13 escenarios con evidencia (9 COMPLIANT + 4 PARTIAL por gate humano) · 0 FAIL · 6/6 requirements con evidencia. Los 4 PARTIAL (R1-S1, R1-S2, R4-S2, R6-S2) no son UNTESTED: tienen evidencia estática completa y su capa runtime es el gate humano configurado en el proyecto (design §Estrategia:377 "no hay suite que ejecute collections/; ... gate manual"); el conteo del envelope (13/13) refleja que la verificación no dejó ningún escenario sin cubrir.

### Cobertura de endpoints (api.php ↔ collections/)

Cruce sistemático contra `backend/routes/api.php` (autoridad de contratos). **90 endpoints** (70 rutas + 5 `apiResource` ×5 = 90).

| Área | Endpoints api.php | Requests colección | Estado |
|---|---|---|---|
| Auth (login/user/logout) | 3 | 8 (5 logins + Get User + Logout) | ✅ |
| Activación / Dispositivo | 2 | 2 | ✅ |
| Usuarios (apiResource) | 5 | 5 | ✅ |
| Bancas (apiResource + toggle) | 6 | 6 | ✅ |
| Grupos (apiResource + toggle) | 6 | 6 | ✅ |
| Agencias (apiResource + toggle) | 6 | 6 | ✅ (carpeta nueva) |
| Taquillas (apiResource + toggle) | 6 | 6 | ✅ |
| Configuraciones | 2 | 2 | ✅ (carpeta nueva) |
| Juegos (index/show/update/toggle/opciones/horarios/reglas) | 7 | 13 (3×opciones, 3×horarios, 3×reglas) | ✅ |
| Resultados (index/show/apariciones/scrape/scrape-all) | 5 | 6 | ✅ |
| Apuestas (index/store/historial/resumen/show/destroy) | 6 | 9 | ✅ |
| Logs | 1 | 1 | ✅ (carpeta nueva) |
| Tickets (index/store/ganadores/show/destroy) | 5 | 5 | ✅ (carpeta nueva) |
| Pagos (store/showByApuesta) | 2 | 4 | ✅ |
| Cierre (store/index/actual/semanal/show) | 5 | 6 | ✅ |
| Límites (matriz/por-juego/put/delete/batch) | 5 | 5 | ✅ |
| Reportes (ventas/cuadre/relacion/vencidos) | 4 | 4 | ✅ |
| Estadísticas (rendimiento) | 1 | 1 | ✅ |
| Tasas de cambio (6 rutas) | 6 | 7 | ✅ |
| Releases (latest/download/serve/update-check) | 4 | 4 | ✅ (carpeta nueva) |
| **Total** | **90** | **109** | **90/90 cubiertos — 0 faltantes** |

**27 endpoints faltantes resueltos**:
- F1 (2): `GET /limites` matriz → `Limites/Listar Limites (Matriz).yml`; `DELETE /limites/{limite}` → `Limites/Eliminar Limite.yml`.
- F2.2a (18): Agencias 6 (`Listar/Crear/Ver/Actualizar/Toggle/Eliminar Agencia.yml`), Tickets 5 (`Listar/Crear/Ganadores/Ver/Anular Ticket.yml`), Configuraciones 2 (`Ver/Actualizar Vencimiento.yml`), Logs 1 (`Listar Logs.yml`), Releases 4 (`Ultima Release/Descargar Instalador/Servir Instalador/Update Check.yml`).
- F2.2b (7): `Bancas/Toggle Banca.yml`, `Grupos/Toggle Grupo.yml`, `Taquillas/Toggle Taquilla.yml`, `Cierre de Caja/Ver Cierre Actual.yml`, `Cierre de Caja/Ver Clave de Cierre.yml`, `Resultados/Ver Apariciones.yml`, `Reportes/Cuadre de Caja.yml`.
- F2.3 (+1 payload, no endpoint): `Apuestas/Crear Apuesta (Animalitos - Tripleta).yml` → 109 total (desviación documentada en apply-progress #485 y tasks.md).

**Extras (duplicados de conveniencia, pre-existentes)**: 5 logins = 1 endpoint; 5 `Crear Apuesta` + 2 casos 422; 3 scrapers; 3 `Opciones`/`Horarios`/`Reglas` por juego; 3 `Registrar Pago`.

### Correctness (contratos — spot-check contra controladores, `archivo:línea`)

| Área | Evidencia colección | Autoridad (backend) | Status |
|---|---|---|---|
| Límites: sin dormidos, `banca_id` required | `Configurar Limite.yml:17-24,32`; batch legacy+scope `Configurar Limite (Batch).yml:46-49` | `JuegoController.php:379-388` (sin fraccion/limite_tiempo), `:380` (`banca_id` required), `:455-482` (scope: ítems sin entidades, singular exige id, 422) | ✅ |
| Límites: matriz XOR, DELETE | `Listar Limites (Matriz).yml:27-33`; `Eliminar Limite.yml:8,16-21` | `JuegoController.php:291-293` (exactamente un filtro), `:277-278` (bancas sin raíz), `:679-698` (destroy SM\|M\|B, 200 message) | ✅ |
| Pagos: egreso sin montos + estados pagables | `Registrar Pago (Token required).yml:17-22,30-33` | `PagoController.php:30-31` (amounts nullable), `:76` (`pendiente|ganadora`), `:102-128` (motor autoritativo, ±0.01), `:201-207` (respuesta `premio`) | ✅ |
| Apuestas/Tickets: `selecciones[]` | `Crear Apuesta (Animalitos - Tripleta).yml:19-26`; `Crear Ticket.yml:17-27` (`lines[]`) | `TicketController.php:105-109` (solo taquilla 403), `:111-118` (validación lines); api.php:166 (roles sin agencia) | ✅ |
| Carpetas nuevas vs controladores | `Crear Agencia.yml:17-28` (name/code/grupo_id/active + opcionales); `Actualizar Vencimiento.yml:18` (`horas: 24`); `Listar Logs.yml` (`per_page=50`); `Releases/*` (4) | `ConfiguracionController.php:28` (`horas` 1..8760); `api.php:158-161` (logs per_page 50); `ReleaseController.php:19-98` (latest panel, download firmada 5 min, serve público signed, update-check público) | ✅ |
| Auth: `X-Panel` / fingerprint | `Login.yml:12-13` (`X-Panel: "true"`); `Login (Taquilla).yml:9-11` (sin X-Panel) | `AuthController.php:53-61` (X-Panel exacto `=== 'true'`; sin X-Panel → solo taquilla, 403 si no), `:38-41` (fingerprint taquilla obligatorio) | ✅ |
| Entornos (formato OpenCollection) | `environments/Local.yml` (baseUrl `http://localhost:8000`, MAC `d8:f3:bc:65:d1:c5` solo Local, token `""` secret, 11 `secret: true`); `Produccion.yml` (baseUrl `https://lotto.gzuz.dev`, MAC vacío); `opencollection.yml:21-25` (headers root X-Device-MAC/Fingerprint), `:29` (`defaultEnvironment: Local`), sin `baseUrlWeb` | Formato oficial Bruno: `name` + `variables[]` con `name/value/enabled/secret/description` (design §Entornos:47) | ✅ |
| Juegos/reglas con `premios` | `Reglas (Animalitos).yml:19-20` | `JuegoController.php:148` (`modalidades` filtradas), `:153` (`premios` del motor) | ✅ |

### Coherence (Design)

| Decisión (design.md) | Seguida? | Evidencia |
|---|---|---|
| A — Convención host pelado + `/api/v1/` | ✅ | 109/109 urls `{{baseUrl}}/api/v1/...`; invariante 1 vacío |
| B — Entornos estándar + `defaultEnvironment: Local` | ✅ | `environments/Local.yml` + `Produccion.yml`; `opencollection.yml:29` |
| C — Credenciales per-rol vacías `secret: true` | ✅ | 10 variables por rol en cada entorno, todas `""` + `secret: true` |
| D — MAC real solo en Local; Prod vacío | ✅ | `d8:f3:bc:65:d1:c5` solo `Local.yml:14`; `Produccion.yml:13-17` vacío |
| E — Headers de dispositivo en root | ✅ | `opencollection.yml:21-25`; 0 headers por-request (grep `name: X-Device` vacío) — piloto de herencia pendiente (gate F1) |
| F — `bru.setEnvVar` + `auth: none` + X-Panel | ✅ | 5/5 logins `setEnvVar`; `auth: none`; X-Panel en 4 de panel, ausente en taquilla |
| G — Batch legacy en body + scope en docs | ✅ | `Configurar Limite (Batch).yml:17-38` (legacy) + `:47-49` (scope opt-in) |
| H — Hash del WIP descartado | ✅ | Invariante `3d02b41a` vacío |
| I — Cobertura 27 (2 F1 + 25 F2.2) | ✅ | Tabla de cobertura: 27 requests nuevos presentes |
| J — Script temporal `/v1` no commiteado | ✅ | Invariante post `api/(?!v1/)` vacío; diff de 68 archivos revisado en F2.1 |
| K — Clave-cierre en carpeta Cierre de Caja | ✅ | `Ver Clave de Cierre.yml` (GET) + `Configurar Clave de Cierre (Token required).yml` (PUT) |

### Issues Found

**CRITICAL**: None

**WARNING**:
1. **Gate humano Bruno UI pendiente** — 4 escenarios quedan PARTIAL (R1-S1, R1-S2, R4-S2, R6-S2). No hay `bru` CLI en el entorno; el flujo ejecutable (login Local, requests de panel, piloto taquilla con MAC, pago autoritativo, Producción con tasa pública) debe ejecutarse en la UI de Bruno antes de `sdd-archive` (ver Gate humano abajo). Es un pendiente documentado del entorno, no un defecto del artefacto.

**SUGGESTION**:
1. `Releases/Servir Instalador.yml:10-11` envía `Authorization: Bearer {{token}}` en un endpoint público (`signed:relative`, sin auth Sanctum — `ReleaseController.php:66-79`). El header es ignorado por el middleware, pero omitirlo evitaría confusión sobre el esquema de auth.
2. `password123` como valor de ejemplo en `Usuarios (Solo Super Master y Master)/Crear Usuario.yml:20` y `Taquillas (Super Master, Master, Banca, Grupo)/Crear Taquilla.yml:24` (`user_password`). Es un placeholder demo (convención pre-existente en la base `d2f8261`), no un secreto real — los invariantes R5 pasan. Opcional: migrar a variable `{{...}}` como los logins.
3. `folder.yml` con `seq` duplicados pre-existentes (Auth/Activacion 1/2, Usuarios 2, Bancas/Grupos 3, Juegos/Pagos 6, Dispositivo/Tasas 8) — cosmético y no introducido por este cambio; las 5 carpetas nuevas (15–19) tienen `seq` único.
4. Discrepancia de conteo administrativo: apply-progress registra "18/18 tareas" vs 32 checkboxes `[x]` reales en tasks.md — estado completo en ambos casos (0 pendientes).

### Gate humano (QA pendiente — Bruno UI, pasos concretos)

Sin `bru` CLI → la parte ejecutable es gate humano. Pasos (tasks.md:116-118 y design §Estrategia:375):

1. **Login Local**: seleccionar entorno `Local` (ya es el default); rellenar `superEmail`/`superPassword`; ejecutar `Auth/Login` → **200** + `token` en el entorno activo (`bru.setEnvVar`).
2. **Requests de panel**: `Juegos/Listar Juegos`, `Limites/Listar Limites por Juego`, `Cierre de Caja/Listar Cierres` → **200** sin editar archivos (valida R1-S1/R2/R3 en runtime).
3. **Piloto taquilla (herencia de headers root, riesgo D/E)**: `Auth/Login (Taquilla)` con MAC `d8:f3:bc:65:d1:c5` registrada en `taquillas.mac_address` (BD local, en MAYÚSCULAS) + `Juegos/Listar Juegos` → **200** (prueba que `X-Device-MAC`/`X-Device-Fingerprint` del root llegan a la request; si falla → aplicar fallback per-request documentado en design D/E).
4. **Pago autoritativo (R4-S2)**: `Pagos/Registrar Pago (Token required)` sobre una apuesta `pendiente|ganadora` → **201** con `premio` (egreso sin montos).
5. **Producción (conmutación R1-S2)**: seleccionar `Produccion` y ejecutar `Tasas de Cambio/Obtener Tasa Activa (sin token, pública)` → **200** (endpoint público, sin credenciales).

### Verdict

**PASS WITH WARNINGS** — Todos los invariantes estáticos de cierre F2 pasan (`invalid=0`, 9 invariantes rg, 90/90 endpoints cubiertos, contratos fieles a controladores, sin secretos); 0 CRITICAL, 0 FAIL. 4/13 escenarios (R1-S1, R1-S2, R4-S2, R6-S2) quedan PARTIAL únicamente por el gate humano Bruno UI, pendiente de ejecutar en la UI antes de archivar.