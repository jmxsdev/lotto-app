// Spooler adapter. sendRawCommand is a STATIC method; never instantiate PosPrinter.
import type { PosPrinterLike, SendRawOptions, PrintError, PrintErrorCode } from '../types';

const DEFAULT_TIMEOUT_MS = 15000;

function fail(code: PrintErrorCode, message: string, details?: unknown): PrintError {
  const error = new Error(message) as PrintError;
  error.code = code;
  error.details = details;
  return error;
}
/**
 * Lazy loader: the package may be absent in a pure test worktree.
 */
function loadPosPrinter(): PosPrinterLike {
  const mod = require('electron-pos-printer');
  return (mod && (mod.PosPrinter || (mod.default && mod.default.PosPrinter))) || mod;
}
/**
 * Accepts the module/class directly or a zero-arg loader returning it.
 */
function resolvePrinter(posPrinter: PosPrinterLike | (() => PosPrinterLike)): PosPrinterLike {
  let printer: any = posPrinter;
  try {
    if (typeof printer === 'function' && typeof printer.sendRawCommand !== 'function') printer = printer();
  } catch (error) {
    throw fail('E_TRANSPORT_UNAVAILABLE', 'No se pudo cargar electron-pos-printer.', { cause: error && error.message });
  }
  if (!printer || typeof printer.sendRawCommand !== 'function') {
    throw fail('E_TRANSPORT_UNAVAILABLE', 'electron-pos-printer no esta disponible o no expone sendRawCommand.');
  }
  return printer;
}
function runWithTimeout(printerName: string, invoke: () => Promise<void>, timeoutMs: number): Promise<void> {
  const reason = 'Verifique la impresora y la cola antes de reimprimir; no se reintento automaticamente.';
  return new Promise((resolve, reject) => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      reject(fail('E_TRANSPORT_TIMEOUT', `No se pudo enviar el documento a "${printerName}": tiempo de espera agotado. ${reason}`));
    }, timeoutMs);
    Promise.resolve().then(invoke).then(
      () => { if (settled) return; settled = true; clearTimeout(timer); resolve(); },
      (error) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        reject(fail('E_TRANSPORT_FAILED', `No se pudo enviar el documento a "${printerName}": ${error && error.message}. ${reason}`, { cause: error && error.message }));
      },
    );
  });
}

async function sendRaw(printerName: string, buffer: Buffer, { timeoutMs = DEFAULT_TIMEOUT_MS, posPrinter }: SendRawOptions = {}): Promise<void> {
  if (typeof printerName !== 'string' || printerName.trim() === '' || !Buffer.isBuffer(buffer)) {
    throw fail('E_TRANSPORT_INVALID', 'Parametros de transporte invalidos: nombre de impresora y Buffer son obligatorios.');
  }
  let printer: PosPrinterLike;
  if (posPrinter !== undefined) {
    printer = resolvePrinter(posPrinter);
  } else {
    let loaded: PosPrinterLike;
    try {
      loaded = loadPosPrinter();
    } catch (error) {
      throw fail('E_TRANSPORT_UNAVAILABLE', 'No se pudo cargar electron-pos-printer.', { cause: error && error.message });
    }
    printer = resolvePrinter(loaded);
  }
  return runWithTimeout(printerName, () => printer.sendRawCommand(printerName, buffer), timeoutMs);
}
export { DEFAULT_TIMEOUT_MS, sendRaw };
