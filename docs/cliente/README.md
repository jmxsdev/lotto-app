# docs/cliente — Documentación de consulta para el cliente y decisiones de negocio

> Índice de los documentos que **se entregan al cliente** o que sustentan **decisiones de negocio**:
> cómo se juega y paga cada juego, multiplicadores oficiales, limitantes de las fuentes y los puntos
> abiertos que el negocio debe decidir.
> Documentación técnica/operativa: [`../dev/README.md`](../dev/README.md).
> Reglamentos y afiches oficiales (PDFs): [`../reglamentos/`](../reglamentos/README.md).

## Cómo usar este set

1. Para entender **cuánto paga un juego** con ejemplos → `premiacion-juegos.md` (didáctico) y
   `multiplicadores-juegos.md` (tabla oficial).
2. Para **cambiar premios** (base, modalidades, comodines) → `guia-editor-premios.md`.
3. Para **decidir** sobre discrepancias o juegos nuevos → `inconsistencias.md`,
   `comparacion-juegos.md` y `plataformas-juegos.md` (tabla al final).
4. Los valores de los documentos ya **coinciden con el catálogo implementado**
   (`backend/app/Support/PremiosOficiales.php`): no hay drift doc↔código.

## Documentos

| Archivo | Propósito |
|---|---|
| [`premiacion-juegos.md`](premiacion-juegos.md) | Guía didáctica: **cómo se juega, qué se apuesta, cuándo se gana y cuánto se paga** en los 21 juegos, con ejemplos. **Documento para el cliente.** |
| [`multiplicadores-juegos.md`](multiplicadores-juegos.md) | Tabla consolidada de **multiplicadores oficiales** por juego, fuente y estado de aplicación. |
| [`guia-editor-premios.md`](guia-editor-premios.md) | Cómo se configuran **base, modalidades y comodines** desde el editor del panel, con ejemplos concretos. |
| [`inconsistencias.md`](inconsistencias.md) | **Registro canónico de las referencias H (H1–H24)**: informativa vs oficial, contradicciones internas y gaps. |
| [`comparacion-juegos.md`](comparacion-juegos.md) | Narrativa de la comparación contra el proveedor + **hallazgos con decisión pendiente**. |
| [`seguimiento-verificacion.md`](seguimiento-verificacion.md) | **Log de verificación por juego** contra su fuente oficial (horarios, opciones, premios, reglamento). |
| [`fuentes-oficiales.md`](fuentes-oficiales.md) | **Registro de la fuente oficial** de cada juego (para scrapers y verificación) y de la informativa. |
| [`plataformas-juegos.md`](plataformas-juegos.md) | Plataformas multi-juego mapeadas y **candidatos de juegos nuevos para consultar al cliente**. |

## Decisiones de negocio pendientes (resumen)

> Detalle y evidencia en `inconsistencias.md` / `comparacion-juegos.md`; seguimiento en
> `../dev/pendientes-front.md` §3 (`DEC-xx`).

| Ref | Tema | Decisión pendiente |
|---|---|---|
| H12 / DEC-01 | Terminal Trío | Reglamento **60×** vs FAQ oficial **70×** (+5× aproximación) |
| H23 / DEC-02 | Triple Chance | Reglamento **150× / 6.000×** vs afiche **100× / 5.000×** |
| DEC-03 | Dupleta 1.000×, Par Millonario 200.000×, El Patronus | ¿Se soportan como modalidades nuevas? |
| DEC-04 | La Ricachona | Deshabilitada (sin fuente con valores); los fronts deben ocultarla |
| DEC-05 / H15·H18·H19 | Horarios operativos | Domingos de Táchira/Zamorano; Trío Activo 3 vs 12 sorteos; Caliente 5 vs 3 |
| DEC-06 | Juegos nuevos | ¿Integrar los candidatos de `plataformas-juegos.md`? |
| DEC-07 | PIN de cierre de caja | Formato 4–8 dígitos (default 6) sin confirmar |
| DEC-08 | Iconos de comodines | Confirmar emojis/comodines (~8–10) |

**Fuentes a obtener del cliente/operador**

- **7 reglamentos no publicados**: `triple-facil`, `el-arrejuntado`, `el-guacharito`,
  `guacharo-activo`, `loto-chaima`, `selva-plus`, `mega-animal-40`.
- **PDFs escaneados a extraer** (sin capa de texto): Triple Chance (×2), La Granjita, La Ricachona,
  y `lotto-activo` (imagen). Ver [`../reglamentos/README.md`](../reglamentos/README.md).
