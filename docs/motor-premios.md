# Motor de premios — implementación y referencia técnica

> **Estado**: implementado, verificado y archivado (2026-09-26).
> Ciclo SDD: `openspec/changes/archive/2026-09-26-motor-premios/` (proposal, design, 3 specs, tasks 24/24, verify-report PASS).
> Rama: `feat/motor-premios-f3-estados` (42 commits sobre `main`; **pendiente de merge** al momento de escribir este documento).
> Este documento reemplaza la nota de trabajo del 2026-09-14 (describía la arquitectura previa y los gaps, ya resueltos).
> Contrato para los fronts (taquilla/panel): `docs/integracion-front-motor-premios.md`.

## 1. La idea central

**El plugin reconoce la FORMA del acierto; el motor decide el DINERO; `config.premios` guarda los valores oficiales.**

- Antes: cada plugin hardcodeaba su multiplicador (Animalitos 30x, Tripletas 30x, Terminales 20x) y el `premio_multiplo` de la BD no se usaba al liquidar.
- Ahora: `PremiosEngine` lee `config.premios` (`{base, modalidades, comodines}`) del juego y el plugin solo responde *que se aposto vs que salio* (clave canonica + senales de comodin presentes en el resultado).

## 2. Arquitectura por capas

```
Taquilla / Panel
   |  REST /api/v1 (Sanctum + roles)
   v
Controllers: TicketController . PagoController . JuegoController . ConfiguracionController
   |
   v
Services: ApuestaService (venta/liquidacion) . JuegoPluginManager (fachada) . ConfiguracionService
   |
   +--> PremiosEngine .... DINERO (multiplicadores, comodines, redondeo)
   |        | lee
   |        v
   |    Juego.config['premios']  .... valores oficiales (BD)
   |
   +--> Plugins (Animalitos / Tripletas / Terminales) .... FORMA del acierto
            evaluarAcierto() / modalidadDe()   (JuegoInterface)

Catalogo de valores en codigo: App\Support\PremiosOficiales (fuente de seeders y migracion)
```

## 3. Flujo del dinero, paso a paso

| # | Cuando | Quien | Que pasa |
|---|---|---|---|
| 1 | Venta | `POST /api/v1/tickets` -> `TicketController::store` -> `ApuestaService::createApuesta` | guards (juego `active`, opciones del zoo, monedas, costo minimo, limites, sorteo no vencido) y **`PremiosEngine::premioPosible`** -> `detalle_apuestas.premio_posible(_usd)` |
| 2 | Llega el resultado | `ScrapeResultsJob` -> `ApuestaService::verificarGanadores` | por apuesta: **`PremiosEngine::calcular`**; gana -> `estado=ganadora` + `resultado_id` + `premio_ganado`; pierde -> `perdida`. Filtro `whereNull('resultado_id')` = nunca reprocesa |
| 3 | Pago | `POST /api/v1/pagos` -> `PagoController` | valida el monto contra el motor (+-0.01), exige `resultado_id`, `estado in {pendiente, ganadora}` y `moneda`; apuesta -> `pagada`; cascada del ticket |
| 4 | Nunca llega el resultado | `MarcarApuestasVencidasJob` (diario 02:00) | agrupa pendientes sin resultado por (juego, fecha) -> **catch-up** `ScrapeResultsJob::dispatchSync` -> lo que sigue sin resultado tras la ventana (24h default, configurable super_master/master) -> `vencido` |
| 5 | Consulta | `GET /tickets/ganadores` | filtra por `juego / fecha / sorteo_hora / estado`; no declara ganador de un sorteo distinto al apostado |

Ticket: `premio_total_bs/usd` se **acumula** (increment) entre sorteos; estado del ticket se cierra cuando todas sus apuestas estan resueltas (`vencido` cuenta como resuelta, `ganadora` no).

## 4. Inventario de archivos

### 4.1 Nucleo (backend)

