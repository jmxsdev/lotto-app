/**
 * Motor de teclado (A1, A2) — módulo PURO, sin DOM ni I/O (PR2).
 *
 * Lin-testable vía scripts/check-pure.mjs (Node 24 type-stripping: solo
 * sintaxis TS borrable — sin enums, sin namespaces, sin parameter properties).
 *
 * Contenido:
 *   - KEYMAP: mapa estático F1–F12 (REQ-KB-07). El binding vigente es el del
 *     re-mapeo atajos-2026-09 (pedido del usuario); las acciones locales
 *     (F1–F4, F9–F12) viven en el glue de dashboard.astro y la navegación
 *     global (F5–F8) en MainLayout.astro. Aquí viven el mapa, las guardas de
 *     estado y el ruteo.
 *
 *     Mapa atajos-2026-09 (era → ahora):
 *       F1  repetir-ultima «Repetir última»    (era F4)
 *       F2  ir-numero «Números»                (NUEVO; foco a #qt-numero; Tab→Monto, Enter→Añadir)
 *       F3  anular-ultima «Eliminar última»    (era F3, renombrada)
 *       F4  pagar-generar «Pagar / Generar»    (era F5)
 *       F5  ventas «Ventas»                    (era F7; nav /historial)
 *       F6  resultados «Resultados»            (era F10; nav /resultados)
 *       F7  ganadores «Ganadores»              (NUEVO; nav /ganadores)
 *       F8  cuadre «Cuadre»                    (igual; nav /cierre)
 *       F9  reimprimir «Reimprimir»            (era F12)
 *       F10 anular-ticket «Anular ticket»      (NUEVO; DELETE /tickets/{id} con serial)
 *       F11 limpiar «Limpiar todo»             (era F2)
 *       F12 vuelto «Vuelto»                    (era F9)
 *       Alt+H ayuda «Ayuda»                    (era F1; NUEVO combo, toggle del modal)
 *       Backspace eliminar-item «Eliminar ítem» (era F6; fila del Resumen, solo fuera de inputs)
 *     `ir-numero` (viejo F11 «Números») vuelve al mapa en F2.
 *
 *   - buildZoneGraph(familia, ctx): ciclo de zonas por familia de opciones
 *     (REQ-KB-01, REQ-KB-02, A2). Base: juegos→seleccion→horarios→numero→
 *     monto→añadir→resumen. Zodiacal inserta modalidad tras juegos y signo
 *     SOLO si ctx.triple_c (D1); animalitos omite numero; numérica/terminal
 *     usan la base.
 *   - routeKey(state): decisión pura consumir/pasar (A1): Alt+H (toggle del
 *     modal de ayuda; consume SIEMPRE para preservar la guarda A12), modal
 *     abierto → solo su toggle propio (F12↔vuelto) y Esc,
 *     F-keys con guardas de estado (F1/F3 sin historial, F4 sin líneas),
 *     e.repeat ignorado en F-keys, foco en INPUT/SELECT → no intercepta salvo
 *     F-keys/Escape, Backspace elimina el ítem seleccionado del Resumen (era
 *     F6) solo fuera de inputs, ←/→ en zona Juegos (grid: columna adyacente o
 *     pestaña en el borde, resuelto por el glue), ↑/↓ contextuales,
 *     Tab/Shift+Tab ciclan zonas, Ctrl+A/`*` marcan todos los horarios
 *     visibles (KB-05).
 *   - anulación F10 (atajos-2026-09): helpers PUROS del flujo de anulación de
 *     ticket — normalizarSerial, serialCoincide (anti-tecleo) y
 *     ultimoTicketPendiente (más reciente de GET /tickets; desempate por id).
 *
 *   Historia relevante:
 *     - win-fixes: FIX-3a ←/→ entre columnas; FIX-3b Tab desde Horarios con
 *       selección pendiente; FIX-3c cambio de pestaña bloqueado con selección
 *       en curso; FIX-5 dígitos en Selección; FIX-6 navegación global por
 *       NAV_GLOBAL + destinoNav.
 *     - win-fixes2: FIX B dígitos en Signo (triple_c); FIX C «Limpiar todo»
 *       sin guarda de líneas (desviación de A11).
 *     - win-fixes3: F11 «Números» (salto al input) — retirado de F11 por
 *       atajos-2026-09, que lo recupera como F2 «Números».
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
  /** Guarda de estado: 'lineas' (F4), 'historial' (F1/F3), 'ninguno'. */
  guarda: 'lineas' | 'historial' | 'ninguno';
  /**
   * Batch que fijó el binding tecla→acción vigente (null = sin acción).
   * 'atajos-2026-09' es el re-mapeo completo pedido por el usuario; los
   * literales previos se conservan como historia de los batches anteriores.
   */
  implementadaEn: 'PR3b' | 'win-fixes' | 'win-fixes3' | 'atajos-2026-09' | null;
}

