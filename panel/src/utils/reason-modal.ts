import type { ReasonPayload } from './api.ts';

export type { ReasonPayload } from './api.ts';

/**
 * Closed set of reason codes accepted by the backend `AuditReason` enum.
 * Labels stay in Spanish because the panel UI is Spanish.
 */
const REASON_OPTIONS: { value: string; label: string }[] = [
  { value: 'duplicate_account', label: 'Cuenta duplicada' },
  { value: 'employee_offboarding', label: 'Retiro de empleado' },
  { value: 'fraud_suspicion', label: 'Sospecha de fraude' },
  { value: 'data_correction', label: 'Corrección de datos' },
  { value: 'business_decision', label: 'Decisión de negocio' },
  { value: 'security_incident', label: 'Incidente de seguridad' },
  { value: 'operational_error', label: 'Error operativo' },
  { value: 'other', label: 'Otro (describir)' },
  { value: 'unspecified', label: 'Sin especificar' },
];

const TEXT_MAX = 500;
const OTHER_MIN = 3;

export interface ReasonModalOptions {
  title: string;
  action: string;
  required: boolean;
  initial?: Partial<ReasonPayload>;
}

/**
 * Opens a reusable reason dialog and resolves with the captured payload.
 *
 * - `required`: no “Sin motivo” option, excludes `unspecified`, and confirm
 *   stays disabled until a code is selected.
 * - `required=false`: “— Sin motivo —” resolves `{ reason_code: '', reason_text: '' }`.
 * - `other` always requires at least `OTHER_MIN` characters of text.
 * - Closing via X, overlay, or Escape resolves `null` (the caller aborts).
 *
 * The modal never performs a request; it only returns the reason.
 */
