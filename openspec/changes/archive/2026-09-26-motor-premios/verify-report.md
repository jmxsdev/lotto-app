```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:6bfa6424e033f71b48f761ee2d9cf6d99e9716c206921e61bac5d572129f86b1
verdict: pass
blockers: 0
critical_findings: 0
requirements: 18/18
scenarios: 29/29
test_command: DB_DATABASE=lotto_test_motor php artisan test
test_exit_code: 0
test_output_hash: sha256:19f287060d32f97e010dea0736ae7a314514f9808e0c36ff45d1b9fb79894b47
build_command: vendor/bin/pint --test
build_exit_code: 0
build_output_hash: sha256:cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2
```

## Verification Report

**Change**: motor-premios
**Version**: spec draft (16 REQ / 23 escenarios motor-premios + 1 REQ / 2 escenarios catalogo-juegos + 1 REQ / 4 escenarios integracion-juego-incremental = **18 REQ / 29 escenarios**; el encargo citaba 24 escenarios, el conteo autoritativo del spec es 29)
**Mode**: Strict TDD
**Worktree**: /home/gzuz/Documentos/lotto-app-worktrees/investigacion-produccion · Rama `feat/motor-premios-f3-estados` @ `6ecce9f88265ecd4a1682de3a79f5ba0cfe3f047`
**Fecha**: 2026-09-26 · **BD evidencia**: `lotto_test_motor` (la `lotto_test` compartida está corrupta por agente concurrente; NO se usó)

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 24 |
| Tasks complete | 24 (1.1–1.17, 2.1–2.2, 3.1–3.5) |
| Tasks incomplete | 0 |

Verificado contra `tasks.md` (todas `[x]`) y el código real (sección Correctness). Tareas sin test propio por naturaleza: 1.4 (contrato `JuegoInterface`, verificado vía tests 1.5–1.7), 1.15 (migraciones, harness runtime en F1c), 3.5 (cubierto por `VerificarGanadoresTest`).

### Build & Tests Execution

**Build**: ✅ Passed — `vendor/bin/pint --test` (exit 0, `{"tool":"pint","result":"passed"}`). NUNCA se ejecutó `pint` sin `--test` (no se mutó ningún archivo).

**Tests (suite completa)**: ✅ 922 passed / 2 skipped / 0 failed — 924 tests, 4201 assertions, 2414580 ms
```text
$ DB_DATABASE=lotto_test_motor composer test
INFO Configuration cache cleared successfully.
The following exception is caused by a process timeout ...
In Process.php line 1205: the process ... exceeded the timeout of 300 seconds.
```
Nota: `composer test` aborta por el timeout de proceso de Composer (300 s, infraestructura, no un fallo de test). Se usó el fallback autorizado por el encargo:
```text
$ DB_DATABASE=lotto_test_motor php artisan test
{"tool":"phpunit","result":"passed","tests":924,"passed":922,"assertions":4201,"duration_ms":2414580,"skipped":2}
```
→ Confirma la corrección del commit `6ecce9f`: los 6 fallos pre-existentes de `LimitesScopedApiTest` (F1c, la-ricachona inactiva → 20 juegos activos) ya NO existen; la suite completa está en verde (0 failed). Antes de `6ecce9f` (obs Engram F3) eran 916 passed + 6 failed; ahora 922 passed = 916+6.

**Regresión enfocada**: ✅ 107/107 passed, 1286 assertions
```text
$ DB_DATABASE=lotto_test_motor php artisan test --filter='MotorPremiosRegresionTest|ModalidadesSingleDrawTest|VerificarGanadoresTest|ConfiguracionServiceTest|ConfiguracionVencimientoTest|VencimientoApuestasTest|LimitesScopedApiTest|JuegosJsonTest'
{"tool":"phpunit","result":"passed","tests":107,"passed":107,"assertions":1286,"duration_ms":389003}
```

**Coverage**: ➖ No disponible — no hay tool de coverage configurada (phpunit.xml sin `<coverage>`, sin xdebug).

