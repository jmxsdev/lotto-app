/**
 * Motor de teclado (A1, A2) — módulo PURO, sin DOM ni I/O (PR2).
 *
 * Lin-testable vía scripts/check-pure.mjs (Node 24 type-stripping: solo
 * sintaxis TS borrable — sin enums, sin namespaces, sin parameter properties).
 *
 * Contenido:
 *   - KEYMAP: mapa estático F1–F12 (REQ-KB-07). Las ACCIONES de F-keys se
 *     implementan en PR3b; aquí solo viven el mapa, las guardas de estado y el
 *     ruteo (hooks/stubs en el glue de dashboard.astro).
 *   - buildZoneGraph(familia, ctx): ciclo de zonas por familia de opciones
 *     (REQ-KB-01, REQ-KB-02, A2). Base: juegos→seleccion→horarios→numero→
 *     monto→añadir→resumen. Zodiacal inserta modalidad tras juegos y signo
 *     SOLO si ctx.triple_c (D1); animalitos omite numero; numérica/terminal
 *     usan la base.
 *   - routeKey(state): decisión pura consumir/pasar (A1): F-keys con guardas
 *     de estado (F5/F6 sin líneas, F3/F4 sin historial, F2 sin líneas),
 *     e.repeat ignorado en F-keys, modal abierto → solo su toggle (F1/F9) y
 *     Esc, foco en INPUT/SELECT → no intercepta salvo F-keys/Escape, ←/→ solo
 *     en zona Juegos, ↑/↓ contextuales, Tab/Shift+Tab ciclan zonas, Ctrl+A/`*`
 *     marcan todos los horarios visibles (KB-05).
 *   - win-fixes (batch de remediación del feedback de usuario):
 *     - FIX-3a: ←/→ fuera de la zona Juegos se mueven ENTRE columnas
 *       (zonaHorizontal: juegos/horarios ↔ seleccion ↔ resumen) en vez de morir.
 *     - FIX-3b: Tab desde Horarios con selección pendiente vuelve a la zona que
 *       falta (seleccion/modalidad/signo, zonaPendienteSeleccion) antes de
 *       numero/monto.
 *     - FIX-3c: cambio de pestaña (→/← en Juegos) bloqueado con selección en
 *       curso (pestana-* con ejecutable=false).
 *     - FIX-5: dígitos en la zona Selección → decisión `digito` (el glue decide
 *       entre el buscador de animales y el salto a Número).
 *     - FIX-6: navegación global F7/F8/F10/Alt+D (NAV_GLOBAL + destinoNav)
 *       desde MainLayout; KEYMAP marca F7/F8/F10 con implementadaEn 'win-fixes'.
 *   - win-fixes2 (batch de remediación 2):
 *     - FIX B: los dígitos también se rutean en la zona Signo (zodiacal
 *       triple_c) → decisión `digito`; el glue selecciona el signo por
 *       posición 1-12.
 *     - FIX C: F2 pasa a «Limpiar todo» (nombre) con guarda 'ninguno': se
 *       ejecuta aunque no haya líneas para poder limpiar la selección en
 *       curso (desviación de A11; antes guarda 'lineas').
 *   - win-fixes3 (batch de remediación 3):
 *     - F11 pasa de «Libre» a «Números» (accion `ir-numero`): el glue lleva
 *       el foco al input de Número y a la zona `numero`; desde ahí Tab→Monto
 *       y Enter→Añadir ya funcionan. El menú nativo de Electron se desactiva
 *       (Menu.setApplicationMenu(null)) para que F11 no dispare fullscreen.
 */

export type FamiliaOpciones = 'animalitos' | 'zodiacal' | 'numerica' | 'terminal';

export type NombreZona =
  | 'juegos'
  | 'modalidad'
  | 'seleccion'
  | 'signo'
  | 'horarios'
  | 'numero'
  | 'monto'
  | 'anadir'
  | 'resumen';