export function openReasonModal(opts: ReasonModalOptions): Promise<ReasonPayload | null> {
  return new Promise((resolve) => {
    const uid = 'reason-modal-' + Math.random().toString(36).slice(2, 8);
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    let settled = false;

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.zIndex = '10000';
    overlay.dataset.action = opts.action;

    const dialog = document.createElement('div');
    dialog.className = 'modal-dialog';
    dialog.style.textAlign = 'left';
    dialog.style.maxWidth = '460px';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', uid + '-title');

    const header = document.createElement('div');
    header.style.display = 'flex';
    header.style.alignItems = 'center';
    header.style.justifyContent = 'space-between';
    header.style.gap = '12px';
    header.style.marginBottom = '16px';

    const title = document.createElement('h3');
    title.id = uid + '-title';
    title.textContent = opts.title;
    title.style.margin = '0';
    title.style.fontSize = '17px';
    title.style.fontWeight = '700';
    title.style.color = '#1e293b';

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Cerrar');
    closeBtn.textContent = '×';
    closeBtn.style.border = 'none';
    closeBtn.style.background = 'transparent';
    closeBtn.style.color = '#64748b';
    closeBtn.style.fontSize = '22px';
    closeBtn.style.lineHeight = '1';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.padding = '0 4px';

    header.append(title, closeBtn);

    const codeLabel = document.createElement('label');
    codeLabel.htmlFor = uid + '-code';
    codeLabel.textContent = 'Motivo';
    codeLabel.style.display = 'block';
    codeLabel.style.marginBottom = '6px';
    codeLabel.style.fontSize = '13px';
    codeLabel.style.fontWeight = '600';
    codeLabel.style.color = '#334155';

    const select = document.createElement('select');
    select.id = uid + '-code';
    select.style.width = '100%';
    select.style.padding = '10px';
    select.style.border = '2px solid #e2e8f0';
    select.style.borderRadius = '6px';
    select.style.fontSize = '14px';
    select.style.marginBottom = '14px';

    if (opts.required) {
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Seleccione un motivo...';
      placeholder.disabled = true;
      placeholder.selected = true;
      select.appendChild(placeholder);
    } else {
      const none = document.createElement('option');
      none.value = '';
      none.textContent = '— Sin motivo —';
      select.appendChild(none);
    }

    for (const option of REASON_OPTIONS) {
      if (opts.required && option.value === 'unspecified') continue;
      const el = document.createElement('option');
      el.value = option.value;
      el.textContent = option.label;
      select.appendChild(el);
    }

    const initialCode = opts.initial?.reason_code;
    if (initialCode && Array.from(select.options).some((option) => option.value === initialCode)) {
      select.value = initialCode;
    }

    const textLabel = document.createElement('label');
    textLabel.htmlFor = uid + '-text';
    textLabel.textContent = 'Detalle (opcional)';
    textLabel.style.display = 'block';
    textLabel.style.marginBottom = '6px';
    textLabel.style.fontSize = '13px';
    textLabel.style.fontWeight = '600';
    textLabel.style.color = '#334155';

    const textarea = document.createElement('textarea');
    textarea.id = uid + '-text';
    textarea.rows = 3;
    textarea.maxLength = TEXT_MAX;
    textarea.value = opts.initial?.reason_text ?? '';
    textarea.style.width = '100%';
    textarea.style.padding = '10px';
    textarea.style.border = '2px solid #e2e8f0';
    textarea.style.borderRadius = '6px';
    textarea.style.fontSize = '14px';
    textarea.style.fontFamily = 'inherit';
    textarea.style.resize = 'vertical';

    const meta = document.createElement('div');
    meta.style.display = 'flex';
    meta.style.justifyContent = 'space-between';
    meta.style.gap = '12px';
    meta.style.margin = '6px 0 12px';
    meta.style.fontSize = '12px';
    meta.style.color = '#64748b';

    const hint = document.createElement('span');
    hint.textContent = 'No incluya datos personales.';

    const counter = document.createElement('span');
    counter.setAttribute('aria-live', 'polite');

    meta.append(hint, counter);

    const error = document.createElement('p');
    error.setAttribute('role', 'alert');
    error.style.minHeight = '16px';
    error.style.margin = '0 0 12px';
    error.style.fontSize = '12px';
    error.style.color = '#dc2626';

    const buttons = document.createElement('div');
    buttons.style.display = 'flex';
    buttons.style.justifyContent = 'flex-end';
    buttons.style.gap = '8px';

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'modal-btn modal-btn-secondary';
    cancelBtn.textContent = 'Cancelar';

    const confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'modal-btn modal-btn-primary';
    confirmBtn.textContent = 'Confirmar';

    buttons.append(cancelBtn, confirmBtn);
    dialog.append(header, codeLabel, select, textLabel, textarea, meta, error, buttons);
    overlay.appendChild(dialog);

    function updateCounter(): void {
      counter.textContent = `${textarea.value.length}/${TEXT_MAX}`;
    }

    function validate(): void {
      const code = select.value;
      const text = textarea.value.trim();
      let valid = true;
      let message = '';

      if (opts.required && code === '') {
        valid = false;
      }
      if (code === 'other' && text.length < OTHER_MIN) {
        valid = false;
        message = `Describa el motivo (mínimo ${OTHER_MIN} caracteres).`;
      }

      confirmBtn.disabled = !valid;
      confirmBtn.style.opacity = valid ? '1' : '.5';
      confirmBtn.style.cursor = valid ? 'pointer' : 'not-allowed';
      error.textContent = message;
    }

    function settle(result: ReasonPayload | null): void {
      if (settled) return;
      settled = true;
      document.removeEventListener('keydown', onKeydown, true);
      overlay.remove();
      if (previousFocus && previousFocus.isConnected) previousFocus.focus();
      resolve(result);
    }

    function onKeydown(event: KeyboardEvent): void {
      if (event.key === 'Escape') {
        event.stopPropagation();
        settle(null);
      }
    }

    select.addEventListener('change', validate);
    textarea.addEventListener('input', () => {
      updateCounter();
      validate();
    });
    closeBtn.addEventListener('click', () => settle(null));
    cancelBtn.addEventListener('click', () => settle(null));
    confirmBtn.addEventListener('click', () => {
      if (confirmBtn.disabled) return;
      const code = select.value;
      if (code === '') {
        settle({ reason_code: '', reason_text: '' });
        return;
      }
      settle({ reason_code: code, reason_text: textarea.value.trim() });
    });
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) settle(null);
    });
    document.addEventListener('keydown', onKeydown, true);

    document.body.appendChild(overlay);
    updateCounter();
    validate();
    select.focus();
  });
}