### TDD Compliance

Fuente de evidencia: `apply-progress.md` (slices F1a/F1b/F1c/F1d/F2, con tablas "TDD Cycle Evidence") + Engram obs #266 (slice F3, mismo formato; el archivo del change no se actualizó con F3 — el commit `2223d29` solo marcó `tasks.md`).

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | Tablas "TDD Cycle Evidence" en apply-progress (F1a–F2) y obs #266 (F3) |
| All tasks have tests | ✅ | 24/24; 1.4 (contrato) y 1.15 (migraciones) vía verificaciones instrumentadas |
| RED confirmed (tests exist) | ✅ | Los 19 archivos de test del cambio existen en `backend/tests/` (verificado por inventario) |
| GREEN confirmed (tests pass) | ✅ | Suite completa 922 passed / 2 skipped / 0 failed + regresión enfocada 107/107 |
| Triangulation adequate | ✅ | Múltiples casos por comportamiento (ver matriz; p. ej. REQ6 con 3 escenarios y 4+ tests cada uno) |
| Safety Net for modified files | ✅ | Reportado por slice (baselines 36/36, 74/74, 124/124, 61/61); suite completa en verde confirma |

**TDD Compliance**: 6/6 checks passed

### Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit | 155 | 11 | PHPUnit (sin framework de mock externo; mocks Laravel/`app()`) |
| Integration/Feature | 102 | 9 | PHPUnit + RefreshDatabase + fakes (`FakeVencimientoScraper`) |
| E2E | 0 | 0 | No aplica (backend API) |
| **Total (cambio)** | **257** | **20** | |

(Cambio relacionado: 11 unit + 9 feature = 20 archivos; la suite completa corre 924 tests.)

### Changed File Coverage

Coverage analysis skipped — no coverage tool detected.

### Assertion Quality

Auditoría manual sobre los tests del cambio: `ConfiguracionServiceTest`, `VerificarGanadoresTest`, `VencimientoApuestasTest`, `PremiosEngineTest` (140×, redondeo 1.23456→1.23), `ModalidadesSingleDrawTest` (dupleta 0, cruzado 3000), `JuegosJsonTest` (schema + valores), `MotorPremiosRegresionTest` (30 casos con valores esperados distintos). Sin tautologías, sin ghost loops, sin smoke-only en los tests NUEVOS; todas las aserciones verifican comportamiento con valores concretos.

| File | Line | Assertion | Issue | Severity |
|------|------|-----------|-------|----------|
| `tests/Feature/ScrapeResultsJobTest.php` | 147 | `$this->assertTrue(true)` | Smoke idiom "no lanza excepción" en `test_scrape_results_job_returns_early_for_non_scraper_game` — PRE-EXISTENTE (presente en el commit base `d250c20`, antes del cambio; el archivo fue modificado por el cambio solo para añadir los tests de dedupe) | SUGGESTION |

**Assertion quality**: 0 CRITICAL, 0 WARNING, 1 SUGGESTION (pre-existente, fuera del alcance del cambio)

### Quality Metrics

**Linter (Pint)**: ✅ No errors — `vendor/bin/pint --test` exit 0 (proyecto completo)
**Type Checker**: ➖ No disponible — sin phpstan/psalm configurados en composer.json

### Spec Compliance Matrix (motor-premios — 16 REQ / 23 escenarios)