/** Contexto de construcción del grafo (A2). */
export interface CtxZonas {
  /** triple_c activa ⇒ se inserta la zona signo antes de horarios (D1). */
  triple_c?: boolean;
}

export interface GrafoZonas {
  familia: FamiliaOpciones;
  zonas: readonly NombreZona[];
  incluye(zona: NombreZona): boolean;
  /** Siguiente zona en el ciclo (envuelve en el extremo). null con lista vacía. */
  siguiente(actual: NombreZona | null): NombreZona | null;
  /** Zona anterior en el ciclo (envuelve en el extremo). null con lista vacía. */
  anterior(actual: NombreZona | null): NombreZona | null;
}

export interface TeclaMapa {
  tecla: 'F1' | 'F2' | 'F3' | 'F4' | 'F5' | 'F6' | 'F7' | 'F8' | 'F9' | 'F10' | 'F11' | 'F12';
  /** Acción semántica (REQ-KB-07). null = tecla sin acción. */
  accion: string | null;
  nombre: string;
  /** Guarda de estado: 'lineas' (F2/F5/F6), 'historial' (F3/F4), 'ninguno'. */
  guarda: 'lineas' | 'historial' | 'ninguno';
  /** Slice que implementa la acción. null = sin acción asignada. */
  implementadaEn: 'PR3b' | 'win-fixes' | 'win-fixes3' | null;
}

/** Mapa F1–F12 (REQ-KB-07). F11 = «Números» (win-fixes3). */
export const KEYMAP: readonly TeclaMapa[] = [
  { tecla: 'F1', accion: 'ayuda', nombre: 'Ayuda', guarda: 'ninguno', implementadaEn: 'PR3b' },
  // F2 «Limpiar todo» (win-fixes2 FIX C): guarda 'ninguno' — se ejecuta SIN
  // líneas para poder limpiar la selección en curso (desviación de A11, que
  // exigía líneas; el reset total deja el guard de pestañas satisfecho).
  { tecla: 'F2', accion: 'limpiar', nombre: 'Limpiar todo', guarda: 'ninguno', implementadaEn: 'PR3b' },
  { tecla: 'F3', accion: 'anular-ultima', nombre: 'Anular última', guarda: 'historial', implementadaEn: 'PR3b' },
  { tecla: 'F4', accion: 'repetir-ultima', nombre: 'Repetir última', guarda: 'historial', implementadaEn: 'PR3b' },
  { tecla: 'F5', accion: 'pagar-generar', nombre: 'Pagar / Generar', guarda: 'lineas', implementadaEn: 'PR3b' },
  { tecla: 'F6', accion: 'eliminar-item', nombre: 'Eliminar ítem', guarda: 'lineas', implementadaEn: 'PR3b' },
  { tecla: 'F7', accion: 'ventas', nombre: 'Ventas', guarda: 'ninguno', implementadaEn: 'win-fixes' },
  { tecla: 'F8', accion: 'cuadre', nombre: 'Cuadre', guarda: 'ninguno', implementadaEn: 'win-fixes' },
  { tecla: 'F9', accion: 'vuelto', nombre: 'Vuelto', guarda: 'ninguno', implementadaEn: 'PR3b' },
  { tecla: 'F10', accion: 'resultados', nombre: 'Resultados', guarda: 'ninguno', implementadaEn: 'win-fixes' },
  // F11 «Números» (win-fixes3): salto directo al input de Número. El menú
  // nativo de Electron se desactiva (main.cjs) para que F11 no haga
  // fullscreen; el glue enfoca #qt-numero y la zona `numero`.
  { tecla: 'F11', accion: 'ir-numero', nombre: 'Números', guarda: 'ninguno', implementadaEn: 'win-fixes3' },
  { tecla: 'F12', accion: 'reimprimir', nombre: 'Reimprimir', guarda: 'ninguno', implementadaEn: 'PR3b' },
];

const ZONAS_BASE: readonly NombreZona[] = [
  'juegos',
  'seleccion',
  'horarios',
  'numero',
  'monto',
  'anadir',
  'resumen',
];

