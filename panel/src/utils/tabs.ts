// Utilidad de pestañas con persistencia en URL.
// Patrón compartido por las páginas de detalle de entidades
// (bancas, grupos, taquillas): ?tab=limites abre directamente la pestaña
// indicada y el cambio de pestaña se persiste con history.replaceState
// sin recargar la página.

export const ENTITY_TABS = ['informacion', 'monedas', 'limites', 'usuarios'];

/** Lee un parámetro de la query string actual. */
export function getParam(name: string): string | null {
  if (typeof window === 'undefined') return null;
  return new URLSearchParams(window.location.search).get(name);
}

/** Actualiza un parámetro en la URL con history.replaceState, preservando el resto. */
export function setParam(name: string, value: string): void {
  if (typeof window === 'undefined') return;
  const params = new URLSearchParams(window.location.search);
  params.set(name, value);
  window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
}

/** Lee la pestaña inicial desde la URL (?tab=...) validándola contra las permitidas. */
export function getInitialTab(validTabs: string[] = ENTITY_TABS, fallback = 'informacion'): string {
  const tab = getParam('tab');
  return tab && validTabs.includes(tab) ? tab : fallback;
}

/**
 * Conecta los botones de pestaña ([data-tab]) con sus paneles ([data-panel]),
 * muestra la pestaña inicial de la URL y persiste el cambio en la URL.
 * Devuelve el nombre de la pestaña activa.
 */
export function initTabs(
  containerId: string,
  validTabs: string[] = ENTITY_TABS,
  onShow?: (tab: string) => void,
): string {
  const container = document.getElementById(containerId);
  if (!container) return getInitialTab(validTabs);

  const buttons = Array.from(container.querySelectorAll<HTMLElement>('[data-tab]'));
  const panels = Array.from(document.querySelectorAll<HTMLElement>('[data-panel]'));
  const active = getInitialTab(validTabs);

  const show = (name: string) => {
    buttons.forEach(b => b.classList.toggle('tab-btn-active', b.dataset.tab === name));
    panels.forEach(p => { p.style.display = p.dataset.panel === name ? '' : 'none'; });
    setParam('tab', name);
    if (onShow) onShow(name);
  };

  buttons.forEach(b => b.addEventListener('click', () => show(b.dataset.tab || '')));
  show(active);

  return active;
}