| Archivo | Que hace |
|---|---|
| `backend/app/Services/PremiosEngine.php` | El dinero. `calcular()` (liquida), `premioPosible()` (venta), `reglas()` (contrato para /reglas), `multiplicadorPara()`, `multiplicadorConComodines()` (privado), `evaluarAcierto()` / `modalidadDe()` (privados, via manager) |
| `backend/app/Support/PremiosOficiales.php` | Catalogo unico en codigo (21 juegos): `todos()`, `para(slug)`, `slugs()`, `activo(slug)`, `configPara(slug)`; espejos legacy |
| `backend/app/Support/Texto.php` | `normalizar()`: sin acentos + minusculas (H13) |
| `backend/app/Plugins/Contracts/JuegoInterface.php` | Contrato: `evaluarAcierto()`, `modalidadDe()` (nuevos); `calcularPremio()` y `obtenerMultiplicador()` quedan `@deprecated` (legacy pre-motor) |
| `backend/app/Plugins/Juegos/Animalitos.php` | Forma animalitos: acentos, `figuras[]` (Tripleta), `comodinesDelResultado()` (superset de senales) |
| `backend/app/Plugins/Juegos/Tripletas.php` | Forma tripletas: tipo apostado (REQ5), signos label/sigla, posiciones (punta/terminal/una), aproximacion, arrimao/pegadito, `selecciones[]` (Cruzado / Par A+B) |
| `backend/app/Plugins/Juegos/Terminales.php` | Forma terminales: clave `numero` del resultado con padding a 2 cifras (N1/N9) |
| `backend/app/Services/JuegoPluginManager.php` | Fachada: `getPlugin`, `getPluginByJuegoId`, `validarApuesta` (con opciones del zoo), `calcularPremio`, `getOpciones`, `getValidationRules/Messages`, `getMultiplicador` |

### 4.2 Modelos

| Modelo | Rol |
|---|---|
| `backend/app/Models/Juego.php` | `config` (JSON; contiene `premios`), `active`, relacion `pluginJuego` |
| `backend/app/Models/JuegoLimite.php` | Limites por juego x entidad x moneda. Scope **`deJuegosActivos()`**: los limites de juegos inactivos no se exponen en la matriz |
| `backend/app/Models/Apuesta.php` | `estado` ENUM(`pendiente`,`pagada`,`anulada`,`perdida`,`vencido`,`ganadora`), `resultado_id` |
| `backend/app/Models/DetalleApuesta.php` | `premio_posible(_usd)` (venta) y `premio_ganado` (liquidacion) |
| `backend/app/Models/Ticket.php` | `premio_total_bs/usd` acumulado; estados (`pendiente`,`pagada`,`anulada`,`ganador`,`vencido`) |
| `backend/app/Models/Resultado.php` | `numeros_ganadores` (JSON) |
| `backend/app/Models/Configuracion.php` | Clave/valor de settings (`apuestas.vencimiento_sin_resultado`); `$table='configuraciones'` (fix latente) |

### 4.3 Servicios y controladores

| Archivo | Rol |
|---|---|
| `backend/app/Services/ApuestaService.php` | `createApuesta` (guards + `premio_posible`), `verificarGanadores` (motor + estados), limites/monedas/vigencias heredadas, reportes |
| `backend/app/Http/Controllers/Api/TicketController.php` | `store`, `index`, `show`, `destroy` (usa `getEffectiveTiempoEliminacion` real), `ganadores` (filtro por `sorteo_hora`) |
| `backend/app/Http/Controllers/Api/PagoController.php` | Pago validado contra el motor (nunca contra un plugin roto) |
| `backend/app/Http/Controllers/Api/JuegoController.php` | `reglas` (+`premios` aditivo), `update` (config), `toggle`, `updateLimites` / matriz (con `deJuegosActivos()`) |
| `backend/app/Http/Controllers/Api/ConfiguracionController.php` | `GET/PUT /api/v1/configuraciones/apuestas-vencimiento` (role `super_master|master`) |
| `backend/app/Services/ConfiguracionService.php` | `CLAVE_VENCIMIENTO`, `get/set`, `horasVencimientoSinResultado()` (default 24), `setVentanaVencimiento()` |

### 4.4 Jobs y comandos

| Archivo | Rol |
|---|---|
| `backend/app/Jobs/ScrapeResultsJob.php` | Trae resultados (3 reintentos, backoff 300 s) |
| `backend/app/Jobs/MarcarApuestasVencidasJob.php` | Diario 02:00; catch-up `dispatchSync` antes de vencer (`backend/routes/console.php:14`) |
| `backend/app/Jobs/ExpireUnclaimedPrizesJob.php` | Diario 01:00 (premios vencidos; preexistente) |
| `backend/app/Console/Commands/JuegosExportCommand.php` | `php artisan juegos:export` -> regenera `docs/juegos.json` |
| `backend/app/Services/JuegoCatalogoService.php` | Arma el export del catalogo (incluye `premios`, `active`, `vendible`) |

### 4.5 Datos