/** Destino de la navegación global (FIX-6): F-keys y Alt+D desde MainLayout. */
export interface NavDestino {
  tecla: string;
  ruta: string;
  nombre: string;
}

/**
 * Mapa puro de navegación global (REQ-KB-07, FIX-6): se renderiza desde
 * MainLayout.astro (cubre dashboard, historial, cierre, resultados y
 * ganadores). F11 NO navega: es la acción local «Números» del dashboard
 * (win-fixes3).
 */
export const NAV_GLOBAL: readonly NavDestino[] = [
  { tecla: 'F7', ruta: '/historial', nombre: 'Ventas' },
  { tecla: 'F8', ruta: '/cierre', nombre: 'Cuadre' },
  { tecla: 'F10', ruta: '/resultados', nombre: 'Resultados' },
  { tecla: 'Alt+D', ruta: '/dashboard', nombre: 'Dashboard' },
];

/**
 * Resolución pura de tecla → destino de navegación (FIX-6). Alt+D exige
 * altKey SIN ctrlKey (Ctrl+Alt = AltGr en algunos layouts y no debe
 * dispararse). null si la tecla no navega (p. ej. F11, local del dashboard).
 */
export function destinoNav(tecla: string, opts: { altKey: boolean; ctrlKey: boolean }): NavDestino | null {
  if (opts.altKey && !opts.ctrlKey && tecla.toLowerCase() === 'd') {
    return NAV_GLOBAL.find((n) => n.tecla === 'Alt+D') ?? null;
  }
  return NAV_GLOBAL.find((n) => n.tecla === tecla) ?? null;
}

export interface TeclaLegend {
  tecla: string;
  nombre: string;
}

/**
 * Teclas mostradas en la leyenda/ayuda (REQ-KB-09, FIX-6): F1–F12 (KEYMAP,
 * F11 «Números») más los destinos globales no-F (Alt+D) del NAV_GLOBAL.
 */
export function teclasLegend(): readonly TeclaLegend[] {
  const extras = NAV_GLOBAL
    .filter((n) => !KEYMAP.some((k) => k.tecla === n.tecla))
    .map((n) => ({ tecla: n.tecla, nombre: n.nombre }));
  return [
    ...KEYMAP.map((k) => ({ tecla: k.tecla, nombre: k.accion ? k.nombre : 'Libre' })),
    ...extras,
  ];
}

/**
 * Adyacencia HORIZONTAL entre zonas (FIX-3a, KB-03): ←/→ fuera de la zona
 * Juegos se mueven entre columnas (izquierda ↔ centro ↔ derecha) en vez de
 * morir. La columna izquierda es juegos u horarios según columnMode; el
 * centro agrupa modalidad/seleccion/signo; la derecha es el resumen.
 * numero/monto/anadir (barra superior) no tienen vecino horizontal → null.
 */
export type DireccionHorizontal = 'izquierda' | 'derecha';

export function zonaHorizontal(
  actual: NombreZona,
  direccion: DireccionHorizontal,
  columnMode: 'juegos' | 'horarios',
): NombreZona | null {
  const izquierda: NombreZona = columnMode === 'horarios' ? 'horarios' : 'juegos';
  const centro: readonly NombreZona[] = ['modalidad', 'seleccion', 'signo'];
  if (direccion === 'derecha') {
    if (actual === 'juegos' || actual === 'horarios') return 'seleccion';
    if (centro.includes(actual)) return 'resumen';
    return null;
  }
  if (centro.includes(actual)) return izquierda;
  if (actual === 'resumen') return 'seleccion';
  return null;
}

/**
 * Zona de selección PENDIENTE (FIX-3b, KB-01): con un juego activo, la zona
 * que todavía falta completar ANTES de numero/monto. animalitos sin animal →
 * seleccion; zodiacal sin modalidad → modalidad; triple_c sin signo → signo.
 * null = la selección está completa (o no aplica: numérica/terminal, cuyo
 * «pendiente» es la propia zona numero).
 */
