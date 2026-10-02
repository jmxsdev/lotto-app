// Auto-update OTA (TQ-10, U3): aviso obligatorio compartido por index.astro y
// dashboard.astro (D5). Reemplaza el banner notify-only del splash.
//
// Reglas (spec taquilla-auto-update-instalacion):
// - Aviso prominente al descargarse la actualización, con "Reiniciar e
//   instalar ahora"; SIN opción de ocultado permanente.
// - Postergar SOLO con venta/ticket en curso (busy) y confirmación explícita.
// - Sin venta en curso no se posterga: el aviso se mantiene.
// - Reaparición al terminar la condición que permitió postergar, o al reabrir
//   la app (rehidratación vía update:get-state).
// - Error/offline: silencio absoluto, la app sigue operando (nada bloqueante).
//
// Integración con el guard de venta (D4): el renderer responde a
// update:query-busy con su estado en vivo y empuja reportBusy ante cambios.

export type UpdateStatus = 'idle' | 'checking' | 'downloading' | 'downloaded' | 'error';

export interface UpdateState {
  status: UpdateStatus;
  version?: string | null;
  percent?: number | null;
  error?: string | null;
}

/**
 * Puro: decide la vista del aviso. `visible` solo cuando la actualización está
 * descargada; `puedePostergar` solo con venta/ticket en curso (busy).
 */
export function vistaUpdate(
  status: UpdateStatus,
  busy: boolean,
): { visible: boolean; puedePostergar: boolean } {
  if (status === 'downloaded') {
    return { visible: true, puedePostergar: busy };
  }
  return { visible: false, puedePostergar: false };
}

// ─── DOM helpers (estilos inline, sin dependencias) ────────────────────────

function crearEl<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  estilos: Partial<CSSStyleDeclaration>,
  texto = '',
): HTMLElementTagNameMap[K] {
  const el = document.createElement(tag);
  Object.assign(el.style, estilos);
  if (texto) el.textContent = texto;
  return el;
}

const MODAL_ID = 'taquilla-auto-update-modal';
const BADGE_ID = 'taquilla-auto-update-badge';

interface ElectronBridge {
  onUpdateEvent?: (cb: (channel: string, payload: any) => void) => () => void;
  onUpdateBusyQuery?: (cb: (payload: { requestId: number }) => void) => () => void;
  respondBusy?: (requestId: number, busy: boolean) => void;
  reportBusy?: (busy: boolean) => void;
  installUpdate?: () => Promise<{ ok: boolean; reason?: string }>;
  getUpdateState?: () => Promise<UpdateState>;
}

function bridge(): ElectronBridge {
  return (window as any).electron ?? {};
}

/**
 * Monta el aviso obligatorio. `isBusy()` reporta si hay venta/ticket en curso
 * (dashboard: ticketLines.length > 0 || saleInFlight; index: () => false).
 */
