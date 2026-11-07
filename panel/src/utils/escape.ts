// Utilidades de escape para el panel.
// Regla de oro: todo valor de usuario/evento se inserta con textContent
// (o createTextNode). `esc()` solo se usa en atributos o plantillas estáticas.

const ENTIDADES: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
};

/** Escapa entidades HTML de un valor. null/undefined -> ''. */
export function esc(value: unknown): string {
  if (value === null || value === undefined) return '';
  return String(value).replace(/[&<>"']/g, (ch) => ENTIDADES[ch] ?? ch);
}

/** Asigna textContent de forma segura. null/undefined -> ''. */
export function setText(el: Element | null, value: unknown): void {
  if (!el) return;
  el.textContent = value === null || value === undefined ? '' : String(value);
}

/** Normaliza un valor para una celda de tabla: vacío -> '—'. */
export function cell(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'string') return value.trim() === '' ? '—' : value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value);
    } catch {
      return '—';
    }
  }
  return String(value);
}