export interface EstadoSeleccionPendiente {
  familia: FamiliaOpciones | null;
  tripleModalidad: string | null;
  signoElegido: boolean;
  animalElegido: boolean;
}

export function zonaPendienteSeleccion(estado: EstadoSeleccionPendiente): NombreZona | null {
  if (estado.familia === 'animalitos') {
    return estado.animalElegido ? null : 'seleccion';
  }
  if (estado.familia === 'zodiacal') {
    if (!estado.tripleModalidad) return 'modalidad';
    if (estado.tripleModalidad === 'triple_c' && !estado.signoElegido) return 'signo';
    return null;
  }
  return null;
}

function crearGrafo(familia: FamiliaOpciones, zonas: readonly NombreZona[]): GrafoZonas {
  return {
    familia,
    zonas,
    incluye(zona) {
      return zonas.includes(zona);
    },
    siguiente(actual) {
      if (zonas.length === 0) return null;
      if (actual === null) return zonas[0];
      const i = zonas.indexOf(actual);
      return zonas[i === -1 ? 0 : (i + 1) % zonas.length];
    },
    anterior(actual) {
      if (zonas.length === 0) return null;
      if (actual === null) return zonas[zonas.length - 1];
      const i = zonas.indexOf(actual);
      return zonas[i === -1 ? zonas.length - 1 : (i - 1 + zonas.length) % zonas.length];
    },
  };
}

/**
 * Ciclo de zonas por familia de opciones (REQ-KB-02, A2):
 *   - animalitos: base SIN numero (los dígitos buscan en seleccion).
 *   - zodiacal: juegos → modalidad → seleccion → [signo si triple_c] →
 *     horarios → numero → monto → añadir → resumen.
 *   - numerica (100 opciones 00-99) y terminal (2 cifras): base.
 */
export function buildZoneGraph(familia: FamiliaOpciones, ctx: CtxZonas = {}): GrafoZonas {
  if (familia === 'animalitos') {
    return crearGrafo('animalitos', ZONAS_BASE.filter((z) => z !== 'numero'));
  }
  if (familia === 'zodiacal') {
    const zonas: NombreZona[] = ['juegos', 'modalidad', 'seleccion'];
    if (ctx.triple_c === true) zonas.push('signo');
    zonas.push('horarios', 'numero', 'monto', 'anadir', 'resumen');
    return crearGrafo('zodiacal', zonas);
  }
  return crearGrafo(familia, [...ZONAS_BASE]);
}

/** ¿Es una tecla de función F1..F12? */
export function esFKey(tecla: string): boolean {
  return /^F(?:[1-9]|1[0-2])$/.test(tecla);
}

export function obtenerFKey(tecla: string): TeclaMapa | null {
  const f = KEYMAP.find((k) => k.tecla === tecla);
  return f ?? null;
}

/** Estado observable del renderer que alimenta el ruteo (A1). */
export interface EstadoRuteo {
  tecla: string;
  /** e.repeat: las F-keys en auto-repetición se ignoran (A1). */
  repeat: boolean;
  ctrlKey: boolean;
  shiftKey: boolean;
  /** Foco en INPUT/SELECT/TEXTAREA nativo (no intercepta typing, A1). */
  focoEditable: boolean;
  /** Zona con foco actual (roving focus); null si ninguna. */
  zonaActual: NombreZona | null;
  /** Algún modal abierto (.modal-overlay o #help-overlay.abierto). */
  modalAbierto: boolean;
  /** Modal propio abierto: 'f1' (ayuda) o 'f9' (vuelto). null = modal genérico. */
  modalPropio: 'f1' | 'f9' | null;
  /** Modo de la columna izquierda (juegos | horarios). */
  columnMode: 'juegos' | 'horarios';
  /** Guarda de estado: F2/F5/F6 requieren líneas (A11). */
  tieneLineas: boolean;
  /** Guarda de estado: F3/F4 requieren historial (último groupId, A11). */
  tieneHistorial: boolean;
  /** Guarda de pestañas (FIX-3c): hay selección en curso (animal/signo/
   *  modalidad elegidos u horarios marcados) → ←/→ en Juegos NO cambia pestaña. */
  seleccionEnCurso: boolean;
}

