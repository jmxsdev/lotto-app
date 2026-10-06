// Anulación (REQ-3 cancellation): vender → F10 (modal de anulación con el
// último ticket pendiente) → teclear el serial (anti-tecleo) → Confirmar →
// DELETE /tickets/{id} → badge ANULADA en /historial (F5).
//
// La ventana de eliminación efectiva de E2E01 es 1440 min (E2eSeeder) y el
// sorteo del ticket es mañana (reloj del harness) → el backend autoriza.
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, loginToDashboard, sellTicketLine, navigateGlobalKey, seleccionarTipoPago } from '../helpers/app.mjs';
import { SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('anulación', () => {
  test('F10 + serial → ticket anulado → badge ANULADA en historial', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);

    // Vender un ticket propio (el último pendiente que F10 va a apuntar).
    await sellTicketLine(page, { animal: 'Caballo', monto: '5000' });
    // tipo-pago-taquilla (R6): el tipo de pago es obligatorio antes de imprimir.
    await seleccionarTipoPago(page);
    await page.click(SELECTORS.dashboard.print);
    await page.waitForFunction(() => Boolean(localStorage.getItem('ultimoTicket')), null, {
      timeout: 15_000,
    });
    const ticketCode = await page.evaluate(() =>
      JSON.parse(localStorage.getItem('ultimoTicket') || 'null').ticketCode
    );
    expect(ticketCode).toMatch(/^[A-Z0-9]{8}$/);

    // F10: modal de anulación con el último pendiente (GET /tickets).
    await page.keyboard.press('F10');
    const overlay = page.locator('#anular-overlay');
    await expect(overlay).toHaveClass(/abierto/, { timeout: 10_000 });
    await stepShot(page, '05-anulacion-01-modal');

    // Anti-tecleo: el serial debe coincidir con el ticket_code.
    await page.fill('#anular-serial', ticketCode);
    await page.click('#anular-confirmar');

    // Modal de éxito del DELETE.
    const success = page.locator('.modal-overlay:visible');
    await expect(success).toContainText('Ticket anulado correctamente', { timeout: 15_000 });
    await success.locator('.ok-btn').click();
    await stepShot(page, '05-anulacion-02-anulado');

    // Historial (F5): el ticket anulado queda visible con badge ANULADA.
    // F5 es tecla GLOBAL (MainLayout): helper con reintentos (idempotente).
    await navigateGlobalKey(page, 'F5', /\/historial/);
    const card = page.locator('.ticket-card', { hasText: ticketCode });
    await expect(card.locator('.badge-anulada')).toBeVisible({ timeout: 15_000 });
    await stepShot(page, '05-anulacion-03-historial');

    await closeApp(app);
  });
});