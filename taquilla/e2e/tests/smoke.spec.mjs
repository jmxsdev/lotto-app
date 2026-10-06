// Smoke (REQ-4 clean-state + REQ-7 build mode): la app carga desde app:// con
// el bridge, los stubs IPC responden (get-mac/print) y NO abre diálogos de
// sistema (el stub captura en vez de imprimir).
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, printsHandle } from '../helpers/app.mjs';
import { E2E_MAC } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('smoke', () => {
  test('app:// carga, bridge presente, get-mac/print stubeados, sin diálogo', async () => {
    let dialogFired = false;
    const { app, page } = await launchApp();
    page.on('dialog', (dialog) => {
      dialogFired = true;
      dialog.dismiss().catch(() => {});
    });

    // Build mode: URL app:// (nunca localhost:3000 de dev).
    await expect(page).toHaveURL(/^app:\/\//, { timeout: 20_000 });

    // Bridge expuesto por preload (contextBridge).
    const bridge = await page.evaluate(() => ({
      hasElectron: typeof window.electron === 'object',
      hasGetMac: typeof window.electron?.getMac === 'function',
      hasPrintTicket: typeof window.electron?.printTicket === 'function',
      hasPrintCierre: typeof window.electron?.printCierre === 'function',
      hasPrintReporte: typeof window.electron?.printReporte === 'function',
    }));
    expect(bridge.hasElectron).toBe(true);
    expect(bridge.hasGetMac).toBe(true);
    expect(bridge.hasPrintTicket).toBe(true);
    expect(bridge.hasPrintCierre).toBe(true);
    expect(bridge.hasPrintReporte).toBe(true);

    // get-mac stubeado → MAC E2E (el renderer lo manda como X-Device-MAC).
    const mac = await page.evaluate(() => window.electron.getMac());
    expect(mac).toBe(E2E_MAC);

    // print-ticket stubeado → { success: true, e2e: true } sin diálogo.
    const printRes = await page.evaluate(() =>
      window.electron.printTicket({
        ticketData: {
          ticketCode: 'E2E-SMOKE-0001',
          date: '2026-10-02',
          time: '06:00',
          game: 'Smoke',
          lines: [{ jugada: '01', amountBs: 1, amountUsd: 0 }],
        },
      })
    );
    expect(printRes).toMatchObject({ success: true, e2e: true });

    // Capturas registradas en el proceso main (globalThis.__e2e_prints).
    const prints = await printsHandle(app);
    expect(prints.some((p) => p.channel === 'get-mac')).toBe(true);
    expect(prints.some((p) => p.channel === 'print-ticket')).toBe(true);

    // El estado fresco + stubs evitan cualquier diálogo de impresión.
    expect(dialogFired).toBe(false);

    // La app sigue viva en app:// (splash o login tras la verificación).
    await expect(page.locator('body')).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/^app:\/\//);
    await stepShot(page, 'smoke-01-app');

    await closeApp(app);
  });
});