export type RutaDecision =
  | { consume: true; tipo: 'tab-siguiente' }
  | { consume: true; tipo: 'tab-anterior' }
  | { consume: true; tipo: 'pestana-anterior'; ejecutable: boolean }
  | { consume: true; tipo: 'pestana-siguiente'; ejecutable: boolean }
  | { consume: true; tipo: 'zona-izquierda' }
  | { consume: true; tipo: 'zona-derecha' }
  | { consume: true; tipo: 'fila-anterior' }
  | { consume: true; tipo: 'fila-siguiente' }
  | { consume: true; tipo: 'marcar-todos' }
  | { consume: true; tipo: 'escape'; nivel: 'modal' | 'input' | 'juegos' | 'zona-anterior' }
  | { consume: true; tipo: 'toggle-modal'; modal: 'f1' | 'f9' }
  | { consume: true; tipo: 'f-key'; fkey: string; accion: string; ejecutable: boolean }
  | { consume: true; tipo: 'digito'; digito: string }
  | { consume: false; tipo: 'pasar' };

/**
 * Decisión pura de ruteo (A1): consumir (preventDefault en el glue) o dejar
 * pasar. Reglas, en orden de precedencia:
 *   1. Modal abierto → solo su propio toggle (F1/F9) y Esc; el resto pasa
 *      (REQ-KB-08).
 *   2. F-keys con e.repeat → se ignoran (no consumen).
 *   3. F-keys mapeadas → SIEMPRE consumen (evita defaults del navegador:
 *      F5 refresh, F12 devtools…) con `ejecutable` según la guarda de estado.
 *   4. Foco en INPUT/SELECT nativo → solo Escape (blur) actúa; el resto pasa
 *      (no secuestra typing, A1).
 *   5. Marcar todos los horarios (Ctrl+A/`*`, KB-05).
 *   6. Dígito en la zona Selección → `digito` (FIX-5).
 *   7. Navegación: Tab/Shift+Tab ciclan zonas; ←/→ en Juegos cambian de
 *      pestaña (bloqueado con selección en curso, FIX-3c) y fuera de Juegos
 *      se mueven entre columnas (FIX-3a); ↑/↓ contextuales; Escape sube
 *      nivel sin descartar selección.
 */
