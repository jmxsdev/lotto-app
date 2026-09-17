# Estrategia: ciclo de scrapers y motor de premios

> Actualizado: 2026-09-12. Decisiones del cliente + hallazgos del equipo.

## Decisión de ciclos

### Ciclo actual — SOLO SCRAPERS (en curso)

- **Alcance**: integrar juegos (scraper + seeder + tests + carga real) y mantener al día
  `docs/juegos.json` (opciones correctas por juego + premiación hallada).
- **NO** se modifica el motor de premios en este ciclo.
- Se cierra con: scrapers funcionales + cualquier hallazgo valioso documentado.

### Próximo ciclo — MOTOR DE PREMIOS (SDD dedicado)

- Se inicia DESPUÉS de cerrar el ciclo de scrapers, con estrategia comunicada y diseñada
  junto al cliente, basada en los hallazgos.
- Base de trabajo: `docs/comparacion-juegos.md` (hallazgos H1–H7) + análisis del motor
  (Engram `architecture/motor-premios`).
- **Hallazgo clave**: los multiplicadores están **hardcodeados en los plugins**
  (`Animalitos` 30×, `Tripletas` 30×, `Terminales` 20×) y `config.premio_multiplo` **no se usa
  al liquidar** — solo lo consume el JSON del front. Juegos con premios distintos al genérico
  pagarían el genérico.
- Fases propuestas:
  1. **Premios config-driven** (base + comodines + especiales por juego) — desbloquea H1/H3.
  2. **Zoológicos propios en validación** + alineaciones (H2/H5).
  3. **Modalidades nuevas** (Dupleta cross-sorteo, El Patronus) — diseño dedicado aparte.

## Política de fuentes de datos

- **Scrapers**: SIEMPRE desde páginas oficiales cuando existan (fidelidad de datos).
- **resultadosvenezuela.com**: fuente **informativa** (reglas, zoológicos, premiación,
  comparación) — NO para scrapers, salvo juego sin página oficial
  (excepción autorizada: Mega Animal 40).
- **⚠️ Lección Selva Plus**: el proveedor puede estar **desactualizado o equivocado**
  (describía Selva Plus como 38 animalitos / 30× / 11 sorteos; el sitio oficial dice
  **101 figuras + 2 comodines / 80× / 13 sorteos**). Todo dato del proveedor se VERIFICA
  contra fuente oficial antes de tocar nuestro sistema.
- **Información no obtenible con fiabilidad** (p. ej. comodines sin representación en los
  datos de la fuente): **no se simula**. Se documenta como no soportada hasta tener fuente fiable.

## Contrato del front (`docs/juegos.json`)

- Mantener actualizado con nuestros hallazgos: **opciones correctas por juego** +
  **premiación real** (`premio_multiplo` con el valor oficial).
- Pendiente (ciclo motor de premios): extender el contrato con detalle de premios/comodines
  si el front lo requiere (se definirá con ellos).
- Regenerar con `php artisan juegos:export` en cada work unit de juego.

## Hallazgos pendientes de verificación oficial (NO tocar sin verificar)

| Hallazgo (fuente: proveedor) | Qué verificar | Contra |
|---|---|---|
| `monje-millonario` 77 figuras | Zoológico real | Fuente oficial del juego |
| `guacharo-activo` 77 figuras | Zoológico real | Fuente oficial del juego |
| `guacharito-millonario` 101 figuras | Zoológico real | Fuente oficial del juego |
| `trio-activo` terminales 3 cifras / 3 sorteos | Tipo y horarios reales | lottoactivo.com |
| `cazaloton` zoo no declarado | Zoológico real | Su fuente oficial |

> Regla: primero verificar, después corregir datos/JSON. El proveedor quedó demostrado
> poco fiable para Selva Plus.
