// Cierre de caja (REQ-3 cashier close): arqueo = efectivo esperado del
// preview (lectura del DOM, nunca hardcodeado) → ejecutar cierre (crear, o
// re-cierre si el día ya tiene cierre de una corrida previa) → 2ª ejecución
// SIEMPRE re-cierre con clave_cierre (E2E_CIERRE_CLAVE, E2eSeeder) → badge
// CONCILIADO en el resultado.
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, loginToDashboard, navigateGlobalKey } from '../helpers/app.mjs';
import { E2E_CIERRE_CLAVE } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

/** Convierte "Bs. 1.234,56" / "Bs. -130.000,00" → número (es-VE). */
function parseEsVeBs(text) {
  // Exige al menos un dígito: "Bs." no debe matchear (el "." solo → NaN).
  const m = String(text || '').match(/-?\d[\d.,]*/);
  if (!m) return null;
  return parseFloat(m[0].replace(/\./g, '').replace(',', '.'));
}

/**
 * Ejecuta el cierre: click #btn-ejecutar → confirm (.ok-btn) → si aparece el
 * prompt de clave (re-cierre) la completa → modal de éxito → Aceptar.
 */
async function ejecutarCierre(page) {
  await page.click('#btn-ejecutar');
  const confirm = page.locator('.modal-overlay:visible');
  await expect(confirm.locator('.ok-btn')).toBeVisible({ timeout: 10_000 });
  await confirm.locator('.ok-btn').click();

  // ¿Prompt de clave? Solo en re-cierre (cierre_hoy ya existe: corrida previa
  // o la 2ª ejecución de este spec).
  const claveInput = page.locator('.modal-overlay:visible .modal-input');
  const pideClave = await claveInput
    .waitFor({ state: 'visible', timeout: 1_500 })
    .then(() => true)
    .catch(() => false);
  if (pideClave) {
    await claveInput.fill(E2E_CIERRE_CLAVE);
    await page.locator('.modal-overlay:visible .ok-btn').click();
  }

  const success = page.locator('.modal-overlay:visible');
  await expect(success.locator('.ok-btn')).toBeVisible({ timeout: 15_000 });
  await expect(success).toContainText(/Cierre|actualizad/, { timeout: 15_000 });
  await success.locator('.ok-btn').click();
}

test.describe('cierre de caja', () => {
  test('cierre → re-cierre con clave → badge CONCILIADO', async () => {
    const { app, page } = await launchApp();
    await loginToDashboard(page);

    // Navegación global: F8 → /cierre. El listener GLOBAL de MainLayout puede
    // registrarse después del dashboard (#qt-numero no lo garantiza); el
    // helper reintenta hasta que la URL matchea (F8 es idempotente).
    await navigateGlobalKey(page, 'F8', /\/cierre/);

    // Efectivo esperado BS desde el preview (período real del backend:
    // fixture + ventas/egresos de la corrida; leerlo es determinista).
    // El arqueo tiene `min:0` (CierreController): con esperado >= 0 se cierra
    // CONCILIADO (arqueo = esperado); con esperado negativo (egreso de premios
    // del spec 04 > ventas, p. ej. corridas aisladas) el arqueo mínimo 0 deja
    // un sobrante — el badge se afirma según el diff real.
    const filaEsperado = page.locator('#preview-content tr.row-total');
    await expect(filaEsperado).toBeVisible({ timeout: 15_000 });
    const texto = await filaEsperado.locator('td:nth-child(2)').textContent();
    const esperado = parseEsVeBs(texto);
    expect(esperado).not.toBeNull();
    const arqueo = Math.max(esperado, 0);
    const badgeEsperado = esperado >= 0 ? 'CONCILIADO' : 'SOBRANTE';
    await page.fill('#arqueo-bs', String(arqueo));
    await stepShot(page, '07-cierre-01-preview');

    // 1er cierre (crear, o re-cierre si una corrida previa dejó cierre hoy).
    await ejecutarCierre(page);
    await stepShot(page, '07-cierre-02-primer');

    // 2ª ejecución: SIEMPRE re-cierre (cierre_hoy ya existe) → clave.
    await ejecutarCierre(page);
    await stepShot(page, '07-cierre-03-recierre');

    // Badge del resultado del cierre (diff = arqueo - esperado ≈ 0 en el
    // camino CONCILIADO; sobrante cuando el esperado es negativo).
    await expect(page.locator('#resultado-content')).toContainText(badgeEsperado, {
      timeout: 15_000,
    });

    await closeApp(app);
  });
});