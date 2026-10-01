# Changelog

Todas las versiones notables de la Taquilla se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.1.0/) y el
versionado es [SemVer](https://semver.org/lang/es/).

## [1.0.2] — 2026-10-01

Correcciones del historial, del copy de pago y de las modalidades.

### Corregido

- **Anulación**: los tickets anulados quedan visibles en el historial con badge
  `ANULADA` y sin acciones (antes desaparecían por el scope de soft-delete).
- **Confirmación de pago**: muestra el premio pagado (Bs y/o $) en vez del
  total apostado del ticket.

### Cambiado

- **Modalidades de tripletas**: selector en grilla de dos columnas, con
  navegación por fila (↑/↓) y por columna (←/→).

## [1.0.1] — 2026-10-01

Hotfix de la Taquilla.

### Corregido

- **Dashboard**: el icono referenciado antes de su inicialización (TDZ) rompía
  el renderizado del panel.
- **Comprobante de pago**: se imprime al registrar el pago y admite
  reimpresión; los totales se derivan de los pagos registrados.
- **Tiempo de anulación**: configurable por el panel (1..8760 horas).
- **Navegación de tripletas**: se recorre con las flechas del teclado.
- **Foco directo a Número**: el cursor entra directo al input de Número.

## [1.0.0] — 2026-09-28

Primera versión estable. Ciclo completo del producto: la taquilla vende con
modalidades reales, paga premios contra resultados oficiales y el panel
gestiona juegos, vencimientos y ganadores del día.

### Agregado

- **Pago de premios** (TQ-01): pago de tickets `ganadora` vía `POST /pagos`
  (egreso) con resultado real de la API — sin "éxito" falso, montos derivados
  del premio declarado, soporte de moneda (Bs / $ / mixto) y cascada de
  apuestas `pendiente` → `pagada`.
- **Catálogo bundled** (TQ-06): el dashboard carga el catálogo desde una copia
  local empaquetada (`taquilla/src/data/juegos.json`, 21 juegos) y expone los
  premios normalizados por juego; La Ricachona queda oculta (`vendible=false`).
- **Estados de ticket** (TQ-02/TQ-03): chips `ganador`/`vencido`, filtros en el
  historial, badge por apuesta y ticket "resuelto sin ganadores" — el estado
  nunca se inventa, cae al estado real de la API.
- **Resultados** (TQ-04): renderizado de `figuras[]` (trofeo + animal +
  número), `comodín`, `patronus`, `arrimao` y `pegadito`, con iconos Lucide
  únicamente.
- **Modalidades single-draw** (TQ-05a): punta, terminal, uña, aproximación,
  signo terminal, signo solo, arrimao y pegadito, con validación de dígitos y
  `premio_posible > 0` en la venta.
- **Multi-selección same-draw** (TQ-05b): cruzado, Par A+B (Chance) y tripleta
  (Cazalotón/Chaima) vía `selecciones[]`, con validación backend dedicada.
- **Panel** (PN-01/02/04/05): toggle de juegos, página de configuración del
  vencimiento de apuestas (1..8760 horas, roles `super_master|master`, 403 para
  banca), rótulo "Ganadores Hoy" con el total real de ganadoras del día y
  topbar dinámico por página.

### Cambiado

- Backend: `Tripletas::validarApuesta` y `Animalitos::validarApuesta`
  aceptan las modalidades nuevas y `selecciones[]`, reutilizando `modalidadDe`
  (201 por modalidad, 422 con dígitos/animales inválidos).
- Taquilla: selector de modalidades y grafo de zonas del teclado ampliado para
  multi-selección.

### Corregido

- El botón Pagar usaba `estado === 'pendiente'`; ahora usa `esPagableTicket`
  (tickets en `ganador` con apuestas `ganadora` sin pagar también pagables).
- Se eliminó el `catch` vacío del toggle de juegos en el panel: el error real
  se muestra vía modal y el estado persiste tras recargar.

### Técnico

- Instalador NSIS `Taquilla-Setup-1.0.0.exe` (`artifactName` derivado de
  `package.json`, fuente única de versión).
- Publicación de release vía `releases:publish --release-version=1.0.0`;
  `GET /api/v1/update-check` responde `version=1.0.0` (notify-only, la taquilla
  nunca auto-instala).

[1.0.0]: https://github.com/jmxsdev/lotto-app/releases/tag/v1.0.0