- Migraciones: `2026_09_17_000001_add_ganadora_to_apuestas_estado`, `2026_09_17_000002_backfill_premios_config_juegos`, `2026_09_17_000003_dedupe_resultados_sorteo_duplicado`.
- Seeders: uno por juego leyendo `PremiosOficiales::configPara($slug)` (`backend/database/seeders/`: `MonjeMillonarioSeeder`, `SelvaPlusSeeder`, `TripleChanceSeeder`, `LaRicachonaSeeder` (off), ...) + `DatabaseSeeder` que orquesta.
- **Vistas**: el motor no tiene vistas Blade; su interfaz es la **API** + `docs/juegos.json`. Las pantallas viven en `panel/` y `taquilla/` -> contrato en `docs/integracion-front-motor-premios.md`.

## 5. `config.premios` — forma exacta

```json
{
  "premios": {
    "base": 600,
    "modalidades": { "cruzado": 3000, "cruzado_10": 10, "punta": 60 },
    "comodines": {
      "patronus-75":      { "tipo": "numero",  "premio_multiplo": 120, "nombre": "Patronus" },
      "patronus-palabra": { "tipo": "palabra", "premio_multiplo": 20, "acumulativo": true, "nombre": "PATRONUS" }
    }
  },
  "premio_multiplo": 600,
  "modalidades": { },
  "comodines": { }
}
```

- Ejemplo real (`triple-chance`): `base 600`; modalidades `triple_a_b 200000`, `solo_a_b 150`, `punta 60`, `terminal 60`, `cruzado 3000`, `cruzado_10 10`, `signo_triple 6000`, `signo_terminal 600`, `signo_solo 6`.
- `premio_multiplo` / `modalidades` / `comodines` de primer nivel son **espejos legacy** (display del panel, contrato previo del front). La fuente de verdad del dinero es `premios`.
- Claves de comodin = las que emite `Animalitos::comodinesDelResultado()`: `mega`, `comodin-a`, `comodin-b`, `guacharito-99`, `guacharo-75`, `patronus-75`, `patronus-palabra`.

## 6. Reglas de calculo (comportamiento exacto)

| Regla | Detalle |
|---|---|
| Base | `multiplicadorPara(clave)` = `modalidades[clave] ?? base`; fallback transicional: si falta `premios.base`, se usa `premio_multiplo` legacy |
| Comodin flag/letra/numero | **Reemplaza** el multiplicador vigente: gana el mayor (`max`) |
| Comodin palabra | **Suma** (+20) si `acumulativo`: figura normal 50+20=70x; Patronus 75 + palabra = 120+20=**140x** |
| Acentos | `Texto::normalizar` en ambos lados ("Delfin" = "Delfin" con acento) |
| Cifras | Padding: 3 cifras triples; 2 terminales/puntas; 4 arrimao; 5 pegadito |
| Signos | Acepta LABEL ("Escorpio") o SIGLA ("ESC"); compara normalizado contra el resultado |
| Terminal Activo | Liquida contra la clave `numero` del resultado (el bug historico leia `terminal` y nunca pagaba) |
| Redondeo | `round(valor, 2, PHP_ROUND_HALF_UP)` por moneda apostada |
| Juego inactivo | `calcular()`/`premioPosible()` -> 0; `createApuesta` rechaza la venta |
| Modalidad declarada desconocida | `premioPosible` -> 0 (p. ej. `dupleta`; fuera de alcance) |
| Tripletas | Paga solo por el tipo apostado (`triple_a` contra `a`, nunca contra `b`) |
| Dedupe | Los resultados duplicados pre-H22 se deduplican antes de liquidar (migracion `...000003`) |

## 7. Modalidades soportadas (vista backend)

Los payloads exactos de `combinacion` para el front estan en `docs/integracion-front-motor-premios.md` (seccion 2).
Resumen de claves canonicas por juego (valores en `config.premios.modalidades`):

