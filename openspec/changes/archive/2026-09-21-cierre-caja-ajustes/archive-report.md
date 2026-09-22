# Archive Report: cierre-caja-ajustes

**Cambio**: cierre-caja-ajustes (sucesor de `cierre-caja-taquilla`; misma rama `feat/cierre-caja-taquilla`, PR #12)
**Fecha de cierre**: 2026-09-21
**Estado**: cerrado — 29/29 tareas, verify `pass_with_warnings`
**Entrega**: commits nuevos al PR #12 (single-PR con `size:exception` aprobado por el maintainer)

## Resumen

Ciclo de ajustes operativos al cierre de caja: período abierto explícito en el resumen, sección "Reportes por rangos" con dos calendarios e impresión, un solo cierre por día calendario con re-cierre idempotente que actualiza la fila del día previa clave, y clave de cierre por usuario (super_master/master/banca) validada contra la cadena de la taquilla con configuración self-service desde el panel admin.

## Estado final (hechos al cierre)

- **29/29 tareas** completas.
- **7 work units (commits)**: `0b360a2e` (migración+modelos), `b05af10` (clave self-service+cadena), `bd8cea9e` (re-cierre idempotente+`cierre_hoy`), `ffa02b12` (shape completo `cierres[]`), `ef5b2daf` (UI taquilla + IPC `print-reporte`), `b0c5cd7` (panel `clave-cierre`), `1491640` (colecciones).
- **Evidencia runtime**: Unit 281/281 (937 asserts) + Feature 512/514 (2 skipped pre-existentes: `PluginIntegrationTest`, `ScrapeResultsJobTest`) = **793 passed / 3996 asserts**; builds taquilla 8 páginas y panel 25 páginas; `pint --test` backend completo passed. Evidence revision `sha256:fb2fe2bc…`.
- **Verify**: `pass_with_warnings` — 10/10 requisitos, 35/35 escenarios del delta; 0 CRITICAL, 0 blockers.

## Capabilities sincronizadas

- `cierre-caja` (**MODIFIED**): spec consolidado **13 req / 40 escenarios** (base 9/24 + 4 requisitos ADDED y 2 MODIFIED del delta, sin REMOVED).
- `clave-cierre` (**NEW**): **4 req / 14 escenarios**.
- Merge aditivo/no destructivo: los requisitos existentes se conservan; los modificados se reemplazaron por su versión vigente (se retiraron las notas `(Previously: …)` de tracking del delta).

## Findings arrastrados (detalle en `verify-report.md`)

- **WARNING**: Q5 — formato/longitud del PIN (4–8 implementado, default 6 sugerido) pendiente de confirmación de negocio.
- **WARNING**: cobertura manual-only en taquilla/panel (`print-reporte`, variante input de `showModal`, página del panel): build + E2E manual documentado.
- **SUGGESTION**: rutas `clave-cierre` fuera de `verify.mac` (el design las listaba dentro; funcionalmente equivalente — alinear nota); ejemplo PUT del design con `clave_nueva_confirma` (UI-only por decisión 2.1 — corregir ejemplo); `collections/` con URLs pre-v1 en Listar/Ver Cierre (deuda pre-existente); rangos muy largos sin paginación en `/cierre/semanal` (open question).

## Notas operativas

- Migración `2026_09_21_000001_add_clave_cierre_and_reclose_audit` verificada con round-trip en `lotto_test_cierre`; se aplica a `lotto_db` para pruebas locales al cerrar la entrega.
- Datos demo con múltiples cierres el mismo día: sin backfill (sin release); comportamiento hacia adelante = un cierre por día (la detección toma el último por `fecha_fin`).
- Artefactos openspec están gitignored (`*.md`): se forzaron (`git add -f`) en el commit de docs del archivo.
- Ejecución del ciclo con red inestable: la evidencia final, el verify y el archivo se completaron inline bajo sucesores acotados del ledger (`cc-ajustes-final-evidence`, `cc-ajustes-verify`), autorizados por el maintainer.
