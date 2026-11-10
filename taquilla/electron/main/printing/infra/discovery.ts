// Exact-queue discovery through getPrintersAsync. No substring matching.
import type { GetPrintersAsync, PrinterInfo, PrinterRef, PrintError, PrintErrorCode, ResolvePrinterInput } from '../types';

const NAME_RE = /^[\p{L}\p{N} ._#,-]{1,128}$/u;
const DEFAULT_TIMEOUT_MS = 5000;
const INVALID_NAME = 'Nombre de impresora invalido. Use solo letras, numeros, espacios, punto, guion, guion bajo, # y coma.';

function fail(code: PrintErrorCode, message: string, details?: unknown): PrintError {
  const error = new Error(message) as PrintError;
  error.code = code;
  error.details = details;
  return error;
}
const namesOf = (printers: readonly (string | PrinterInfo)[] | unknown): string[] => (Array.isArray(printers) ? printers : [])
  .map((printer) => (typeof printer === 'string' ? printer : printer && printer.name))
  .filter(Boolean);
const listText = (names: string[]): string => (names.length ? names.join(', ') : 'ninguna');

function enumerate(getPrintersAsync: GetPrintersAsync | undefined, timeoutMs: number): Promise<string[]> {
  if (typeof getPrintersAsync !== 'function') {
    return Promise.reject(fail('E_DISCOVERY_TIMEOUT', 'No se pudo enumerar las impresoras: funcion no disponible.'));
  }
  return new Promise((resolve, reject) => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      reject(fail('E_DISCOVERY_TIMEOUT', `No se pudo enumerar las impresoras en ${timeoutMs} ms.`));
    }, timeoutMs);
    Promise.resolve().then(getPrintersAsync).then(
      (printers) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(namesOf(printers));
      },
      (error) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        reject(fail('E_DISCOVERY_TIMEOUT', `Fallo la enumeracion de impresoras: ${error && error.message}.`));
      },
    );
  });
}

async function resolvePrinter({ printerName, getPrintersAsync, timeoutMs = DEFAULT_TIMEOUT_MS }: ResolvePrinterInput = {}): Promise<PrinterRef> {
  const name = typeof printerName === 'string' ? printerName : '';

  if (name.trim() === '') {
    const available = await enumerate(getPrintersAsync, timeoutMs).catch(() => []);
    const suffix = available.length ? ` Impresoras disponibles: ${listText(available)}.` : '';
    throw fail('E_PRINTER_UNCONFIGURED', `Impresora no configurada. Defina LOTTO_PRINTER_NAME o printer-config.json.${suffix}`, { available });
  }

  if (!NAME_RE.test(name)) throw fail('E_CONFIG_INVALID', INVALID_NAME, { printerName: name });

  const available = await enumerate(getPrintersAsync, timeoutMs);
  if (available.length === 0) {
    throw fail('E_PRINTER_NOT_FOUND', 'No hay impresoras instaladas. Instale o comparta la impresora termica y reintente.', { available });
  }

  const match = available.find((item) => item === name) || available.find((item) => item.toLowerCase() === name.toLowerCase());
  if (!match) {
    const message = `Impresora "${name}" no encontrada. Disponibles: ${listText(available)}. Corrija LOTTO_PRINTER_NAME o printer-config.json.`;
    throw fail('E_PRINTER_NOT_FOUND', message, { available });
  }
  return { name: match };
}

export { DEFAULT_TIMEOUT_MS, NAME_RE, resolvePrinter };
