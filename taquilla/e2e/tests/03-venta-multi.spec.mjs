// Venta multi-selección (REQ-3 multi-selection): dos jugadas (dos animales)
// en el MISMO ticket → POST /tickets con 2 líneas → el payload del stub y el
// snapshot ultimoTicket llevan ambas selecciones.
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, loginToDashboard, sellTicketLine, printsHandle, seleccionarTipoPago } from '../helpers/app.mjs';
import { SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('venta multi-selección', () => {
  test('dos jugadas en un ticket → ambas selecciones en payload y snapshot', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);

    // Línea 1: Perro #27. El add resetea la selección (resetSeleccionJuegos),
    // así que la línea 2 se arma desde cero.
    await sellTicketLine(page, { animal: 'Perro', monto: '5000' });
    await sellTicketLine(page, { animal: 'Gato', monto: '5000' });
    await stepShot(page, '03-venta-multi-01-lineas');

    // tipo-pago-taquilla (R6): el tipo de pago es obligatorio antes de imprimir.
    await seleccionarTipoPago(page);

    await page.click(SELECTORS.dashboard.print);
    await page.waitForFunction(() => Boolean(localStorage.getItem('ultimoTicket')), null, {
      timeout: 15_000,
    });

    const snapshot = await page.evaluate(() =>
      JSON.parse(localStorage.getItem('ultimoTicket') || 'null')
    );
    expect(snapshot).not.toBeNull();
    expect(snapshot.ticketCode).toMatch(/^[A-Z0-9]{8}$/);
    expect(snapshot.lines).toHaveLength(2);

    const prints = await printsHandle(app);
    const print = prints.find((p) => p.channel === 'print-ticket');
    expect(print).toBeTruthy();
    expect(print.payload.ticketData.ticketCode).toBe(snapshot.ticketCode);
    const jugadas = print.payload.ticketData.lines.map((l) => l.jugada);
    expect(jugadas).toContain('Perro #27');
    expect(jugadas).toContain('Gato #11');
    await stepShot(page, '03-venta-multi-02-ticket');

    await closeApp(app);
  });
});