| Req | Escenario | Test que lo cubre (pasó en suite) | Resultado |
|-----|-----------|----------------------------------|-----------|
| REQ1 config-driven | Premio base desde config | `PremiosEngineTest::test_calcular_premio_base_desde_config` + `MotorPremiosRegresionTest` (30 casos por juego) | ✅ COMPLIANT |
| REQ1 config-driven | Multiplicador hardcodeado ausente | `JuegoPluginManagerTest::test_get_multiplicador_devuelve_el_base_desde_config_no_el_del_plugin` + `PremiosEngineTest::test_calcular_fallback_legacy_premio_multiplo_solo_para_base`; static: plugins no hardcodean dinero (`calcularPremio` legacy deprecado, sin call sites productivos) | ✅ COMPLIANT |
| REQ2 acentos | Apuesta acentuada contra resultado sin acento | `TextoTest` (6) + `AnimalitosPluginTest::test_evaluar_acierto_coincide_apuesta_acentuada_contra_resultado_sin_acento` + `MotorPremiosRegresionTest::test_lotto_activo_acento_delfin_30x` | ✅ COMPLIANT |
| REQ3 terminales | Terminal paga 60× | `TerminalesPluginTest::test_calcular_premio_via_manager_engine_paga_60_por_la_clave_numero` + `MotorPremiosRegresionTest::test_terminal_activo_numero_37_60x` | ✅ COMPLIANT |
| REQ4 signos | Signo enviado como label | `TripletasPluginTest::test_evaluar_acierto_triple_c_acepta_signo_label_contra_sigla_del_resultado` + `test_calcular_premio_via_manager_engine_paga_signo_triple_con_label` + `MotorPremiosRegresionTest::test_triple_caliente_triple_c_signo_label_6000x` | ✅ COMPLIANT |
| REQ5 tipo estricto | Número en tipo distinto no paga | `TripletasPluginTest::test_evaluar_acierto_no_paga_numero_en_tipo_distinto_al_apostado` + `MotorPremiosRegresionTest::test_triple_zulia_tipo_estricto_no_paga` | ✅ COMPLIANT |
| REQ6 comodines | Comodín MEGA | `PremiosEngineTest::test_comodin_flag_mega_reemplaza_base` + `MotorPremiosRegresionTest::test_mega_animal_40_comodin_true_40x` + `TicketGanadoresTest::test_ganadores_con_comodin_mega_usa_motor` | ✅ COMPLIANT |
| REQ6 comodines | Palabra PATRONUS acumula | `PremiosEngineTest::test_palabra_patronus_acumula_sobre_figura_normal` + `MotorPremiosRegresionTest::test_monje_figura_42_con_palabra_70x` | ✅ COMPLIANT |
| REQ6 comodines | Patronus 75 con palabra 140× | `PremiosEngineTest::test_patronus_75_con_palabra_acumula_140` (1400.0) + `MotorPremiosRegresionTest::test_monje_patronus_75_con_palabra_140x` | ✅ COMPLIANT |
| REQ7 sin fuente | Juego deshabilitado no liquida | `PremiosEngineTest::test_juego_inactivo_no_liquida` + `ApuestaServiceTest::test_create_apuesta_rechaza_juego_inactivo` + `MotorPremiosRegresionTest::test_la_ricachona_inactiva_no_liquida` + `JuegosJsonTest` (active=false/vendible=false) | ✅ COMPLIANT |
| REQ8 redondeo | Premio con decimales | `PremiosEngineTest::test_calcular_redondea_premio_a_2_decimales` (1.23456→1.23) | ✅ COMPLIANT |
| REQ9 ganadores por sorteo | Ganador solo del sorteo apostado | `TicketGanadoresTest::test_ganador_solo_del_sorteo_apostado` (whereTime) | ✅ COMPLIANT |
| REQ10 pago vs motor | Pago de animal acentuado | `ApuestaTest::test_pago_apuesta_ganadora_aceptado_contra_motor` (+3 de PagoController: legacy pendiente, sin resultado, monto≠motor) | ✅ COMPLIANT |
| REQ11 single-draw | Dupleta fuera de alcance | `ModalidadesSingleDrawTest::test_dupleta_rechazada_en_premio_posible` + `test_dupleta_rechazada_en_liquidacion` | ✅ COMPLIANT |
| REQ11 single-draw | Cruzado con dos selecciones mismo sorteo | `ModalidadesSingleDrawTest::test_cruzado_chance_paga_3000x_con_ambas_puntas` (selecciones[] same-draw) | ✅ COMPLIANT |
| REQ12 premio_posible | premio_posible no nulo | `ApuestaServiceTest::test_create_apuesta_calcula_premio_posible_con_motor` (0→500) + `test_create_apuesta_premio_posible_usa_modalidad_declarada` + `PremiosEngineTest::test_premio_posible_*` (4) | ✅ COMPLIANT |
| REQ13 estados/vencimiento | Transición ganadora → pagada | `VerificarGanadoresTest::test_verificar_ganadores_marca_ganadora_con_resultado_id` + `ApuestaTest::test_pago_apuesta_ganadora_aceptado_contra_motor` (pago → `pagada`) | ✅ COMPLIANT |
| REQ13 estados/vencimiento | Vencimiento tras la ventana configurable | `VencimientoApuestasTest::test_job_vence_apuesta_sin_resultado_tras_la_ventana` + `test_job_respeta_la_ventana_configurada` (48 h) | ✅ COMPLIANT |
| REQ13 estados/vencimiento | El reintento de búsqueda evita el vencimiento | `VencimientoApuestasTest::test_job_catchup_relanza_busqueda_y_evita_el_vencimiento` (catch-up → ganadora, no vencido) | ✅ COMPLIANT |
| REQ13 estados/vencimiento | Solo super_master y master configuran la ventana | `ConfiguracionVencimientoTest::test_get_rechazado_403_para_rol_taquilla` + `test_put_rechazado_403_para_rol_taquilla` + `test_master_puede_leer_y_editar_la_ventana` | ✅ COMPLIANT |
| REQ14 dedupe | Sorteo duplicado evaluado una vez | `ScrapeResultsJobTest::test_job_evalua_una_sola_vez_cada_sorteo_aunque_haya_duplicados` + `test_dedupe_conserva_la_fila_mas_completa_por_sorteo` + `test_dedupe_desempata_por_updated_at_mas_reciente` + `BaseScraperHelpersTest::test_save_results_upserta_y_no_duplica` | ✅ COMPLIANT |
| REQ15 validación con opciones | Validación contra el zoo propio | `JuegoPluginManagerTest::test_validar_apuesta_valida_contra_el_zoo_propio_del_juego` + `test_validar_apuesta_rechaza_animal_ausente_del_zoo_propio_aunque_sea_canonico` | ✅ COMPLIANT |
| REQ16 regresión | Regresión por juego en verde | `MotorPremiosRegresionTest` (30/30: 21 juegos + comodines, acentos, terminal, signo, base) | ✅ COMPLIANT |