export function routeKey(state: EstadoRuteo): RutaDecision {
  const fkey = obtenerFKey(state.tecla);

  // 1. Guarda de modal (REQ-KB-08): con modal abierto solo su toggle y Esc.
  if (state.modalAbierto) {
    if (state.tecla === 'Escape') {
      return { consume: true, tipo: 'escape', nivel: 'modal' };
    }
    if (fkey) {
      if (fkey.tecla === 'F1' && state.modalPropio === 'f1') {
        return { consume: true, tipo: 'toggle-modal', modal: 'f1' };
      }
      if (fkey.tecla === 'F9' && state.modalPropio === 'f9') {
        return { consume: true, tipo: 'toggle-modal', modal: 'f9' };
      }
    }
    return { consume: false, tipo: 'pasar' };
  }

  // 2. F-keys en auto-repetición: se ignoran (A1).
  if (state.repeat && fkey) {
    return { consume: false, tipo: 'pasar' };
  }

  // 3. F-keys mapeadas: consumen siempre; ejecutables según guarda de estado.
  if (fkey) {
    if (fkey.accion === null) {
      // Red de seguridad: F-key sin acción no consume (hoy TODAS las F1–F12
      // tienen acción, incluida F11 «Números» desde win-fixes3).
      return { consume: false, tipo: 'pasar' };
    }
    let ejecutable = true;
    if (fkey.guarda === 'lineas' && !state.tieneLineas) ejecutable = false;
    if (fkey.guarda === 'historial' && !state.tieneHistorial) ejecutable = false;
    return { consume: true, tipo: 'f-key', fkey: fkey.tecla, accion: fkey.accion, ejecutable };
  }

  // 4. Foco en INPUT/SELECT nativo: solo Escape (blur); no intercepta typing.
  if (state.focoEditable) {
    if (state.tecla === 'Escape') {
      return { consume: true, tipo: 'escape', nivel: 'input' };
    }
    return { consume: false, tipo: 'pasar' };
  }

  // 5. Marcar todos los horarios visibles (KB-05, A3): Ctrl+A o `*` SOLO con
  //    la lista de horarios abierta y fuera de input (el texto de inputs no se
  //    secuestra; focoEditable ya retornó en la regla 4).
  if ((state.ctrlKey && state.tecla.toLowerCase() === 'a') || state.tecla === '*') {
    return state.columnMode === 'horarios'
      ? { consume: true, tipo: 'marcar-todos' }
      : { consume: false, tipo: 'pasar' };
  }

  // 6. Dígito en la zona Selección (FIX-5, REQ-KB-06) o Signo (win-fixes2
  //    FIX B): con foco fuera de input, un dígito arranca la búsqueda
  //    (animalitos) o salta a Número (numérica/terminal), y en la zona Signo
  //    (zodiacal triple_c) selecciona el signo por posición 1-12. El glue
  //    decide según la familia y la modalidad activa.
  if (/^\d$/.test(state.tecla) && (state.zonaActual === 'seleccion' || state.zonaActual === 'signo')) {
    return { consume: true, tipo: 'digito', digito: state.tecla };
  }

  // 7. Navegación por zonas (KB-01, KB-03, KB-04; FIX-3a, FIX-3c).
  switch (state.tecla) {
    case 'Tab':
      return state.shiftKey
        ? { consume: true, tipo: 'tab-anterior' }
        : { consume: true, tipo: 'tab-siguiente' };
    case 'ArrowLeft':
      if (state.zonaActual === 'juegos') {
        // FIX-3c: con selección en curso el cambio de pestaña se bloquea
        // (ejecutable=false); el glue muestra el aviso breve.
        return { consume: true, tipo: 'pestana-anterior', ejecutable: !state.seleccionEnCurso };
      }
      if (state.zonaActual === null) return { consume: false, tipo: 'pasar' };
      // FIX-3a: fuera de Juegos, ← se mueve a la columna adyacente (o pasa
      // si no hay vecino horizontal, p. ej. numero/monto/anadir).
      return zonaHorizontal(state.zonaActual, 'izquierda', state.columnMode) !== null
        ? { consume: true, tipo: 'zona-izquierda' }
        : { consume: false, tipo: 'pasar' };
    case 'ArrowRight':
      if (state.zonaActual === 'juegos') {
        return { consume: true, tipo: 'pestana-siguiente', ejecutable: !state.seleccionEnCurso };
      }
      if (state.zonaActual === null) return { consume: false, tipo: 'pasar' };
      return zonaHorizontal(state.zonaActual, 'derecha', state.columnMode) !== null
        ? { consume: true, tipo: 'zona-derecha' }
        : { consume: false, tipo: 'pasar' };
    case 'ArrowUp':
      return { consume: true, tipo: 'fila-anterior' };
    case 'ArrowDown':
      return { consume: true, tipo: 'fila-siguiente' };
    case 'Escape':
      // Sube un nivel sin descartar selección/líneas (KB-04): en modo
      // horarios vuelve a Juegos; si no, a la zona previa.
      return state.columnMode === 'horarios'
        ? { consume: true, tipo: 'escape', nivel: 'juegos' }
        : { consume: true, tipo: 'escape', nivel: 'zona-anterior' };
    default:
      return { consume: false, tipo: 'pasar' };
  }
}
