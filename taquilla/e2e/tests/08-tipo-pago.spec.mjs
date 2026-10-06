// Tipo de pago en la venta (change tipo-pago-taquilla): deriva las opciones de
// la moneda del ticket, exige una selección antes de imprimir, envía
// `metodo_pago` en el POST /tickets, lo persiste en el snapshot y lo imprime.
//
// Evidencia runtime de los escenarios de la spec tipo-pago-venta que el harness
// puro (check-pure.mjs) no puede cubrir porque viven en el DOM de
// `dashboard.astro` (zona navegable, congelado de moneda, bloqueo, snapshot e
// impresión). El POST se captura envolviendo `window.fetch` (API_BASE es el
// protocolo custom `api:///api/v1`, que page.route no intercepta).
//
// Un único test que recorre los escenarios en UNA sesión: el harness hace un
// login por test y el backend limita /login a 10 intentos/2 min, así que
// mantener la suite dentro de ese presupuesto es parte del contrato del harness.
import { test, expect } from '@playwright/test';
import {
  launchApp,
  closeApp,
  loginToDashboard,
  sellTicketLine,
  printsHandle,
} from '../helpers/app.mjs';
import { SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

const CODIGOS_BS = ['transferencia', 'efectivo', 'punto_venta', 'pago_movil'];
const PLACEHOLDER = /Agregue una jugada para elegir el tipo de pago/;

/** Captura el body del POST /tickets envolviendo el fetch global del renderer. */
async function capturarPostTickets(page) {
  await page.evaluate(() => {
    const orig = window.fetch.bind(window);
    globalThis.__e2e_postTickets = null;
    window.fetch = async (input, init) => {
      const url = typeof input === 'string' ? input : input?.url ?? '';
      if (url.endsWith('/tickets') && init?.method === 'POST' && init?.body) {
        try {
          globalThis.__e2e_postTickets = JSON.parse(init.body);
        } catch {
          /* body no JSON */
        }
      }
      return orig(input, init);
    };
  });
}

function leerPostTickets(page) {
  return page.evaluate(() => globalThis.__e2e_postTickets ?? null);
}

test.describe('tipo de pago', () => {
  test('Bs/USD, obligatoriedad, moneda única, snapshot, impresión y teclado', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);
    await capturarPostTickets(page);

    // ── R2 (Bs): cuatro opciones derivadas de la moneda, ninguna USD. ────
    await sellTicketLine(page, { animal: 'Perro', monto: '5000' });
    const items = page.locator('#pago-list .pago-item');
    await expect(items).toHaveCount(4);
    expect(await items.evaluateAll((els) => els.map((e) => e.dataset.metodo))).toEqual(CODIGOS_BS);
    await expect(page.locator('#pago-list .pago-item[data-metodo="efectivo"]')).toHaveText('Efectivo');

    // ── R4: la opción se elige y queda activa. ────────────────────────────
    await page.click('#pago-list .pago-item[data-metodo="transferencia"]');
    await expect(page.locator('#pago-list .pago-item[data-metodo="transferencia"]')).toHaveClass(/activo/);
    await stepShot(page, '08-pago-01-seleccion-bs');

    // ── R1 + R5: POST con metodo_pago + snapshot/impresión con el label. ──
    await page.click(SELECTORS.dashboard.print);
    await page.waitForFunction(() => Boolean(localStorage.getItem('ultimoTicket')), null, { timeout: 15_000 });
    const snapshot = await page.evaluate(() => JSON.parse(localStorage.getItem('ultimoTicket') || 'null'));
    expect(snapshot.tipoPago).toBe('Transferencia');
    const postBody = await leerPostTickets(page);
    expect(postBody?.metodo_pago).toBe('transferencia');
    expect(postBody?.lines).toHaveLength(1);
    const print = (await printsHandle(app)).find((p) => p.channel === 'print-ticket');
    expect(print.payload.ticketData.tipoPago).toBe('Transferencia');

    // R5: la reimpresión con «+» conserva el tipo de pago.
    const antes = (await printsHandle(app)).length;
    await page.keyboard.press('+');
    await expect.poll(async () => (await printsHandle(app)).length, { timeout: 10_000 }).toBeGreaterThan(antes);
    const ultimoPrint = (await printsHandle(app)).filter((p) => p.channel === 'print-ticket').at(-1);
    expect(ultimoPrint.payload.ticketData.tipoPago).toBe('Transferencia');
    await stepShot(page, '08-pago-02-impreso');

    // ── R6: F11 resetea el tipo y rehabilita el selector de moneda. ───────
    await page.keyboard.press('F11');
    await expect(page.locator('#qt-moneda')).toBeEnabled();
    await expect(page.locator('#pago-list .pago-placeholder')).toHaveText(PLACEHOLDER);

    // ── R2 (USD): única opción «USD efectivo». ────────────────────────────
    await page.selectOption('#qt-moneda', 'usd');
    await sellTicketLine(page, { animal: 'Perro', monto: '10' });
    const usdItems = page.locator('#pago-list .pago-item');
    await expect(usdItems).toHaveCount(1);
    await expect(usdItems.first()).toHaveAttribute('data-metodo', 'efectivo');
    await expect(usdItems.first()).toHaveText('USD efectivo');
    await stepShot(page, '08-pago-03-usd');
    await page.keyboard.press('F11');
    await page.selectOption('#qt-moneda', 'bs');

    // ── R3: moneda única (selector congelado + guarda de addLine). ────────
    await sellTicketLine(page, { animal: 'Perro', monto: '5000' });
    await expect(page.locator('#qt-moneda')).toBeDisabled();
    await page.evaluate(() => {
      const s = document.getElementById('qt-moneda');
      s.disabled = false;
      s.value = 'usd';
    });
    await page.click(SELECTORS.dashboard.add);
    await expect(page.locator('.modal-message')).toHaveText(/No se puede mezclar monedas/);
    await page.click('.modal-overlay .ok-btn');
    await expect(page.locator('.resumen-table tbody tr')).toHaveCount(1);

    // ── R6: sin tipo de pago, Imprimir se bloquea con aviso y no crea. ────
    await page.evaluate(() => localStorage.removeItem('ultimoTicket'));
    await page.click(SELECTORS.dashboard.print);
    await expect(page.locator('.modal-message')).toHaveText(/Elija el tipo de pago antes de imprimir/);
    await page.click('.modal-overlay .ok-btn');
    expect(await page.evaluate(() => localStorage.getItem('ultimoTicket'))).toBeNull();
    await stepShot(page, '08-pago-04-bloqueo');

    // ── R4: teclado — Tab hasta `pago`, ↑/↓ recorren, Enter elige, Tab envuelve. ──
    let alcanzada = false;
    for (let i = 0; i < 14 && !alcanzada; i += 1) {
      await page.keyboard.press('Tab');
      alcanzada = await page.evaluate(
        () => document.activeElement?.classList?.contains('pago-item') === true
      );
    }
    expect(alcanzada).toBe(true);
    const inicial = await page.evaluate(() => document.activeElement?.dataset?.metodo);
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#pago-list .pago-item:focus')).toHaveCount(1);
    expect(await page.evaluate(() => document.activeElement?.dataset?.metodo)).not.toBe(inicial);
    await page.keyboard.press('Enter');
    await expect(page.locator('#pago-list .pago-item.activo')).toHaveCount(1);
    await stepShot(page, '08-pago-05-teclado');
    await page.keyboard.press('Tab');
    expect(
      await page.evaluate(
        () => document.activeElement?.closest?.('[data-zona]')?.dataset?.zona ?? null
      )
    ).toBe('juegos');

    // ── R6: reset final con F11. ──────────────────────────────────────────
    await page.keyboard.press('F11');
    await expect(page.locator('#qt-moneda')).toBeEnabled();
    await expect(page.locator('#pago-list .pago-placeholder')).toBeVisible();

    await closeApp(app);
  });
});