**Compliance summary (motor-premios)**: 23/23 escenarios compliant

### Spec Compliance Matrix (deltas)

| Spec delta | Requisito | Escenario | Test que lo cubre | Resultado |
|------------|-----------|-----------|-------------------|-----------|
| catalogo-juegos | Contrato de premiación | Juego exportado con esquema completo | `JuegosJsonTest::test_el_catalogo_generado_coincide_con_el_archivo_commiteado` (schema `premios`+valores por juego, 717 aserciones) | ✅ COMPLIANT |
| catalogo-juegos | Contrato de premiación | Juego deshabilitado excluido de la venta | `JuegosJsonTest` (la-ricachona `active=false`, `vendible=false`, `premios=null`) + `LaRicachonaResultsTest` | ✅ COMPLIANT |
| integracion-juego-incremental | Seeder por juego | Juego registrado y agendado | `ScheduleTimeZoneTest` (2, pre-existente en verde: ScheduleServiceProvider registra `juego_horarios`) + seeders escriben horarios (validado por `JuegosJsonTest`) | ✅ COMPLIANT |
| integracion-juego-incremental | Seeder por juego | Seeder registra el esquema de premios | `PremiosOficialesTest::test_config_para_*` (8) + 6 `*ResultsTest` alineados (40×, 150/6.000, comodines `tipo`) | ✅ COMPLIANT |
| integracion-juego-incremental | Seeder por juego | Scraper parsea y persiste con dedupe | `ScrapeResultsJobTest::test_triple_zulia_scraper_avoids_duplicates` + `BaseScraperHelpersTest::test_save_results_upserta_y_no_duplica` | ✅ COMPLIANT |
| integracion-juego-incremental | Seeder por juego | hora_sorteo normalizada | `BaseScraperHelpersTest::test_normalize_hora_convierte_formato_12h_a_24h` + `test_normalize_hora_trunca_segundos` | ✅ COMPLIANT |

