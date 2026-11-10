// Single-flight print lock. The caller decides retries; there is no queue.
import type { PrintLock, PrintError, PrintErrorCode } from '../types';

const fail = (code: PrintErrorCode, message: string): PrintError => Object.assign(new Error(message), { code });

function createLock(): PrintLock {
  let busy = false;

  async function run<T>(task: () => Promise<T>): Promise<T> {
    if (typeof task !== 'function') {
      throw fail('E_PRINT_LOCK_INVALID', 'La tarea de impresion debe ser una funcion.');
    }
    if (busy) {
      throw fail('E_PRINT_BUSY', 'Ya hay una impresion en curso. Espere a que termine antes de reintentar.');
    }
    busy = true;
    try {
      return await task();
    } finally {
      busy = false;
    }
  }

  return { run, isBusy: () => busy };
}

export { createLock };
