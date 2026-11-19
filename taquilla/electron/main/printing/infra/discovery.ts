// Exact-queue discovery through getPrintersAsync. No substring matching.
import { isSafePrinterName } from './names';
import type {
  GetPrintersAsync, ListPrintersInput, PrinterInfo, PrinterListResult, PrinterRef,
  PrintError, PrintErrorCode, ResolvePrinterInput,
} from '../types';

const DEFAULT_TIMEOUT_MS = 5000;
const SUGGEST_RE = /pos|thermal|ticket|receipt|58|80|xp|tm/i;

function fail(code: PrintErrorCode, message: string, details?: unknown): PrintError {
  const error = new Error(message) as PrintError;
  error.code = code;
  error.details = details;
  return error;
}
const normalize = (printers: unknown): PrinterInfo[] => {
  if (!Array.isArray(printers)) return [];
  const found: PrinterInfo[] = [];
  for (const entry of printers) {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) continue;
    const name = (entry as PrinterInfo).name;
    if (typeof name !== 'string' || name.trim() === '') continue;
    found.push({ ...(entry as PrinterInfo), name });
  }
  return found;
};
const namesOf = (printers: readonly PrinterInfo[]): string[] => printers.map((printer) => printer.name);
const listText = (names: string[]): string => (names.length ? names.join(', ') : 'ninguna');

function enumerate(getPrintersAsync: GetPrintersAsync | undefined, timeoutMs: number): Promise<PrinterInfo[]> {
  if (typeof getPrintersAsync !== 'function') {
    return Promise.reject(fail('E_DISCOVERY_TIMEOUT', 'No se pudo enumerar las impresoras: funcion no disponible.', { available: [], suggested: null }));
  }
  return new Promise((resolve, reject) => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      reject(fail('E_DISCOVERY_TIMEOUT', `No se pudo enumerar las impresoras en ${timeoutMs} ms.`, { available: [], suggested: null }));
    }, timeoutMs);
    Promise.resolve().then(getPrintersAsync).then(
      (printers) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(normalize(printers));
      },
      (error) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        reject(fail('E_DISCOVERY_TIMEOUT', `Fallo la enumeracion de impresoras: ${error && error.message}.`, {
          available: [], suggested: null, cause: error && error.message,
        }));
      },
    );
  });
}

function suggest(printers: readonly PrinterInfo[]): string | null {
  if (printers.length === 1) return printers[0].name;
  const match = printers.find((printer) => SUGGEST_RE.test(printer.name)
    || (typeof printer.displayName === 'string' && SUGGEST_RE.test(printer.displayName)));
  return match ? match.name : null;
}

async function listPrinters({ getPrintersAsync, timeoutMs = DEFAULT_TIMEOUT_MS }: ListPrintersInput = {}): Promise<PrinterListResult> {
  const printers = await enumerate(getPrintersAsync, timeoutMs);
  return { printers, suggested: suggest(printers) };
}

async function resolvePrinter({ printerName, getPrintersAsync, timeoutMs = DEFAULT_TIMEOUT_MS }: ResolvePrinterInput = {}): Promise<PrinterRef> {
  const name = typeof printerName === 'string' ? printerName.trim() : '';

  if (name === '') {
    const { printers, suggested } = await listPrinters({ getPrintersAsync, timeoutMs }).catch(() => ({ printers: [], suggested: null }));
    const available = namesOf(printers);
    const suffix = available.length ? ` Impresoras disponibles: ${listText(available)}.` : '';
    const hint = suggested ? ` Sugerida: ${suggested}.` : '';
    throw fail('E_PRINTER_UNCONFIGURED', `Impresora no configurada. Defina printerName en printer-config.json o use Configuracion de impresora.${suffix}${hint}`, { available, suggested });
  }

  if (!isSafePrinterName(name)) {
    throw fail('E_CONFIG_INVALID', 'Nombre de impresora invalido: no puede contener caracteres de control ni superar 128 caracteres.', { printerName: name });
  }

  const { printers, suggested } = await listPrinters({ getPrintersAsync, timeoutMs });
  const available = namesOf(printers);
  if (available.length === 0) {
    throw fail('E_PRINTER_NOT_FOUND', 'No hay impresoras instaladas. Instale o comparta la impresora termica y reintente.', { available, suggested: null });
  }

  const match = printers.find((printer) => printer.name === name)
    || printers.find((printer) => printer.name.toLowerCase() === name.toLowerCase());
  if (!match) {
    const hint = suggested ? ` Sugerida: ${suggested}.` : '';
    const message = `Impresora "${name}" no encontrada. Disponibles: ${listText(available)}.${hint} Configure printerName en printer-config.json o use Configuracion de impresora.`;
    throw fail('E_PRINTER_NOT_FOUND', message, { available, suggested });
  }
  return { name: match.name };
}

export { DEFAULT_TIMEOUT_MS, isSafePrinterName, listPrinters, resolvePrinter };