| Juego | Base | Claves de modalidad | Comodines |
|---|---|---|---|
| Lotto Activo (familia, 4 juegos) | 30 | (base) | - |
| Terminal Activo / Terminal Trio | 60 | (base terminal) | - |
| Trio Activo | 600 | `terminal` 60, `punta` 60 | - |
| Triple Zulia | 600 | `terminal` 60, `signo_triple` 6000, `signo_terminal` 600 | - |
| Triple Caliente | 600 | `terminal` 60, `signo_triple` 6000, `signo_terminal` 600 | - |
| Triple Zamorano | 600 | `terminal` 60, `una` 5, `signo_triple` 6000, `signo_terminal` 600, `signo_una` 60 | - |
| Triple Tachira | 500 | `terminal` 50, `signo_triple` 5000 | - |
| Triple Chance | 600 | `triple_a_b` 200000, `solo_a_b` 150, `punta` 60, `terminal` 60, `cruzado` 3000, `cruzado_10` 10, `signo_triple` 6000, `signo_terminal` 600, `signo_solo` 6 | - |
| Triple Facil | 700 | `terminal` 60, `aproximacion` 10 | - |
| Cazaloton | 30 | `tripleta` 200 | - |
| Monje Millonario | 50 | - | `patronus-75` 120; `patronus-palabra` +20 acumulativo (70x normal; 140x con Patronus 75) |
| El Arrejuntado | 40 | `triple_a`/`triple_b` 600, `signo_triple` 6000, `arrimao` 6000, `pegadito` 60000 | - |
| El Guacharito | 70 | - | `guacharito-99` 150 |
| Guacharo Activo | 60 | - | `guacharo-75` 120 |
| La Granjita | 30 | - | - |
| Loto Chaima | 40 | `tripleta` 50 | - |
| Mega Animal 40 | 30 | - | `mega` 40 |
| Selva Plus | 80 | - | `comodin-a` 160, `comodin-b` 200 |
| La Ricachona | - | - | `active=false` (sin fuente; no se vende ni liquida) |

## 8. Endpoints del motor

| Metodo | Ruta | Rol | Notas |
|---|---|---|---|
| POST | `/api/v1/tickets` | taquilla | Venta; `premio_posible` se persiste por detalle |
| GET | `/api/v1/tickets/ganadores` | auth | Ganadores filtrados por sorteo (`fecha` opcional) |
| GET | `/api/v1/tickets` / `/{id}` | auth | Consulta de tickets/apuestas |
| DELETE | `/api/v1/tickets/{id}` | auth | Anulacion; backend usa `tiempo_eliminacion` real |
| POST | `/api/v1/pagos` | auth | Pago contra motor: `tipo=egreso`, `moneda`, monto = premio |
| GET | `/api/v1/juegos` | auth | Catalogo desde BD (`config` completo) |
| GET | `/api/v1/juegos/{id}/reglas` | auth | Reglas del plugin + `premios` (aditivo) |
| GET | `/api/v1/juegos/{id}/opciones` | auth | Opciones reales del zoo |
| GET | `/api/v1/juegos/{id}/horarios` | auth | Horarios |
| PUT | `/api/v1/juegos/{id}` | super_master/master | `name` y `config` (**reemplazo completo**, no merge) |
| PATCH | `/api/v1/juegos/{id}/toggle` | super_master/master | Requiere body `{active: bool}` |
| GET/PUT | `/api/v1/configuraciones/apuestas-vencimiento` | super_master/master | `{horas}` entero 1..8760; default 24 |

## 9. Operacion

### 9.1 Cambiar un multiplicador hoy

- Fuente de verdad: `config.premios` (BD) — cambiar `premios.base` y el espejo `premio_multiplo`.
- Hoy NO hay UI en el panel ni endpoint dedicado: `PUT /juegos/{id}` acepta `config` pero **reemplaza el array completo** (mandar solo `premios` borraria `scraper`, espejos, etc.).
- Backlog acordado (Engram #352): `PUT /juegos/{juego}/premios` con merge seguro + sincronizacion de espejos + auditoria. Pertenece al ciclo "configuracion de juegos".
- Fuente canonica en codigo para seeders/migracion: `PremiosOficiales::configPara($slug)`.

### 9.2 Despliegue

- Correr las 3 migraciones (`php artisan migrate --force` en deploy).
- Ejecutar seeders por juego si se regenera el catalogo.
- `php artisan juegos:export` regenera `docs/juegos.json` (lo consume el front).

### 9.3 Pruebas

```bash
cd backend
DB_DATABASE=lotto_test_motor php artisan test \
  --filter='MotorPremiosRegresionTest|ModalidadesSingleDrawTest|VerificarGanadoresTest|ConfiguracionServiceTest|ConfiguracionVencimientoTest|VencimientoApuestasTest|LimitesScopedApiTest|JuegosJsonTest'
```

- Suite completa con `DB_DATABASE=lotto_test_motor php artisan test` (NO `composer test`: excede el timeout de 300 s de Composer).
- Ultimo verify (2026-09-26): **922 passed / 2 skipped / 0 failed** (924 tests, 4201 aserciones); 29/29 escenarios del spec; pint limpio.
- Evidencia del ciclo: `openspec/changes/archive/2026-09-26-motor-premios/verify-report.md`.
