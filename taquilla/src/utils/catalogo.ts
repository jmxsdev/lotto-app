/**
 * Catálogo local bundled (D4) — módulo puro, sin I/O ni DOM.
 *
 * Fuente de datos: docs/juegos.json (copia literal en src/data/juegos.json,
 * 21 juegos, version 1). Este módulo NO consume endpoints backend de catálogo
 * (REQ-CL-01) y es Lin-testable vía scripts/check-pure.mjs (Node 24
 * type-stripping, solo sintaxis TS borrable: sin enums ni namespaces).
 *
 * Familias de opciones (A5, REQ-CL-02):
 *   - animalitos: 11 juegos, nombres de animales, número 0..36 (duplicado
 *     Ballena/Delfín en numero 0).
 *   - zodiacal: tripletas con 12 signos (numero null, value = sigla).
 *   - numerica: tripletas 00..99 (trio-activo, triple-facil).
 *   - terminal: terminales 00..99 con label de 2 dígitos ("05").
 */

export type TipoJuego = 'animalitos' | 'tripletas' | 'terminales';

export type FamiliaOpciones = 'animalitos' | 'zodiacal' | 'numerica' | 'terminal';

export interface OpcionCatalogo {
  numero: number | null;
  label: string;
  value: string;
  /**
   * Icono del animalito resuelto por slug desde el catálogo (aditivo,
   * iconos-consistentes). El contrato JSON lo lleva en `null` para familias
   * no animal; sanitizarOpcion conserva SOLO strings no vacíos y descarta el
   * resto, por lo que aquí suele estar ausente (undefined) o ser el emoji.
   */
  icono?: string | null;
}

export interface JuegoCatalogo {
  id: number;
  slug: string;
  nombre: string;
  tipo: TipoJuego;
  /**
   * Espejo legacy de `premios.base` (motor-premios). `null` cuando el juego no
   * está activo/vendible (p. ej. la-ricachona): el catálogo exportado lo lleva
   * así y la taquilla debe aceptarlo sin romper la carga.
   */
  premio_multiplo: number | null;
  /**
   * Estado del motor (motor-premios): `active=false`/`vendible=false` significa
   * que el juego no se ofrece ni permite vender (el backend lo rechaza). Se
   * conserva en el catálogo (21 juegos) y la UI filtra por `vendible`.
   */
  active: boolean;
  vendible: boolean;
  comodines: Record<string, unknown> | null;
  modalidades: Record<string, unknown> | null;
  horarios: string[];
  opciones: OpcionCatalogo[];
  /** Derivada en cargarCatalogo (A5): define la búsqueda/selección. */
  familia: FamiliaOpciones;
}

export interface Catalogo {
  version: number;
  juegos: JuegoCatalogo[];
  porId: Map<number, JuegoCatalogo>;
  porSlug: Map<string, JuegoCatalogo>;
}

export interface BuscadorDigitos {
  teclear(digito: string): void;
  commit(): void;
  limpiar(): void;
  buffer(): string;
}

const TIPOS_VALIDOS: readonly TipoJuego[] = ['animalitos', 'tripletas', 'terminales'];
const MAX_DIGITOS = 3; // cubre animales (0..36), terminales (00..99) y tripletas (3 cifras)

