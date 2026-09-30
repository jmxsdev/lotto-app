import { icono } from './iconos.ts';

export function showModal({ message, type = 'info' }) {
  return new Promise((resolve) => {
    const icons = { success: 'circle-check', error: 'circle-x', confirm: 'triangle-alert', info: 'info' };
    const icon = icons[type] || icons.info;
    const buttons = type === 'confirm'
      ? `<button class="modal-btn modal-btn-secondary cancel-btn">${icono('x')} No</button>
         <button class="modal-btn modal-btn-danger ok-btn">${icono('check')} Sí</button>`
      : `<button class="modal-btn modal-btn-primary ok-btn">${icono('check')} Aceptar</button>`;
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `<div class="modal-dialog">
      <div class="modal-icon">${icono(icon)}</div>
      <p class="modal-message">${message}</p>
      <div class="modal-buttons">${buttons}</div>
    </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.ok-btn')?.addEventListener('click', () => { overlay.remove(); resolve(true); });
    overlay.querySelector('.cancel-btn')?.addEventListener('click', () => { overlay.remove(); resolve(false); });
    overlay.addEventListener('click', (e) => { if (e.target === overlay) { overlay.remove(); resolve(false); } });
  });
}