/**
 * Mapa F1–F12 vigente (REQ-KB-07, atajos-2026-09): re-mapeo completo según el
 * pedido del usuario. Ver la cabecera del módulo para la tabla era → nueva.
 */
export const KEYMAP: readonly TeclaMapa[] = [
  { tecla: 'F1', accion: 'repetir-ultima', nombre: 'Repetir última', guarda: 'historial', implementadaEn: 'atajos-2026-09' },
  // F2 «Números» (atajos-2026-09): recupera al viejo F11 «Números»
  // (ir-numero). Salta al input #qt-numero con guarda 'ninguno'; desde ahí
  // Tab→Monto y Enter→Añadir operan con el ruteo existente.
  { tecla: 'F2', accion: 'ir-numero', nombre: 'Números', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F3', accion: 'anular-ultima', nombre: 'Eliminar última', guarda: 'historial', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F4', accion: 'pagar-generar', nombre: 'Pagar / Generar', guarda: 'lineas', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F5', accion: 'ventas', nombre: 'Ventas', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F6', accion: 'resultados', nombre: 'Resultados', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  // F7 «Ganadores» (atajos-2026-09): destino nuevo → /ganadores (NAV_GLOBAL).
  { tecla: 'F7', accion: 'ganadores', nombre: 'Ganadores', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F8', accion: 'cuadre', nombre: 'Cuadre', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F9', accion: 'reimprimir', nombre: 'Reimprimir', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  // F10 «Anular ticket» (atajos-2026-09): modal de serial + DELETE
  // /tickets/{id} (backend existente; ventana por taquilla en el servidor).
  { tecla: 'F10', accion: 'anular-ticket', nombre: 'Anular ticket', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F11', accion: 'limpiar', nombre: 'Limpiar todo', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
  { tecla: 'F12', accion: 'vuelto', nombre: 'Vuelto', guarda: 'ninguno', implementadaEn: 'atajos-2026-09' },
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
 * Mapa puro de navegación global (REQ-KB-07, FIX-6; atajos-2026-09): se
 * renderiza desde MainLayout.astro (cubre dashboard, historial, cierre,
 * resultados y ganadores). F1–F4/F9–F12 y Alt+H NO navegan: son acciones
 * locales del dashboard (F10 «Anular ticket» incluido, atajos-2026-09).
 */
export const NAV_GLOBAL: readonly NavDestino[] = [
  { tecla: 'F5', ruta: '/historial', nombre: 'Ventas' },
  { tecla: 'F6', ruta: '/resultados', nombre: 'Resultados' },
  { tecla: 'F7', ruta: '/ganadores', nombre: 'Ganadores' },
  { tecla: 'F8', ruta: '/cierre', nombre: 'Cuadre' },
  { tecla: 'Alt+D', ruta: '/dashboard', nombre: 'Dashboard' },
];

/**
 * Resolución pura de tecla → destino de navegación (FIX-6). Alt+D exige
 * altKey SIN ctrlKey (Ctrl+Alt = AltGr en algunos layouts y no debe
 * dispararse). null si la tecla no navega (p. ej. F10, local del dashboard).
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
 * Atajos extra que NO son F-keys ni navegación y viven en el glue del
 * dashboard (atajos-2026-09): Alt+H = toggle del modal de ayuda (era F1).
 * Se listan en la leyenda/ayuda junto al KEYMAP y a los destinos de NAV_GLOBAL.
 */
export const ATAJOS_EXTRA: readonly TeclaLegend[] = [
  { tecla: 'Alt+H', nombre: 'Ayuda' },
];

/**
 * Teclas mostradas en la leyenda/ayuda (REQ-KB-09, FIX-6; atajos-2026-09):
 * F1–F12 (KEYMAP) más los destinos globales no-F (Alt+D) del NAV_GLOBAL y los
 * combos extra del glue (Alt+H). Todo derivado de los mapas: la leyenda y el
 * modal de ayuda reflejan el mapa real sin texto hardcodeado.
 */
export function teclasLegend(): readonly TeclaLegend[] {
  const extras = NAV_GLOBAL
    .filter((n) => !KEYMAP.some((k) => k.tecla === n.tecla))
    .map((n) => ({ tecla: n.tecla, nombre: n.nombre }));
  return [
    ...KEYMAP.map((k) => ({ tecla: k.tecla, nombre: k.accion ? k.nombre : 'Libre' })),
    ...extras,
    ...ATAJOS_EXTRA,
  ];
}

// ─── Anulación de ticket F10 (atajos-2026-09) ───────────────────────────────
// Helpers PUROS del flujo de anulación: viven en este módulo (testeable por
// check-pure.mjs sin DOM) porque el PR restringe los archivos tocados.

/** Forma mínima de un ticket devuelto por GET /tickets (TicketController::index). */
export interface TicketPendienteLike {
  id?: number | string | null;
  estado?: string | null;
  created_at?: string | null;
}

/**
 * Normaliza el serial tecleado por el usuario antes de compararlo con el
 * `ticket_code` del backend: recorta extremos y pasa a mayúsculas (anti-tecleo
 * F10). No quita guiones ni separadores: el código se compara íntegro.
 */
export function normalizarSerial(serial: string): string {
  return serial.trim().toUpperCase();
}

/** ¿El serial tecleado coincide con el ticket_code? (verificación anti-tecleo F10). */
export function serialCoincide(tecleado: string, ticketCode: string | null | undefined): boolean {
  if (!ticketCode) return false;
  return normalizarSerial(tecleado) === normalizarSerial(ticketCode);
}

/**
 * Último ticket PENDIENTE de una lista de GET /tickets (F10): ordena por
 * created_at descendente y desempata por id descendente (el backend ya ordena
 * por created_at, pero el desempate y el filtro se re-verifican aquí). null si
 * no hay pendientes.
 */
export function ultimoTicketPendiente<T extends TicketPendienteLike>(tickets: readonly T[]): T | null {
  const pendientes = tickets.filter((t) => t.estado === 'pendiente');
  if (pendientes.length === 0) return null;
  const tiempo = (t: TicketPendienteLike): number | null => {
    const ms = t.created_at ? Date.parse(String(t.created_at)) : NaN;
    return Number.isNaN(ms) ? null : ms;
  };
  const numeroId = (t: TicketPendienteLike): number | null => {
    if (t.id === null || t.id === undefined) return null;
    const n = Number(t.id);
    return Number.isNaN(n) ? null : n;
  };
  return [...pendientes].sort((a, b) => {
    const ta = tiempo(a);
    const tb = tiempo(b);
    if (ta !== null && tb !== null && ta !== tb) return tb - ta;
    const ia = numeroId(a);
    const ib = numeroId(b);
    if (ia !== null && ib !== null && ia !== ib) return ib - ia;
    return 0;
  })[0] ?? null;
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
  /** Alt pulsado: Alt+H (atajos-2026-09) toggles el modal de ayuda. */
  altKey: boolean;
  /** Foco en INPUT/SELECT/TEXTAREA nativo (no intercepta typing, A1). */
  focoEditable: boolean;
  /** Zona con foco actual (roving focus); null si ninguna. */
  zonaActual: NombreZona | null;
  /** Algún modal abierto (.modal-overlay o #help-overlay.abierto). */
  modalAbierto: boolean;
  /** Modal propio abierto: 'ayuda' (Alt+H) o 'vuelto' (F12). null = modal genérico. */
  modalPropio: 'ayuda' | 'vuelto' | null;
  /** Modo de la columna izquierda (juegos | horarios). */
  columnMode: 'juegos' | 'horarios';
  /** Guarda de estado: F4 requiere líneas (A11). */
  tieneLineas: boolean;
  /** Guarda de estado: F1/F3 requieren historial (último groupId, A11). */
  tieneHistorial: boolean;
  /** Guarda de estado: hay una fila de grupo del Resumen enfocada (Backspace,
   *  antes F6; el glue la calcula desde document.activeElement). */
  itemResumenSeleccionado: boolean;
  /** Guarda de pestañas (FIX-3c): hay selección en curso (animal/signo/
   *  modalidad elegidos u horarios marcados) → ←/→ en Juegos NO cambia
   *  pestaña (en el borde del grid; dentro de la fila mueve de columna). */
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
  | { consume: true; tipo: 'toggle-modal'; modal: 'ayuda' | 'vuelto'; ejecutable: boolean }
  | { consume: true; tipo: 'f-key'; fkey: string; accion: string; ejecutable: boolean }
  | { consume: true; tipo: 'eliminar-item' }
  | { consume: true; tipo: 'digito'; digito: string }
  | { consume: false; tipo: 'pasar' };

/**
 * Decisión pura de ruteo (A1): consumir (preventDefault en el glue) o dejar
 * pasar. Reglas, en orden de precedencia:
 *   0. Alt+H (atajos-2026-09) → toggle del modal de ayuda: se consume
 *      siempre (preserva la guarda A12) pero solo ejecuta si no hay otro modal
 *      visible (guarda estricta A12).
 *   1. Modal abierto → solo su propio toggle (F12↔vuelto) y Esc; el resto pasa
 *      (REQ-KB-08).
 *   2. F-keys con e.repeat → se ignoran (no consumen).
 *   3. F-keys mapeadas → SIEMPRE consumen (evita defaults del navegador:
 *      F5 refresh, F12 devtools…) con `ejecutable` según la guarda de estado.
 *   4. Foco en INPUT/SELECT nativo → solo Escape (blur) actúa; el resto pasa
 *      (no secuestra typing, A1).
 *   5. Marcar todos los horarios (Ctrl+A/`*`, KB-05).
 *   6. Dígito en la zona Selección → `digito` (FIX-5).
 *   7. Backspace en el Resumen con fila de grupo enfocada → `eliminar-item`
 *      (era F6, atajos-2026-09): consume solo cuando actúa; en inputs se
 *      escribe normal (regla 4 ya retornó).
 *   8. Navegación: Tab/Shift+Tab ciclan zonas; ←/→ en Juegos se rutean como
 *      cambio de pestaña (el glue decide: columna adyacente del grid o, en el
 *      borde, pestaña — bloqueada con selección en curso, FIX-3c) y fuera de
 *      Juegos se mueven entre columnas (FIX-3a); ↑/↓ contextuales; Escape sube
 *      nivel sin descartar selección.
 */
export function routeKey(state: EstadoRuteo): RutaDecision {
  const fkey = obtenerFKey(state.tecla);

  // 0. Alt+H (atajos-2026-09, ex F1): toggle del modal de ayuda. Se consume
  //    SIEMPRE (preserva la guarda A12: con otro modal consume sin abrir),
  //    pero solo ejecuta si NO hay otro modal visible (guarda estricta A12):
  //    con ayuda abierta cierra; con otro modal (p. ej. vuelto/anular) no abre
  //    nada. e.repeat no re-dispara el toggle.
  if (state.altKey && !state.ctrlKey && state.tecla.toLowerCase() === 'h') {
    if (state.repeat) return { consume: false, tipo: 'pasar' };
    const puedeToggle = !state.modalAbierto || state.modalPropio === 'ayuda';
    return { consume: true, tipo: 'toggle-modal', modal: 'ayuda', ejecutable: puedeToggle };
  }

  // 1. Guarda de modal (REQ-KB-08): con modal abierto solo su toggle y Esc.
  if (state.modalAbierto) {
    if (state.tecla === 'Escape') {
      return { consume: true, tipo: 'escape', nivel: 'modal' };
    }
    if (fkey && fkey.accion === 'vuelto' && state.modalPropio === 'vuelto') {
      // F12 es el toggle del modal de vuelto: re-abrir/cerrar el propio no
      // viola A12 (ninguna F-key abre OTRO modal).
      return { consume: true, tipo: 'toggle-modal', modal: 'vuelto', ejecutable: true };
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
      // tienen acción con el mapa atajos-2026-09).
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

  // 7. Backspace (atajos-2026-09, ex F6): elimina el ítem/grupo seleccionado
  //    del Resumen. Solo fuera de inputs (regla 4 ya retornó) y con una fila
  //    de grupo enfocada + líneas (guarda 'lineas'); consume SOLO cuando
  //    actúa — en inputs se sigue escribiendo normal.
  if (state.tecla === 'Backspace') {
    if (state.zonaActual === 'resumen' && state.itemResumenSeleccionado && state.tieneLineas) {
      return { consume: true, tipo: 'eliminar-item' };
    }
    return { consume: false, tipo: 'pasar' };
  }

  // 8. Navegación por zonas (KB-01, KB-03, KB-04; FIX-3a, FIX-3c).
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
