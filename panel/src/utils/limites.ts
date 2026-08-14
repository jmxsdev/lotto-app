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

export interface DatosTablaLimites {
  juegos: { id: number; name: string; slug: string }[];
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
  cargarDatos: () => Promise<DatosTablaLimites>;
  guardar: (payload: {
    scope?: { tipo: string; id: number } | null;
    limites: Record<string, any>[];
  }) => Promise<any>;
}

interface Linea {
  juegoId: number;
  juegoName: string;
  moneda: 'bs' | 'usd';
  clave: string; // clave del mapa de límites
  valor: any | null;
  origen: any | null;
  mixto: boolean;
}

const CAMPOS: { campo: string; etiqueta: string; tipo: string }[] = [
  { campo: 'limite_minimo', etiqueta: 'Mínimo', tipo: 'number' },
  { campo: 'limite_maximo', etiqueta: 'Máximo', tipo: 'number' },
  { campo: 'porcentaje_pago', etiqueta: '% Pago', tipo: 'number' },
  { campo: 'participacion', etiqueta: 'Particip.', tipo: 'number' },
  { campo: 'fraccion', etiqueta: 'Fracción', tipo: 'checkbox' },
  { campo: 'limite_tiempo', etiqueta: 'T. Límite', tipo: 'number' },
];

export function crearTablaLimites(opts: OpcionesTablaLimites) {
  const filasPorPagina = opts.filasPorPagina ?? 15;
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
    if (campo === 'fraccion') {
      return linea.valor?.fraccion ? '1' : '';
    }
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
    return `<div class="${cls} pag-wrap">${btn(pagina, '◀ Anterior', pagina === 0)}${nums}${btn(pagina + 2, 'Siguiente ▶', pagina + 1 >= total)}</div>`;
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
        if (c.tipo === 'checkbox') {
          html += `<td><input type="checkbox" data-clave="${linea.clave}" data-campo="${c.campo}" ${inicial === '1' ? 'checked' : ''}></td>`;
        } else {
          html += `<td><input type="number" step="0.01" min="0" data-clave="${linea.clave}" data-campo="${c.campo}" value="${inicial}" ${ph}></td>`;
        }
      }

      if (opts.mostrarOrigen) {
        const origen = linea.origen;
        const txt = origen
          ? `<span class="origen-tag">hereda de ${origen.nivel === 'banca' ? 'Banca' : origen.nivel === 'grupo' ? 'Grupo' : 'Agencia'}: ${origen.valor ? Object.values(origen.valor)[0] : 'valor'}</span>`
          : '';
        html += `<td>${txt}</td>`;
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
        const valor = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
        if (!tocadas.has(clave)) tocadas.set(clave, {});
        const entrada = tocadas.get(clave)!;
        if (valor === '') {
          delete entrada[campo];
        } else {
          entrada[campo] = campo === 'fraccion' ? true : parseFloat(valor);
        }
      });
    });
  }

  function buscar(texto: string) {
    busqueda = texto;
    pagina = 0;
    pintar();
  }

  async function guardar(): Promise<any> {
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
    if (limites.length === 0) {
      throw new Error('No hay cambios para guardar.');
    }
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

  return { montar, pintar, buscar, guardar, hayCambios };
}
