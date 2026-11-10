// Composition root for native printing. Pure builders + inert infra + single-flight lock.
import { resolveConfig } from './infra/config';
import { resolvePrinter } from './infra/discovery';
import { sendRaw } from './infra/transport';
import { createLock } from './infra/lock';
import * as ticket from './documents/ticket';
import * as recibo from './documents/recibo';
import * as cierre from './documents/cierre';
import * as reporte from './documents/reporte';
import * as printTest from './documents/printTest';
import type {
  BuildBufferInput,
  CreatePrintingOptions,
  DocumentRenderer,
  PrintData,
  PrintError,
  PrintErrorCode,
  PrintKind,
  PrintRequest,
  PrintResult,
  Printing,
} from './types';

const SUCCESS: Record<PrintKind, string> = {
  ticket: 'Ticket enviado a impresora',
  recibo: 'Ticket enviado a impresora',
  cierre: 'Cierre enviado a impresora',
  reporte: 'Reporte enviado a impresora',
};

// S3a registers the sale ticket; S3b adds the prize receipt; S4b adds close + report.
const BUILDERS: Partial<Record<PrintKind, DocumentRenderer<any>>> = { ticket: ticket.render, recibo: recibo.render, cierre: cierre.render, reporte: reporte.render };

const fail = (code: PrintErrorCode, message: string): PrintError => Object.assign(new Error(message), { code });

function buildBuffer({ kind, data, config }: BuildBufferInput): Buffer {
  const builder = BUILDERS[kind];
  if (!builder) throw fail('E_PAYLOAD_INVALID', `Sin builder nativo para "${kind}".`);
  return builder(data || {}, config);
}

function createPrinting({
  env = process.env,
  userDataPath,
  getWindow, // used only by the S4a startup test; printDocument never needs it.
  sendRaw: sendRawImpl = sendRaw,
  lock = createLock(),
  readFile,
}: CreatePrintingOptions = {}): Printing {
  const resolveEffectiveConfig = () => resolveConfig({ env, userDataPath, readFile });

  async function printDocument({ kind = 'ticket', data, webContents }: Partial<PrintRequest> = {}): Promise<PrintResult> {
    try {
      const config = resolveEffectiveConfig();
      if (!BUILDERS[kind]) throw fail('E_PAYLOAD_INVALID', `Sin builder nativo para "${kind}".`);
      if (!webContents || typeof webContents.getPrintersAsync !== 'function') {
        throw fail('E_NO_WEBCONTENTS', 'No hay ventana activa para imprimir.');
      }
      return await lock.run(async () => {
        const printer = await resolvePrinter({
          printerName: config.printerName,
          getPrintersAsync: () => webContents.getPrintersAsync(),
        });
        const buffer = buildBuffer({ kind, data: (data || {}) as PrintData, config });
        await sendRawImpl(printer.name, buffer);
        return { success: true, message: SUCCESS[kind] };
      });
    } catch (error) {
      return { success: false, message: (error && error.message) || 'No se pudo imprimir.' };
    }
  }

  /**
   * Commissioning slip for the 58 mm unit. Runs only when LOTTO_PRINT_TEST=1.
   * Never throws: every failure becomes a PrintResult.
   */
  async function maybeRunStartupTest(): Promise<PrintResult | null> {
    try {
      const config = resolveEffectiveConfig();
      if (config.printTest !== true) return null;
      const win = typeof getWindow === 'function' ? getWindow() : null;
      const webContents = win && win.webContents;
      if (!webContents || typeof webContents.getPrintersAsync !== 'function') {
        return { success: false, message: 'No hay ventana activa para la prueba de impresion.' };
      }
      return await lock.run(async () => {
        const printer = await resolvePrinter({
          printerName: config.printerName,
          getPrintersAsync: () => webContents.getPrintersAsync(),
        });
        await sendRawImpl(printer.name, printTest.render(config));
        return { success: true, message: 'Prueba de impresion enviada' };
      });
    } catch (error) {
      return { success: false, message: (error && error.message) || 'No se pudo enviar la prueba de impresion.' };
    }
  }

  return {
    printDocument,
    maybeRunStartupTest,
    resolveEffectiveConfig,
    buildBuffer,
  };
}

export { createPrinting, buildBuffer, SUCCESS };
