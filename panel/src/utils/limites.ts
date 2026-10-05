/**
 * Utilidad compartida para la tabla configurable de límites (juego × moneda).
 *
 * Modos:
 * - 'entidad': matriz de UNA entidad (banca/grupo/taquilla) con origen heredado.
 * - 'scope': matriz general de un tipo (todas las bancas/grupos/agencias) con
 *   indicador mixto y guardado masivo por alcance.
 *
 * Semántica de guardado (present-fields-only): solo se envían las celdas que
 * el usuario modificó. Una celda vacía = no tocar. El panel nunca envía nulls
 * (limpiar/heredar se hace por DELETE por fila).
 */

import { icono } from './iconos.ts';
import { showModal } from './modal.ts';

export interface DatosTablaLimites {
  juegos: { id: number; name: string; slug: string; type: string }[];
  limites: Record<string, any | null>; // "juego:moneda" (entidad) | "entidad:juego:moneda" (scope)
  origen?: Record<string, any | null> | null; // solo modo entidad
  entidades?: { id: number; name: string; tipo: string }[]; // solo modo scope
  mixto?: Record<string, boolean> | null; // solo modo scope
}

export interface OpcionesTablaLimites {
  modo: 'entidad' | 'scope';
  tipoEntidad?: 'banca' | 'grupo' | 'taquilla';
  entidadId?: number;
  alcance?: { tipo: 'banca' | 'grupo' | 'taquilla'; id: number } | null;
  mostrarOrigen?: boolean;
  filasPorPagina?: number;
  puedeEditar?: boolean;                    // default true; false => inputs disabled y sin columna Acciones
  eliminar?: (id: number) => Promise<any>;  // ausente => sin botón Limpiar (create mode y modo scope)
  cargarDatos: () => Promise<DatosTablaLimites>;
  guardar: (payload: {
    scope?: { tipo: string; id: number } | null;
    limites: Record<string, any>[];
  }) => Promise<any>;
}

/** Tope de porcentaje de pago por tipo de juego (el servidor es la fuente de verdad). */
export const TOPES: Record<string, number> = {
  animalitos: 16,
  tripletas: 25,
};

/** Tope aplicable a un tipo; cualquier otro/desconocido = 100. */
export function topeDe(type: string): number {
  return TOPES[type] ?? 100;
}

interface Linea {
  juegoId: number;
  juegoName: string;
  juegoType: string;
  moneda: 'bs' | 'usd';
  clave: string; // clave del mapa de límites
  valor: any | null;
  origen: any | null;
  mixto: boolean;
}

const CAMPOS: { campo: string; etiqueta: string }[] = [
  { campo: 'limite_minimo', etiqueta: 'Mínimo' },
  { campo: 'limite_maximo', etiqueta: 'Máximo' },
  { campo: 'porcentaje_pago', etiqueta: '% Pago' },
  { campo: 'participacion', etiqueta: 'Particip.' },
];

