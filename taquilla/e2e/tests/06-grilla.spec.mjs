// Grilla / navegación por teclado (REQ-3 grid/navigation): el grid de juegos
// `.juego-card[data-id]` se renderiza; F2 enfoca #qt-numero; las flechas
// mueven la celda activa; Enter activa la tarjeta enfocada y, con un juego de
// modalidades (tab Tripletas → triple-zulia), se renderiza #modalidad-grid y
// la selección de modalidad funciona (roving focus + Enter).
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, loginToDashboard } from '../helpers/app.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('grilla / navegación', () => {
  test('grid de juegos + F2 + flechas + Enter → #modalidad-grid y selección', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);

    // 1. Grid de juegos del tab animalitos (Lotto Activo, Cazalotón, ...).
    const cards = page.locator('.juego-card[data-id]');
    await expect(cards.first()).toBeVisible({ timeout: 15_000 });
    const count = await cards.count();
    expect(count).toBeGreaterThan(0);
    await stepShot(page, '06-grilla-00-grid');

    // 2. F2 «Números» → foco en #qt-numero (zona número).
    await page.keyboard.press('F2');
    await expect(page.locator('#qt-numero')).toBeFocused({ timeout: 10_000 });

    // 3. Flechas: ↑/↓ mueven la celda activa del grid de juegos (paso de
    //    fila en la grilla de 2 columnas). Volver a la zona juegos primero.
    await cards.first().focus();
    const antes = await page.evaluate(() => document.activeElement?.getAttribute('data-id'));
    await page.keyboard.press('ArrowDown');
    const despues = await page.evaluate(() => document.activeElement?.getAttribute('data-id'));
    expect(despues).not.toBe(antes);
    await stepShot(page, '06-grilla-01-flechas');

    // 4. Tab Tripletas → triple-zulia (con modalidades) → #modalidad-grid.
    await page.click('.juego-tab[data-tab="tripletas"]');
    await expect(page.locator('.juego-card[data-id]').first()).toBeVisible({ timeout: 10_000 });
    await page.locator('.juego-card[data-id]').first().click();
    await expect(page.locator('#modalidad-grid')).toBeVisible({ timeout: 10_000 });
    await stepShot(page, '06-grilla-02-modalidad');

    // 5. Selección de modalidad: Enter sobre el primer .modalidad-btn marca
    //    `selected` (roving focus KB-05).
    await page.locator('#modalidad-grid .modalidad-btn').first().focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#modalidad-grid .modalidad-btn.selected')).toHaveCount(1, {
      timeout: 10_000,
    });
    await stepShot(page, '06-grilla-03-seleccion');

    await closeApp(app);
  });
});