/** NFD + sin diacríticos + minúsculas (Ciempiés→ciempies, Cáncer→cancer, Géminis→geminis). */
export function normalizarLabel(label: string): string {
  return label.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

function esNumero(valor: unknown): valor is number {
  return typeof valor === 'number' && Number.isFinite(valor);
}

function sanitizarOpcion(raw: Record<string, unknown>, indice: number): OpcionCatalogo {
  const numero =
    raw.numero === null || raw.numero === undefined
      ? null
      : esNumero(raw.numero)
        ? raw.numero
        : (() => {
            throw new Error(`opcion[${indice}]: "numero" debe ser number o null`);
          })();
  const label = typeof raw.label === 'string' ? raw.label : '';
  const value = typeof raw.value === 'string' ? raw.value : '';
  if (!label) throw new Error(`opcion[${indice}]: falta "label"`);
  if (!value) throw new Error(`opcion[${indice}]: falta "value"`);
  const opcion: OpcionCatalogo = { numero, label, value };
  // Campo aditivo `icono` (iconos-consistentes): se conserva solo si es un
  // string NO vacío; null/ausente/vacío se descartan (el contrato JSON lleva
  // null en familias no animal, aquí no se propaga).
  const icono = typeof raw.icono === 'string' && raw.icono !== '' ? raw.icono : undefined;
  if (icono !== undefined) {
    opcion.icono = icono;
  }
  // Salvaguarda legado: el catálogo bundled ya dice "Cebra" (docs/juegos.json);
  // datos legacy pueden traer "Cobra" (el backend usa Cebra, Animalitos.php:34).
  if (normalizarLabel(label) === 'cobra') {
    opcion.label = 'Cebra';
    if (value === 'cobra') opcion.value = 'cebra';
  }
  return opcion;
}

function derivarFamilia(tipo: TipoJuego, opciones: OpcionCatalogo[]): FamiliaOpciones {
  if (tipo === 'animalitos') return 'animalitos';
  if (tipo === 'terminales') return 'terminal';
  // tripletas: zodiacal si TODAS las opciones son signos (numero null);
  // si no, numérica 00..99 (trio-activo, triple-facil).
  return opciones.every((o) => o.numero === null) ? 'zodiacal' : 'numerica';
}

/**
 * Valida el JSON crudo, deriva la familia por juego, aplica la salvaguarda
 * Cobra→Cebra e indexa por id y slug (REQ-CL-01).
 */
export function cargarCatalogo(raw: unknown): Catalogo {
  if (typeof raw !== 'object' || raw === null) {
    throw new Error('Catálogo inválido: se esperaba un objeto');
  }
  const fuente = raw as { version?: unknown; juegos?: unknown };
  if (!esNumero(fuente.version)) {
    throw new Error('Catálogo inválido: falta "version" numérica');
  }
  if (!Array.isArray(fuente.juegos)) {
    throw new Error('Catálogo inválido: falta "juegos" (array)');
  }

  const juegos: JuegoCatalogo[] = fuente.juegos.map((item, indice) => {
    if (typeof item !== 'object' || item === null) {
      throw new Error(`juegos[${indice}]: no es un objeto`);
    }
    const juego = item as Record<string, unknown>;
    if (!esNumero(juego.id)) throw new Error(`juegos[${indice}]: falta "id" numérico`);
    if (typeof juego.slug !== 'string' || !juego.slug) {
      throw new Error(`juegos[${indice}]: falta "slug"`);
    }
    if (typeof juego.nombre !== 'string' || !juego.nombre) {
      throw new Error(`juegos[${indice}]: falta "nombre"`);
    }
    if (typeof juego.tipo !== 'string' || !TIPOS_VALIDOS.includes(juego.tipo as TipoJuego)) {
      throw new Error(`juegos[${indice}]: "tipo" inválido`);
    }
    // Motor-premios: el espejo legacy puede ser null en juegos inactivos/no
    // vendibles (la-ricachona); la clave debe existir con número o null.
    if (juego.premio_multiplo !== null && !esNumero(juego.premio_multiplo)) {
      throw new Error(`juegos[${indice}]: falta "premio_multiplo" numérico o null`);
    }
    // Motor-premios: sin campo (pre-motor) se asume activo/vendible; con campo
    // se respeta el estado exportado (vendible cae a active si falta).
    const active = typeof juego.active === 'boolean' ? juego.active : true;
    const vendible = typeof juego.vendible === 'boolean' ? juego.vendible : active;
    if (!Array.isArray(juego.horarios) || juego.horarios.length === 0) {
      throw new Error(`juegos[${indice}]: faltan "horarios"`);
    }
    if (!Array.isArray(juego.opciones)) {
      throw new Error(`juegos[${indice}]: faltan "opciones"`);
    }
    // Un juego vendible no puede quedar sin opciones; uno inactivo/no vendible
    // sí (la-ricachona en el contrato del motor).
    if (vendible && juego.opciones.length === 0) {
      throw new Error(`juegos[${indice}]: juego vendible sin "opciones"`);
    }

    const opciones = juego.opciones.map((o, i) => {
      if (typeof o !== 'object' || o === null) throw new Error(`opciones[${i}]: no es un objeto`);
      return sanitizarOpcion(o as Record<string, unknown>, i);
    });
    const tipo = juego.tipo as TipoJuego;

    return {
      id: juego.id as number,
      slug: juego.slug,
      nombre: juego.nombre,
      tipo,
      premio_multiplo: juego.premio_multiplo as number | null,
      active,
      vendible,
      comodines: (juego.comodines as Record<string, unknown> | null) ?? null,
      modalidades: (juego.modalidades as Record<string, unknown> | null) ?? null,
      horarios: juego.horarios as string[],
      opciones,
      familia: derivarFamilia(tipo, opciones),
    };
  });

  const porId = new Map<number, JuegoCatalogo>(juegos.map((j) => [j.id, j]));
  const porSlug = new Map<string, JuegoCatalogo>(juegos.map((j) => [j.slug, j]));
  return { version: fuente.version as number, juegos, porId, porSlug };
}

/**
 * Letter-jump cíclico (REQ-KB-06): devuelve el índice de la primera opción
 * cuya label (normalizada, sin acentos) empieza con la letra. Si `desde` es
 * una coincidencia, devuelve la siguiente tras ella (cicla al inicio). null si
 * no hay coincidencias.
 */
export function saltarPorLetra(juego: JuegoCatalogo, letra: string, desde?: number): number | null {
  const letraNorm = normalizarLabel(letra).slice(0, 1);
  if (!letraNorm) return null;
  const coincidencias: number[] = [];
  juego.opciones.forEach((opcion, indice) => {
    if (normalizarLabel(opcion.label).startsWith(letraNorm)) coincidencias.push(indice);
  });
  if (coincidencias.length === 0) return null;
  if (desde === undefined) return coincidencias[0];
  const pos = coincidencias.indexOf(desde);
  if (pos === -1) return coincidencias[0];
  return coincidencias[(pos + 1) % coincidencias.length];
}

/** Clave numérica de búsqueda por dígito según la familia (A5). */
function claveNumerica(opcion: OpcionCatalogo, familia: FamiliaOpciones): string | null {
  if (opcion.numero === null) return null;
  if (familia === 'terminal' || familia === 'numerica') {
    return String(opcion.numero).padStart(2, '0');
  }
  return String(opcion.numero);
}

/**
 * Signo zodiacal por POSICIÓN 1-12 (win-fixes2 FIX B): con triple_c activo los
 * dígitos 1-12 seleccionan el signo correspondiente (el índice se muestra en
 * cada botón). Solo aplica a la familia zodiacal; fuera de rango (0, >12, no
 * numérico) devuelve null. La posición es el índice en `juego.opciones` + 1,
 * que replica el orden del plugin Tripletas (ARI..PIS, Tripletas.php:10-13).
 */
export function signoPorPosicion(juego: JuegoCatalogo, digitos: string): OpcionCatalogo | null {
  if (juego.familia !== 'zodiacal') return null;
  if (!/^\d{1,2}$/.test(digitos)) return null;
  const posicion = parseInt(digitos, 10);
  if (posicion < 1 || posicion > 12) return null;
  return juego.opciones[posicion - 1] ?? null;
}

/**
 * Sigla (value) del signo zodiacal por su label (win-fixes2 FIX E): el
 * backend Tripletas.php valida `strtoupper($signo) ∈ siglas` (ARI, TAU, …), NO
 * el label ("Sagitario"). null si el label no existe o no es zodiacal.
 */
export function siglaDeSigno(juego: JuegoCatalogo, label: string): string | null {
  if (juego.familia !== 'zodiacal') return null;
  const opcion = juego.opciones.find((o) => o.label === label);
  return opcion ? opcion.value : null;
}

/**
 * Label del signo zodiacal por su sigla (value), inverso de siglaDeSigno: el
 * ticket impreso muestra el nombre ("Sagitario"), no la sigla ("SAG"), para
 * que el jugador lo lea sin ambigüedad. null si no es zodiacal o no existe.
 */
export function labelDeSigno(juego: JuegoCatalogo, sigla: string): string | null {
  if (juego.familia !== 'zodiacal') return null;
  const objetivo = String(sigla ?? '').trim().toUpperCase();
  if (!objetivo) return null;
  const opcion = juego.opciones.find((o) => o.value.toUpperCase() === objetivo);
  return opcion ? opcion.label : null;
}

/**
 * Número de tripleta numérica normalizado a 3 dígitos (win-fixes2 FIX D):
 * trio-activo/triple-facil ofrecen 100 opciones 00-99 pero el plugin
 * Tripletas valida `^\d{3}$` (Tripletas.php:36-47). La selección de 1-3
 * cifras se expande a 3 ("05"→"005"); null si no son dígitos (1-3).
 */
export function normalizarNumeroTriple(digitos: string): string | null {
  const limpio = String(digitos ?? '').trim();
  if (!/^\d{1,3}$/.test(limpio)) return null;
  return limpio.padStart(3, '0');
}

/**
 * Búsqueda por dígito (REQ-KB-06, REQ-CL-03): devuelve TODAS las coincidencias
 * exactas (Ballena/Delfín comparten numero 0) para el buffer de dígitos ya
 * confirmado. Terminal "05" matchea label y clave numérica → numero 5.
 */
export function buscarPorDigito(juego: JuegoCatalogo, digitos: string): OpcionCatalogo[] {
  const limpiados = digitos.trim();
  if (!/^\d+$/.test(limpiados)) return [];
  return juego.opciones.filter((opcion) => {
    const clave = claveNumerica(opcion, juego.familia);
    return clave === limpiados || normalizarLabel(opcion.label) === limpiados;
  });
}

/**
 * Buffer multi-dígito (A5): acumula dígitos y dispara `onResult` a los
 * `intervaloMs` de inactividad o al confirmar con Enter (commit). No toca el
 * DOM; el consumidor (dashboard, PR2) decide qué hacer con las coincidencias.
 */
export function crearBuscadorDigitos(
  juego: JuegoCatalogo,
  onResult: (coincidencias: OpcionCatalogo[], buffer: string) => void,
  intervaloMs = 700,
): BuscadorDigitos {
  let bufferActual = '';
  let temporizador: ReturnType<typeof setTimeout> | null = null;

  function disparar(): void {
    onResult(buscarPorDigito(juego, bufferActual), bufferActual);
  }

  function limpiarTemporizador(): void {
    if (temporizador !== null) {
      clearTimeout(temporizador);
      temporizador = null;
    }
  }

  function teclear(digito: string): void {
    if (!/^\d$/.test(digito)) return;
    if (bufferActual.length >= MAX_DIGITOS) return;
    bufferActual += digito;
    limpiarTemporizador();
    temporizador = setTimeout(() => {
      temporizador = null;
      disparar();
    }, intervaloMs);
  }

  function commit(): void {
    limpiarTemporizador();
    disparar();
  }

  function limpiar(): void {
    limpiarTemporizador();
    bufferActual = '';
  }

  return { teclear, commit, limpiar, buffer: () => bufferActual };
}