# Archive Report — comisiones

**Fecha de archivo**: 2026-09-30
**Artefacto store**: hybrid (openspec + engram)
**Worktree**: `/home/gzuz/Documentos/lotto-app-worktrees/comisiones` · Rama `feat/comisiones` @ `d6d7406` (base `c375786`)
**Veredicto del verify**: **PASS WITH WARNINGS** (8/8 requirements, 24/24 escenarios; 0 CRITICAL / 0 blockers)
**Merge a main**: **PENDIENTE (autorizado por el usuario)** — el cambio vive en la rama `feat/comisiones` @ `d6d7406` (13 commits), NO merged, NO pusheado. El usuario autorizó el cierre: archivar + integrar `origin/main` en el worktree + PR a main. Este archive no mergea ni hace push (restricción del encargo).

---

## Resumen del cambio

Comisión = participación (%) de un nivel (banca/grupo/taquilla) sobre sus ventas, liquidada como filas del ledger `comisiones` (estado `pendiente`→`pagado`). Implementa: lector de tasa efectiva por cascada `taquilla→grupo→banca→default global` sobre `juego_limites.porcentaje_pago` (espejo de `getEffectiveLimit`); matriz de default global (2 filas bs/usd) en tabla nueva `comision_defaults` editable por `super_master` como bloque aditivo en `limites.astro`; cálculo de comisión bs-equivalente (`apuestas.total_bs_equivalent` con snapshot `exchange_rate_applied`) por rangos genéricos inclusivos, redondeo 2dp, buckets por moneda (D6), exclusión de anuladas; tope acumulado por moneda (D11: `min(tasaEfectiva, max(0, 100 − Σ tasas propias de ancestros))`, piso 0); liquidación en el ledger SOLO para Grupo y Taquilla (banca excluida), congelada al liquidar (no retroactiva), sin doble conteo (transacción + `lockForUpdate`, overlap 422); gating de escritura `manage_comisiones` + `super_master|master` con `master` scoped a `masterBancaIds()`; columna de comisión en `ventasTotales` y desglose en cuadre/cierre (`comision_bs_equivalent` en `cierres_caja`).

## Estado final al cierre (verdict + números)

