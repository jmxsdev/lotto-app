// Shared printer-name guard: printable, 1-128 chars, no C0/C1 controls.
const MAX_PRINTER_NAME_LENGTH = 128;
const CONTROL_CHARS = /[\u0000-\u001F\u007F-\u009F]/;

function isSafePrinterName(name: unknown): boolean {
  if (typeof name !== 'string') return false;
  const trimmed = name.trim();
  return trimmed.length >= 1
    && trimmed.length <= MAX_PRINTER_NAME_LENGTH
    && !CONTROL_CHARS.test(trimmed);
}

export { MAX_PRINTER_NAME_LENGTH, isSafePrinterName };