export function crearTablaLimites(opts: OpcionesTablaLimites) {
  const filasPorPagina = opts.filasPorPagina ?? 15;
  const puedeEditar = opts.puedeEditar ?? true;
  const mostrarAcciones = opts.modo === 'entidad' && puedeEditar && !!opts.eliminar;
  let datos: DatosTablaLimites = { juegos: [], limites: {} };
  let lineas: Linea[] = [];
  let pagina = 0;
  let busqueda = '';
  const tocadas = new Map<string, Record<string, any>>(); // clave -> {campo: valor}
  let el: HTMLElement | null = null;

  function construirLineas(): Linea[] {
    const lineasTmp: Linea[] = [];
    for (const juego of datos.juegos) {
      for (const moneda of ['bs', 'usd'] as const) {
        const clave = opts.modo === 'scope' && opts.alcance?.id
          ? `${opts.alcance.id}:${juego.id}:${moneda}`
          : `${juego.id}:${moneda}`;
        lineasTmp.push({
          juegoId: juego.id,
          juegoName: juego.name,
          juegoType: juego.type ?? '',
          moneda,
          clave,
          valor: datos.limites[clave] ?? null,
          origen: datos.origen?.[clave] ?? null,
          mixto: opts.modo === 'scope' ? (datos.mixto?.[`${juego.id}:${moneda}`] ?? false) : false,
        });
      }
    }
    return lineasTmp;
  }

  function lineasFiltradas(): Linea[] {
    const q = busqueda.trim().toLowerCase();
    if (!q) return lineas;
    return lineas.filter((l) => l.juegoName.toLowerCase().includes(q));
  }

  function totalPaginas(): number {
    return Math.max(1, Math.ceil(lineasFiltradas().length / filasPorPagina));
  }

  function valorInicial(linea: Linea, campo: string): any {
    const tocada = tocadas.get(linea.clave);
    if (tocada && campo in tocada) return tocada[campo];
    return linea.valor?.[campo] ?? '';
  }

  function pintarPaginacion(arriba: boolean): string {
    const total = totalPaginas();
    const btn = (p: number, label: string, disabled = false) =>
      `<button class="pag-btn" data-pag="${p}" ${disabled ? 'disabled' : ''}>${label}</button>`;
    let nums = '';
    for (let p = 1; p <= total; p++) {
      nums += `<button class="pag-btn ${p === pagina + 1 ? 'pag-activa' : ''}" data-pag="${p}">${p}</button>`;
    }
    const cls = arriba ? 'pag-top' : 'pag-bottom';
    return `<div class="${cls} pag-wrap">${btn(pagina, `<span aria-hidden="true">${icono('chevron-left')}</span> Anterior`, pagina === 0)}${nums}${btn(pagina + 2, `Siguiente <span aria-hidden="true">${icono('chevron-right')}</span>`, pagina + 1 >= total)}</div>`;
  }

  function pintarTabla(): string {
    const filtradas = lineasFiltradas();
    const inicio = pagina * filasPorPagina;
    const visibles = filtradas.slice(inicio, inicio + filasPorPagina);

    if (visibles.length === 0) {
      return '<div class="loading">No hay juegos para mostrar.</div>';
    }

    let html = pintarPaginacion(true);
    html += `<div class="table-wrap"><table><thead><tr><th>Juego</th><th>Moneda</th>`;
    for (const c of CAMPOS) html += `<th>${c.etiqueta}</th>`;
    if (opts.mostrarOrigen) html += '<th>Origen</th>';
    if (mostrarAcciones) html += '<th>Acciones</th>';
    html += '</tr></thead><tbody>';

    let juegoActual = 0;
    for (const linea of visibles) {
      const esNuevoJuego = linea.juegoId !== juegoActual;
      juegoActual = linea.juegoId;
      const nombreJuego = esNuevoJuego
        ? `<td rowspan="2"><strong>${linea.juegoName}</strong></td>`
        : '';
      const badge = linea.moneda === 'bs'
        ? '<span class="moneda-badge moneda-bs">BS</span>'
        : '<span class="moneda-badge moneda-usd">USD</span>';

      html += `<tr>${nombreJuego}<td>${badge}</td>`;

      for (const c of CAMPOS) {
        const inicial = valorInicial(linea, c.campo);
        const mixto = linea.mixto && !(linea.clave in tocadas);
        const ph = mixto ? 'placeholder="mixto"' : '';
        const disabled = !puedeEditar ? 'disabled' : '';
        // Tope dinámico por tipo: solo aplica a porcentaje_pago.
        const maxTope = c.campo === 'porcentaje_pago' ? `max="${topeDe(linea.juegoType)}"` : '';
        html += `<td><input type="number" step="0.01" min="0" ${maxTope} data-clave="${linea.clave}" data-campo="${c.campo}" value="${inicial}" ${ph} ${disabled}></td>`;
      }

      if (opts.mostrarOrigen) {
        const origen = linea.origen;
        const txt = origen
          ? `<span class="origen-tag">hereda de ${origen.nivel === 'banca' ? 'Banca' : origen.nivel === 'grupo' ? 'Grupo' : 'Taquilla'}: ${origen.valor ? Object.values(origen.valor)[0] : 'valor'}</span>`
          : '';
        html += `<td>${txt}</td>`;
      }

      if (mostrarAcciones) {
        // Limpiar solo en filas propias (con id): las celdas heredadas vuelven
        // a heredar del nivel superior vía DELETE (decisión A6 del design).
        const boton = linea.valor?.id
          ? `<button class="limpiar-btn" data-id="${linea.valor.id}" data-clave="${linea.clave}" type="button">Limpiar</button>`
          : '';
        html += `<td>${boton}</td>`;
      }

      html += '</tr>';
    }

    html += '</tbody></table></div>';
    html += pintarPaginacion(false);
    return html;
  }

  function montar(contenedor: HTMLElement) {
    el = contenedor;
    contenedor.innerHTML = '<div class="loading">Cargando límites...</div>';
    opts.cargarDatos()
      .then((d) => {
        datos = d;
        lineas = construirLineas();
        pintar();
      })
      .catch((e) => {
        contenedor.innerHTML = '<div class="loading">Error: ' + e.message + '</div>';
      });
  }

  function pintar() {
    if (!el) return;
    el.innerHTML = pintarTabla();
    el.querySelectorAll<HTMLButtonElement>('.pag-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        pagina = parseInt(btn.dataset.pag || '1', 10) - 1;
        pintar();
      });
    });
    el.querySelectorAll<HTMLInputElement>('input[data-clave]').forEach((input) => {
      input.addEventListener('input', () => {
        const clave = input.dataset.clave!;
        const campo = input.dataset.campo!;
        const valor = input.value;
        if (!tocadas.has(clave)) tocadas.set(clave, {});
        const entrada = tocadas.get(clave)!;
        if (valor === '') {
          delete entrada[campo];
        } else {
          entrada[campo] = parseFloat(valor);
        }
      });
    });
    el.querySelectorAll<HTMLButtonElement>('.limpiar-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.id || '0', 10);
        const clave = btn.dataset.clave!;
        const ok = await showModal({
          message: '¿Eliminar este límite? La celda volverá a heredar del nivel superior.',
          type: 'confirm',
        });
        if (!ok) return;
        try {
          await opts.eliminar!(id);
          // Fila borrada: descartar ediciones pendientes y recalcular el origen heredado
          tocadas.delete(clave);
          datos = await opts.cargarDatos();
          lineas = construirLineas();
          pintar();
        } catch (err) {
          await showModal({ message: err.message, type: 'error' });
          // Carrera 404 (fila ya borrada en otro lugar): refrescar sin tocar tocadas
          if ((err as any)?.status === 404) {
            datos = await opts.cargarDatos();
            lineas = construirLineas();
            pintar();
          }
        }
      });
    });
  }

  function buscar(texto: string) {
    busqueda = texto;
    pagina = 0;
    pintar();
  }

  function construirItems(): Record<string, any>[] {
    // Construir ítems solo con campos tocados (present-fields-only)
    const limites: Record<string, any>[] = [];
    for (const [clave, campos] of tocadas) {
      const [juegoId, moneda] = clave.split(':').slice(-2) as [string, string];
      const item: Record<string, any> = {
        juego_id: parseInt(juegoId, 10),
        moneda,
        ...campos,
      };
      limites.push(item);
    }
    return limites;
  }

  /** Ítems modificados (sin enviarlos). Útil para persistir límites junto a la creación de la entidad. */
  function itemsTocados(): Record<string, any>[] {
    return construirItems();
  }

  /** Bloqueo cliente: ningún porcentaje_pago tocado puede superar el tope de su tipo. */
  function validarTopes(limites: Record<string, any>[]): void {
    const tipos = new Map<number, string>(datos.juegos.map((j) => [j.id, j.type ?? '']));

    for (const item of limites) {
      const valor = item.porcentaje_pago;
      if (valor === undefined || valor === null) continue;

      const type = tipos.get(item.juego_id) ?? '';
      const tope = topeDe(type);

      if (Number(valor) > tope) {
        const nombre = datos.juegos.find((j) => j.id === item.juego_id)?.name ?? `#${item.juego_id}`;
        throw new Error(`El % de pago para ${nombre} (${type || 'desconocido'}) no puede superar ${tope}%.`);
      }
    }
  }

  async function guardar(): Promise<any> {
    const limites = construirItems();
    if (limites.length === 0) {
      throw new Error('No hay cambios para guardar.');
    }
    validarTopes(limites);
    const payload: any = { limites };
    if (opts.modo === 'scope' && opts.alcance) {
      payload.scope = opts.alcance;
    }
    const resultado = await opts.guardar(payload);
    tocadas.clear();
    return resultado;
  }

  function hayCambios(): boolean {
    return tocadas.size > 0;
  }

  return { montar, pintar, buscar, guardar, hayCambios, itemsTocados };
}
