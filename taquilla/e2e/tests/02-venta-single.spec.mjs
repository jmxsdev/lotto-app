// Venta single (REQ-3 single-draw): horario futuro + tasa → POST /tickets con
// una línea → el stub de impresión captura el payload (print-ticket) y
// localStorage.ultimoTicket guarda el snapshot.
//
// NOTA (desviación documentada): la task pedía "success modal muestra
// ticket_code", pero con el stub de impresión activo (design AD #1, evaluate-
// only) `window.electron.printTicket` existe y NO se muestra el modal de
// éxito (ese camino solo corre sin electron.printTicket). El contrato del
// design (§Contracts) manda: assert sobre `__e2e_prints[]` y
// `localStorage.ultimoTicket` — el ticket_code se verifica ahí.
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, loginToDashboard, sellTicketLine, printsHandle } from '../helpers/app.mjs';
import { SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('venta single', () => {
  test('una jugada de animalitos → ticket creado, print stubeado, snapshot ultimoTicket', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);
    await stepShot(page, '02-venta-single-01-dashboard');

    // Jugada: Lotto Activo (primer .juego-card), animal Perro, horario 08:00,
    // monto 5000 Bs (por encima del límite mínimo 3600 del catálogo).
    await sellTicketLine(page, { animal: 'Perro', monto: '5000' });
    await stepShot(page, '02-venta-single-02-linea');

    await page.click(SELECTORS.dashboard.print);
    // POST /tickets ok → snapshot en localStorage (sin modal: stub print).
    await page.waitForFunction(() => Boolean(localStorage.getItem('ultimoTicket')), null, {
      timeout: 15_000,
    });

    const snapshot = await page.evaluate(() =>
      JSON.parse(localStorage.getItem('ultimoTicket') || 'null')
    );
    expect(snapshot).not.toBeNull();
    expect(snapshot.ticketCode).toMatch(/^[A-Z0-9]{8}$/);
    expect(snapshot.lines).toHaveLength(1);

    // Payload capturado por el stub en el proceso main.
    const prints = await printsHandle(app);
    const print = prints.find((p) => p.channel === 'print-ticket');
    expect(print).toBeTruthy();
    expect(print.payload.ticketData.ticketCode).toBe(snapshot.ticketCode);
    expect(print.payload.ticketData.lines).toHaveLength(1);
    expect(print.payload.ticketData.lines[0].jugada).toBe('Perro #27');
    await stepShot(page, '02-venta-single-03-ticket');

    await closeApp(app);
  });
});