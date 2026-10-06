// Premio (REQ-3 prize payment): fixture E2eSeeder → ticket ganador
// E2E-WIN-0001 (resultado animalitos de ayer 19:00, perro #27). Flujo:
// /ganadores con la fecha del fixture → card del ganador → Pagar Premio →
// confirm → POST /pagos (egreso) → comprobante recibo vía stub
// (print-ticket con kind 'recibo') → modal de premio pagado.
//
// El seeder resetea el fixture (borra pagos, restaura pendiente) entre
// corridas: re-seed antes de re-ejecutar el suite (runbook).
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, loginToDashboard, printsHandle, navigateGlobalKey } from '../helpers/app.mjs';
import { caracasDateOffset } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('premio', () => {
  test('pagar premio E2E-WIN-0001 → recibo impreso por stub + premio liquidado', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);

    // Navegación global: F7 → /ganadores (helper con reintentos: el listener
    // GLOBAL de MainLayout puede registrarse tras el dashboard; idempotente).
    await navigateGlobalKey(page, 'F7', /\/ganadores/);

    // Fecha del fixture = ayer en America/Caracas (reloj real, no mockeado).
    const fecha = caracasDateOffset(-1);
    await page.fill('#fecha', fecha);
    await page.click('#btn-buscar');

    const card = page.locator('.ganador-card', { hasText: 'E2E-WIN-0001' });
    await expect(card).toBeVisible({ timeout: 15_000 });
    await stepShot(page, '04-premio-01-ganadores');

    // Expandir el detalle y pagar el premio.
    await card.locator('.ganador-header').click();
    await card.locator('.btn-pagar-ticket').click();

    // Modal de confirmación → Sí.
    const confirm = page.locator('.modal-overlay:visible');
    await expect(confirm.locator('.ok-btn')).toBeVisible({ timeout: 10_000 });
    await confirm.locator('.ok-btn').click();

    // Modal de éxito con el premio liquidado → Aceptar.
    const success = page.locator('.modal-overlay:visible');
    await expect(success).toContainText('Premio pagado', { timeout: 15_000 });
    await success.locator('.ok-btn').click();
    await stepShot(page, '04-premio-02-pagado');

    // Comprobante recibo capturado por el stub (kind 'recibo', S1).
    const prints = await printsHandle(app);
    const recibo = prints.find(
      (p) => p.channel === 'print-ticket' && p.payload.ticketData?.kind === 'recibo'
    );
    expect(recibo).toBeTruthy();
    expect(recibo.payload.ticketData.ticketCode).toBe('E2E-WIN-0001');
    expect(Number(recibo.payload.ticketData.premioTotalBs)).toBeGreaterThan(0);
    await stepShot(page, '04-premio-03-recibo');

    await closeApp(app);
  });
});