Fuente: `verify-report.md` (2026-09-30, observación Engram #486, envelope `gentle-ai.verify-result/v1` `verdict: pass_with_warnings`) + hechos de estado final del orquestador en el encargo de archive (reconfirmación de supuestos y autorización de cierre, 2026-09-30, observación #459). Los números que siguen son los del cierre, no los de snapshots intermedios.

| Métrica | Valor final |
|---------|-------------|
| Verdict | `pass_with_warnings` (envelope validado: `requirements 8/8`, `scenarios 24/24`) |
| Blockers | 0 |
| CRITICAL findings | 0 |
| Suite completa (apply 6.2, HEAD `d6d7406`) | **1128 tests / 1126 passed / 2 skipped / 0 failed / 6587 assertions** (`DB_DATABASE=lotto_test_motor php artisan test`, exit 0, ~53.6 min; los 2 skipped son pre-existentes, no del cambio) |
| Evidencia independiente de verify (ventana limpia) | **209/209 passed / 0 failed / 910 assertions** (57 del cambio + 152 de regresión; los 152 reproducen EXACTAMENTE los chunks 6.1: 46/243, 64/394, 42/85) |
| Build | `./vendor/bin/pint --test` exit 0 + `cd panel && pnpm run build` exit 0 (27 páginas) |
| Coverage | No disponible (sin tool de coverage configurada) |
| Spec compliance | 8 REQ / 24 escenarios mapeados — 24/24 COMPLIANT (comisiones 20/20, reportes-agencia 2/2, cierre-caja 2/2) |
| Tasks | 29/29 completas |

**Reconfirmación de supuestos del spec (i–iii) por el usuario — 2026-09-30** (observación Engram #459, cierra el WARNING 1 del verify): (i) el tope acumulado aplica **por moneda** (cada cadena de moneda se topa de forma independiente); (ii) la **banca queda fuera del ledger** (su % actúa solo como retención/tope sobre descendientes); (iii) Σ ancestros = **tasas propias** configuradas de los ancestros (NULL/ausente = 0), piso 0, `min(tasaEfectiva, max(0, 100 − Σ))`. Los tests fijan este comportamiento (S17 per-moneda, S11 banca off-ledger, S18 herencia).

### WARNINGs divulgados (disclosures, ninguno bloqueante)

1. **Caveats D6 (reporte/cierre)**: (a) `moneda=mixto` no es expresable en la columna del reporte — se evalúa como sin filtro (bs+usd), `comisionesParaNivel` (`ApuestaService.php`); (b) el rollup de agencia multi-local es informativo: si un grupo tiene taquillas en varios locales, su monto settleable se contabiliza en el rollup de cada local (`comisionesRollup`); (c) el rango de comisión del cierre se normaliza a días (startOfDay/endOfDay), por lo que la ventana exacta del cierre (hasta `now()`) puede diferir en horas del día final.
2. **Coordinación §D en merge**: el bloque global aditivo en `limites.astro` (2 filas bs/usd, super_master) debe coordinarse con el agente de límites §D en el merge (sin cambios de matriz; aditivo). Verificado en el código: no toca `limites.ts` ni restructura la matriz.
3. **Bug latente `Comision::$table` corregido en el ciclo**: el modelo `Comision` en el base `c375786` NO declaraba `$table`, por lo que Eloquent resolvía `comisions` en vez de `comisiones` (cualquier query del modelo habría consultado la tabla equivocada). El cambio añade `protected $table = 'comisiones'` (`app/Models/Comision.php:12`); el ledger quedó funcional y testeado.

### SUGGESTION (no bloqueante)

1. La colisión de DB compartida entre worktrees es recurrente (slice 5 de apply, 6.2, y las dos corridas completas de verify, invalidadas por `configuracion-juegos` con `artisan test --parallel --processes=4` sobre `lotto_test_motor`: 10 fallos idénticos por `SQLSTATE[42S02] Table ... doesn't exist`, ninguno de código). Sugerencia: **base de test por worktree** (`lotto_test_<rama>`) o **lock de suite** para eliminar la clase de fallo "Table ... doesn't exist" a mitad de corrida.

## Task Completion Gate

`tasks.md` archivado: **29/29 casillas `[x]`** (1.1–1.6, 2.1–2.5, 3.1–3.3, 4.1–4.7, 5.1–5.6, 6.1, 6.2) — sin tareas de implementación sin marcar. El verify-report confirma 29/29 contra `tasks.md` y el código real. No se requirió reconciliación excepcional de checkboxes.

## Review Gate

Sin artefactos de review para este candidato (no existe `openspec/changes/comisiones/reviews/` ni topic `sdd/comisiones/review/*`): `reviewGate` estructuralmente ausente → archive procede bajo política ordinaria.

## Specs sincronizadas a `openspec/specs/`

| Capability | Acción | Detalle |
|------------|--------|---------|
| comisiones | **Creada** (spec completa, capability nueva) | Copia mecánica (`cp` a temp + `diff -r` vacío + `mv`) desde `openspec/changes/comisiones/specs/comisiones/spec.md` → `openspec/specs/comisiones/spec.md`; readback verbatim vacío; permiso normalizado a 644 (no afecta contenido) |
| reportes-agencia | **Actualizada** (spec existente) | Delta `ADDED`: +1 requisito "Columna de comisión en ventasTotales" (2 escenarios) append al final de Requirements; los 6 requisitos existentes preservados intactos |
| cierre-caja | **Actualizada** (spec existente) | Delta `ADDED`: +1 requisito "Desglose de comisión en el cuadre/cierre" (2 escenarios) append al final de Requirements; los 13 requisitos existentes preservados intactos |

Sin requisitos REMOVED → sin merge destructivo (no aplica `rules.archive` de aviso del `openspec/config.yaml`).

## Movimiento a archive

`openspec/changes/comisiones/` → `openspec/changes/archive/2026-09-30-comisiones/` vía `git mv` (los 2 archivos trackeados del folder: `apply-progress.md`, `tasks.md`) con fallback `mv` para el resto. Readback mecánico obligatorio: `diff -r` snapshot-pre-move vs tree archivado → **vacío (sin diferencias)**, única evidencia de byte-identity. `archive-report.md` es aditivo y quedó excluido de la comparación (no existía en el snapshot fuente). Los archivos `*.md` están en `.gitignore:44`; el commit fuerza el add con `git add -f` sobre los paths de openspec.

## Trazabilidad Engram (observaciones leídas)

| Artefacto | Observación Engram |
|-----------|--------------------|
| proposal | #448 (`sdd/comisiones/proposal`) |
| spec (delta specs) | #451 (`sdd/comisiones/spec`) |
| design | #454 (`sdd/comisiones/design`) |
| tasks | #456 (`sdd/comisiones/tasks`) |
| explore | #435 (`sdd/comisiones/explore`) |
| apply-progress | #464 (`sdd/comisiones/apply-progress`) |
| verify-report | #486 (`sdd/comisiones/verify-report`) |
| Decisiones de producto | #446 (modelo de producto, 2026-09-29) |
| Reconfirmación supuestos i–iii + autorización de cierre | #459 (2026-09-30) — leída completa |
| archive-report (este artefacto) | `sdd/comisiones/archive-report` |

Los artefactos se leyeron primariamente de los archivos del filesystem (modo hybrid); las observaciones Engram son el mirror del pipeline y se listan para trazabilidad.

## Contenido del archive

- proposal.md ✅
- explore.md ✅
- specs/{comisiones, reportes-agencia, cierre-caja}/spec.md ✅ (deltas verbatim)
- design.md ✅
- tasks.md ✅ (29/29)
- apply-progress.md ✅
- verify-report.md ✅ (envelope `pass_with_warnings`)
- archive-report.md ✅ (este artefacto, aditivo)

## Pendientes post-archive

1. **Integración de `origin/main` en el worktree + PR a main** — AUTORIZADO por el usuario (2026-09-30, observación #459); fuera de este archive (sin push ni PR por restricción del encargo). `feat/comisiones` tiene 13 commits; la rama y `origin/main` divergieron (13 vs 30 commits).
2. Coordinación del bloque aditivo global de `limites.astro` con el agente de límites §D al momento del merge (WARNING 2).
3. SUGGESTION: base de test por worktree o lock de suite para eliminar colisiones sobre `lotto_test_motor` (ambiental, no bloqueante).