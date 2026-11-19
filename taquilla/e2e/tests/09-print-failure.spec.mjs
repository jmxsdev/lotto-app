// Fase 5 — caminos de fallo de impresión (REQ: sin fallos silenciosos).
//
// 1. Arranque sin impresora configurada: el dashboard abre el modal de
//    configuración en vez de vender a ciegas (maybePromptPrinterSetup).
// 2. Venta con E_PRINTER_UNCONFIGURED: el ticket se crea, el snapshot
//    ultimoTicket se conserva y el error visible ofrece configurar la
//    impresora (`showPrintFailure` → confirm → `#printer-modal`).
//
// El stub de impresión se parametriza por spec (Fase 5); los specs 01–08
// siguen usando el default POS-58 configurada.
//
// Login: el endpoint tiene `throttle:10,2` y la suite completa ya consume el
// cupo (9 logins UI + el login API del global setup). Estos dos specs
// reutilizan el token de e2e/artifacts/session.json y navegan directo a
// `app://dashboard/`, sin un POST /login extra.
import { test, expect } from '@playwright/test';
import {
  launchApp,
  closeApp,
  sellTicketLine,
  seleccionarTipoPago,
  printsHandle,
} from '../helpers/app.mjs';
import { loadSession } from '../helpers/api.mjs';
import { SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

const TWO_PRINTERS = [
  { name: 'POS-58', displayName: 'POS-58', description: '', options: {} },
  { name: 'EPSON TM-T20', displayName: 'EPSON TM-T20', description: '', options: {} },
];

/** Token creado por el global setup; evita un login UI extra (throttle 10/2). */
function sessionToken() {
  const session = loadSession();
  if (!session?.token) throw new Error('Falta e2e/artifacts/session.json (global setup).');
  return session.token;
}

/** Abre el dashboard con el token inyectado, sin pasar por /login. */
async function openDashboard(page) {
  // Mismo origen (host index.html) para conservar localStorage.auth_token:
  // app://dashboard/ sería otro origen y el token no viajaría.
  await page.goto(new URL('/dashboard', page.url()).href);
  await expect(page.locator(SELECTORS.dashboard.numero)).toBeVisible({ timeout: 20_000 });
}

test.describe('fallos de impresion', () => {
  test('sin impresora configurada, el dashboard abre el modal de configuracion', async () => {
    const { app, page } = await launchApp({
      token: sessionToken(),
      stubOptions: {
        config: { printerName: null },
        printers: TWO_PRINTERS,
      },
    });
    await openDashboard(page);

    // Cero o varias impresoras → no se autoguarda nada y se pide elegir.
    await expect(page.locator('#printer-modal')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#printer-select option')).toHaveCount(2);
    await expect(page.locator('#printer-status')).not.toHaveText('');
    await stepShot(page, '09-print-failure-01-setup-sin-impresora');

    // El modal no debe guardar por su cuenta: no hubo print:set-config.
    const prints = await printsHandle(app);
    expect(prints.some((p) => p.channel === 'print:set-config')).toBe(false);

    await closeApp(app);
  });

  test('venta con E_PRINTER_UNCONFIGURED: error visible, setup y snapshot conservado', async () => {
    const { app, page } = await launchApp({
      token: sessionToken(),
      stubOptions: {
        printResults: {
          'print-ticket': {
            success: false,
            code: 'E_PRINTER_UNCONFIGURED',
            message: 'No hay una impresora configurada.',
          },
        },
      },
    });
    await openDashboard(page);

    await sellTicketLine(page, { animal: 'Perro', monto: '5000' });
    await seleccionarTipoPago(page);
    await page.click(SELECTORS.dashboard.print);

    // La venta NO se pierde: el ticket se creó y el snapshot de «+» quedó.
    await page.waitForFunction(() => Boolean(localStorage.getItem('ultimoTicket')), null, {
      timeout: 15_000,
    });
    const snapshot = await page.evaluate(() =>
      JSON.parse(localStorage.getItem('ultimoTicket') || 'null')
    );
    expect(snapshot).not.toBeNull();
    expect(snapshot.ticketCode).toMatch(/^[A-Z0-9]{8}$/);
    expect(snapshot.lines).toHaveLength(1);

    // El intento de impresión llegó al proceso main con el mismo ticket.
    const prints = await printsHandle(app);
    const print = prints.find((p) => p.channel === 'print-ticket');
    expect(print).toBeTruthy();
    expect(print.payload.ticketData.ticketCode).toBe(snapshot.ticketCode);

    // Fallo visible + oferta de configurar (no un modal de éxito).
    const confirm = page.locator('.modal-overlay', { hasText: '¿Configurar impresora?' });
    await expect(confirm.locator('.modal-message')).toHaveText('¿Configurar impresora?');
    await stepShot(page, '09-print-failure-02-error-visible');

    await confirm.locator('.ok-btn').click();
    await expect(page.locator('#printer-modal')).toBeVisible({ timeout: 15_000 });
    await stepShot(page, '09-print-failure-03-setup-abierto');

    await closeApp(app);
  });
});