**Compliance summary (deltas)**: 6/6 escenarios compliant — **total 29/29**

### Correctness (Static Evidence)

| Req | Status | Notas |
|-----|--------|-------|
| REQ1 config-driven | ✅ Implementado | `PremiosEngine` lee `config.premios` (`multiplicadorPara`: `modalidades[clave] ?? base`); fallback legacy `premio_multiplo` SOLO para base (D2); plugins sin dinero hardcodeado (`calcularPremio` deprecado, sin call sites productivos — grep confirma 0 usos fuera del manager) |
| REQ2 acentos | ✅ Implementado | `App\Support\Texto::normalizar()` (`Str::ascii`+`mb_strtolower`) usado en validación y liquidación de los 3 plugins |
| REQ3 terminales | ✅ Implementado | `Terminales::evaluarAcierto` contra clave `numero` (fallback `terminal`), padding 2 cifras; base 60 en catálogo |
| REQ4 signos | ✅ Implementado | `Tripletas` acepta label/sigla (`signosLabels`), normalizado; regla `signo` ampliada |
| REQ5 tipo estricto | ✅ Implementado | `aciertoTripleSimple` compara solo contra el tipo apostado, padding 3 cifras |
| REQ6 comodines | ✅ Implementado | Overlay en `PremiosEngine::multiplicadorConComodines`: flag/letra/numero reemplazan (max), palabra suma acumulativa (+20); mapper `patronus` en `AnimalitosScraper` (H14) |
| REQ7 sin fuente | ✅ Implementado | `la-ricachona` `active=false` + plugin inactivo (migración 000002 + seeder); guard en `ApuestaService::createApuesta` y `PremiosEngine::calcular` |
| REQ8 redondeo | ✅ Implementado | `round($monto×mult, 2, PHP_ROUND_HALF_UP)` único en el engine (D7) |
| REQ9 ganadores por sorteo | ✅ Implementado | `TicketController::ganadores`: `whereTime('sorteo_hora')` + `whereIn(estado, pendiente|ganadora)` + motor |
| REQ10 pago vs motor | ✅ Implementado | `PagoController::calcularPremio` → manager → engine; acepta `ganadora` y `pendiente` legacy con resultado (D5) |
| REQ11 single-draw | ✅ Implementado | `modalidadDe`/`evaluarAcierto` ampliados (tripleta, cruzado, par, arrimao, pegadito, posiciones, signo_posición); `selecciones[]` same-draw; Dupleta rechazada en `premioPosible` y liquidación (0) |
| REQ12 premio_posible | ✅ Implementado | `premioPosible()` en `createApuesta` (modalidad declarada o derivada `modalidadDe()`), nunca 0 por resultados vacíos |
| REQ13 estados/vencimiento | ✅ Implementado | ENUM `ganadora` (migración 000001); `verificarGanadores` → ganadora/perdida + `whereNull(resultado_id)`; `ConfiguracionService` (24 h default, min 1 h); `ConfiguracionController` + rutas `role:super_master|master`; `MarcarApuestasVencidasJob` diario 02:00 con catch-up `ScrapeResultsJob::dispatchSync` por par (juego, fecha); cascada de ticket suma `vencido` (D5) |
| REQ14 dedupe | ✅ Implementado | Migración 000003 (dedupe+fusión+hora normalizada, `down` no-op documentado) + `ScrapeResultsJob::dedupeResultadosDelDia` + `BaseScraper::saveResults` upsert con hora normalizada |
| REQ15 validación con opciones | ✅ Implementado | `JuegoPluginManager::validarApuesta` carga `juego_opciones` (fallback `obtenerOpciones`) y las pasa al plugin |
| REQ16 regresión | ✅ Implementado | `MotorPremiosRegresionTest` 30 casos (21 juegos + comodines + acentos + inactivo) |