export function initAutoUpdate({ isBusy }: { isBusy: () => boolean }): void {
  const api = bridge();
  if (!api.onUpdateEvent || !api.getUpdateState) return; // sin bridge (browser dev)

  let estado: UpdateState = { status: 'idle' };
  let postergado = false;
  let ultimoBusy = false;

  // ── UI ───────────────────────────────────────────────────────────────────
  function quitarModal() {
    document.getElementById(MODAL_ID)?.remove();
  }

  function quitarBadge() {
    document.getElementById(BADGE_ID)?.remove();
  }

  function mostrarBadgeProgreso(percent: number) {
    quitarBadge();
    const badge = crearEl('div', {
      position: 'fixed',
      top: '12px',
      right: '12px',
      zIndex: '99998',
      padding: '0.5rem 0.9rem',
      borderRadius: '8px',
      background: 'rgba(26,42,108,0.95)',
      color: '#fff',
      fontSize: '0.85rem',
      boxShadow: '0 2px 10px rgba(0,0,0,0.35)',
    });
    badge.id = BADGE_ID;
    badge.textContent = `Descargando actualización… ${Math.round(percent)}%`;
    document.body.appendChild(badge);
  }

  function mostrarModal(version: string | null | undefined) {
    quitarBadge();
    const { puedePostergar } = vistaUpdate('downloaded', isBusy());

    const overlay = crearEl('div', {
      position: 'fixed',
      inset: '0',
      zIndex: '99999',
      background: 'rgba(0,0,0,0.55)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
    });
    overlay.id = MODAL_ID;

    const card = crearEl('div', {
      width: 'min(92vw, 420px)',
      padding: '1.4rem 1.6rem',
      borderRadius: '12px',
      background: '#ffffff',
      color: '#1a2a6c',
      fontFamily: 'Inter, system-ui, sans-serif',
      boxShadow: '0 8px 30px rgba(0,0,0,0.4)',
      textAlign: 'center',
    });

    const titulo = crearEl('h2', { margin: '0 0 0.4rem', fontSize: '1.25rem' }, 'Actualización disponible');
    const versionTxt = crearEl('p', { margin: '0 0 0.3rem', fontSize: '0.95rem' },
      version ? `Versión ${version} descargada.` : 'Nueva versión descargada.');
    const nota = crearEl('p', { margin: '0 0 1.1rem', fontSize: '0.85rem', opacity: '0.8' },
      'Reinicie la taquilla para instalar. La instalación no interrumpe ventas en curso.');

    const instalar = crearEl('button', {
      display: 'block',
      width: '100%',
      padding: '0.7rem 1rem',
      marginBottom: '0.5rem',
      border: 'none',
      borderRadius: '8px',
      background: '#1a2a6c',
      color: '#fff',
      fontSize: '1rem',
      fontWeight: '600',
      cursor: 'pointer',
    }, 'Reiniciar e instalar ahora');

    const postergar = crearEl('button', {
      display: 'block',
      width: '100%',
      padding: '0.6rem 1rem',
      border: '1px solid #1a2a6c',
      borderRadius: '8px',
      background: 'transparent',
      color: '#1a2a6c',
      fontSize: '0.9rem',
      cursor: 'pointer',
    }, 'Postergar');

    if (!puedePostergar) {
      postergar.disabled = true;
      postergar.style.opacity = '0.45';
      postergar.style.cursor = 'not-allowed';
      postergar.title = 'Solo se puede postergar con una venta en curso';
    }

    instalar.addEventListener('click', async () => {
      const res = await api.installUpdate?.();
      if (!res) return;
      if (!res.ok && res.reason === 'busy') {
        // Venta en curso detectada al momento de instalar: el aviso permanece.
        mostrarModal(estado.version);
      }
    });

    postergar.addEventListener('click', () => {
      if (!isBusy()) {
        // Sin venta en curso NO se posterga (spec): el aviso se mantiene.
        mostrarModal(estado.version);
        return;
      }
      const ok = window.confirm('¿Postergar la instalación? La actualización se instalará al cerrar la taquilla.');
      if (ok) {
        postergado = true;
        quitarModal();
      }
    });

    card.append(titulo, versionTxt, nota, instalar, postergar);
    overlay.appendChild(card);
    document.body.appendChild(overlay);
  }

  // ── Eventos del updater (main → renderer) ────────────────────────────────
  const off = api.onUpdateEvent((channel, payload) => {
    switch (channel) {
      case 'update:available':
        estado = { ...estado, status: 'downloading' };
        break;
      case 'update:progress':
        estado = { ...estado, status: 'downloading', percent: payload?.percent ?? 0 };
        mostrarBadgeProgreso(estado.percent ?? 0);
        break;
      case 'update:downloaded':
        estado = { ...estado, status: 'downloaded', version: payload?.version ?? estado.version, percent: 100 };
        postergado = false;
        quitarBadge();
        mostrarModal(estado.version);
        break;
      case 'update:error':
        // Silencio absoluto (spec): error/offline no bloquean la operación.
        estado = { ...estado, status: 'error', error: payload?.message ?? 'Error de actualización' };
        quitarBadge();
        quitarModal();
        break;
      default:
        break;
    }
  });

  // ── Guard de venta (D4): reply en vivo + cache por cambio ────────────────
  const offBusy = api.onUpdateBusyQuery?.(({ requestId }) => {
    api.respondBusy?.(requestId, isBusy());
  });

  const pollBusy = window.setInterval(() => {
    const busy = isBusy();
    if (busy !== ultimoBusy) {
      ultimoBusy = busy;
      api.reportBusy?.(busy);
    }
    // Reaparición al terminar la condición que permitió postergar (spec).
    if (postergado && !busy && estado.status === 'downloaded') {
      postergado = false;
      mostrarModal(estado.version);
    }
  }, 500);

  // ── Rehidratación (D5): estado actual tras recargar el renderer ──────────
  api.getUpdateState().then((s) => {
    if (!s) return;
    estado = s;
    if (s.status === 'downloaded') {
      postergado = false;
      mostrarModal(s.version);
    } else if (s.status === 'downloading' && typeof s.percent === 'number') {
      mostrarBadgeProgreso(s.percent);
    }
  }).catch(() => {
    // Sin updater activo (dev) o error: silencio.
  });

  // Cleanup si la página se descarga (navegación SPA dentro de Electron).
  window.addEventListener('beforeunload', () => {
    off?.();
    offBusy?.();
    window.clearInterval(pollBusy);
  });
}