### Coherence (Design)

| Decisión | ¿Seguida? | Notas |
|----------|-----------|-------|
| D1 motor dedicado + plugins adaptadores | ✅ Sí | `PremiosEngine` única fuente de dinero; `JuegoPluginManager` fachada con la misma firma; 0 call sites productivos al plugin legacy |
| D2 config.premios + espejos legacy | ✅ Sí | `PremiosOficiales::configPara()` escribe `premios` + `premio_multiplo`(=base) + `modalidades`/`comodines` legacy desde la misma fuente; test `config_para_*` fija la coherencia |
| D3 normalización de texto | ✅ Sí | `Texto::normalizar` compartido (D3 exacto) |
| D4 entrega de valores (migración + seeders) | ✅ Sí | Migración 000002 + 21 seeders vía `configPara()`; idempotente y reversible |
| D5 estados y vencimiento | ✅ Sí | ENUM + job + settings `configuraciones` + role-gating + catch-up; deviation documentada: `dispatchSync` para garantía en la misma corrida |
| D6 dedupe | ✅ Sí | Migración (D6-a) + guard job (D6-b) + upsert (D6-c), mismo criterio de superviviente |
| D7 redondeo único | ✅ Sí | `round(x,2)` solo en el engine |
| D8 Fase 2 single-draw, Dupleta fuera | ✅ Sí | `selecciones[]` same-draw sin tablas; Dupleta rechazada por `premioPosible` y liquidación |
| D9 premio_posible al crear | ✅ Sí | `createApuesta` usa `premioPosible()`; no recalcula con resultados vacíos |
| D10 contrato catálogo | ✅ Sí | Export `premios`/`active`/`vendible` aditivo; `JuegoController::reglas` expone `premios` del motor |

Desviaciones registradas en apply-progress (F1c/F1d/F2/F3 + obs #266) — todas documentadas, ninguna rompe spec: guard REQ7 incluye plugin nulo; `PagoController` estado pagable `['pendiente','ganadora']`; triple seco emite clave del tipo apostado (arrejuntado 600×); Táchira T+Z por fallback a base 500×; Tripleta contra `figuras[]` (persistencia scraper futura); `premioPosible` rechaza modalidad declarada no soportada; `Configuracion` fija `$table='configuraciones'`; `dailyAt('02:00')`.

### Issues Found

**CRITICAL**: None

**WARNING**: None

**SUGGESTION**:
1. `ScrapeResultsJobTest::test_scrape_results_job_returns_early_for_non_scraper_game` (línea 147) usa `assertTrue(true)` — patrón smoke pre-existente (commit base `d250c20`), no introducido por el cambio; idealmente reemplazar por una aserción de estado (p. ej. `Resultado::count()` sin cambios).
2. La persistencia de las 3 figuras de Tripleta por los scrapers sigue fuera de alcance (deviation F2 #4): el contrato de liquidación está definido y testeado contra `figuras[]`, pero ningún scraper persiste ese shape hoy.
3. `composer test` excede el timeout de proceso de Composer (300 s) en esta máquina — el pipeline debe usar `php artisan test` directo o ajustar `COMPOSER_PROCESS_TIMEOUT`.

### Verdict

**PASS** — 24/24 tareas completas, suite completa en verde (922 passed / 2 skipped / 0 failed), regresión enfocada 107/107, Pint limpio, 29/29 escenarios del spec con test pasando, motor config-driven en todos los call sites productivos y coherencia `config.premios` ↔ espejos legacy verificada.

### Evidencia de limpieza

```text
$ git status --short   (worktree investigacion-produccion, HEAD 6ecce9f)
?? .codegraph/
```
Solo el `.codegraph/` untracked pre-existente. Nada más modificado: el verify NO mutó código, no ejecutó `pint` sin `--test`, no corrió migraciones fuera de `lotto_test_motor`, no tocó panel/taquilla ni el checkout principal.