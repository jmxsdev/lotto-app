'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { render, buildLines, BLOCK_FEED } = require('../../dist/documents/reporte.js');

const CFG_32 = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3, cutPerBlock: true };
const CFG_48 = { ...CFG_32, columns: 48 };
const sp = (count) => ' '.repeat(count);
const RULE32 = '-'.repeat(32);
const metodos = (ventas, egresos, efectivo) => ({
  efectivo: { ventas, egresos, efectivo },
  transferencia: { ventas: 100, egresos: 10, efectivo: 90 },
  pago_movil: { ventas: 60, egresos: 5, efectivo: 55 },
  punto_venta: { ventas: 40, egresos: 5, efectivo: 35 },
});
const closeOf = (id, arqueo) => ({
  id, taquilla_id: 3,
  fecha_inicio: '2026-11-07T12:00:00Z', fecha_fin: '2026-11-07T22:00:00Z',
  exchange_rate_cierre: 40,
  total_ventas_bs: 1000, total_ventas_usd: 20, total_egresos_bs: 100, total_egresos_usd: 2,
  total_efectivo_bs: 900, total_efectivo_usd: 18,
  desglose_metodos: { bs: metodos(800, 80, 720), usd: metodos(10, 1, 9) },
  ...(arqueo ? { arqueo_efectivo_bs: 890, arqueo_efectivo_usd: 17.8, faltante_sobrante_bs: -10, faltante_sobrante_usd: -0.2 } : {}),
});
const reporte = {
  taquillaNombre: 'Taquilla 1', fecha_desde: '2026-11-01', fecha_hasta: '2026-11-07',
  ventana_cubierta: { desde: '2026-11-01T12:00:00Z', hasta: '2026-11-07T22:00:00Z', cierres_incluidos: 12 },
  total_ventas_bs: 12000, total_ventas_usd: 300, total_egresos_bs: 1200, total_egresos_usd: 30,
  total_efectivo_bs: 10800, total_efectivo_usd: 270,
  desglose_metodos: { bs: metodos(9000, 900, 1234567.89), usd: metodos(10, 1, 9) },
  cierres: Array.from({ length: 12 }, (_, index) => closeOf(42 + index, index % 2 === 0)),
};

test('32 columnas: cabecera con rango, cierres incluidos y ventana cubierta', () => {
  assert.deepEqual(buildLines(reporte, CFG_32).slice(0, 10), [
    sp(7) + 'REPORTE POR RANGO' + sp(8),
    sp(11) + 'Taquilla 1' + sp(11),
    RULE32, 'Desde: 01/11/2026', 'Hasta: 07/11/2026', 'Cierres incluidos: 12',
    'Cubierto: 01/11/2026 08:00', sp(10) + '07/11/2026 18:00',
    RULE32, 'TOTALES',
  ]);
});

test('32 columnas: totales y desglose fusionado con monto de 7 digitos sin truncar', () => {
  const lines = buildLines(reporte, CFG_32);
  assert.equal(lines[19], 'DESGLOSE FUSIONADO');
  assert.ok(lines.includes('   Efectivo' + sp(9) + '1.234.567,89'));
  assert.ok(lines.every((line) => typeof line !== 'string' || line.length <= 32));
});

test('32 columnas: un bloque por cierre, arqueo opcional, feed 2 + corte y feed final 3', () => {
  const lines = buildLines(reporte, CFG_32);
  assert.equal(lines.filter((line) => typeof line === 'string' && line.startsWith('=== Cierre ')).length, 12);
  assert.equal(lines.filter((line) => line === 'Taquilla #3').length, 12);
  assert.equal(lines.filter((line) => line === 'Arqueo').length, 6);
  assert.equal(lines.filter((line) => line === 'BS').length, 13);
  assert.equal(lines.filter((line) => typeof line === 'object' && line.feed === BLOCK_FEED && line.cut === true).length, 11);
  const hex = render(reporte, CFG_32).toString('hex');
  assert.equal(hex.split('1b64021d564200').length - 1, 11);
  assert.ok(hex.endsWith('1b64031d564200'));
});

test('sin cortador: sin GS V, feed final 5 y sin corte por bloque', () => {
  const config = { ...CFG_32, cut: 'none' };
  const lines = buildLines(reporte, config);
  assert.equal(lines.filter((line) => typeof line === 'object').length, 0);
  const hex = render(reporte, config).toString('hex');
  assert.equal(hex.includes('1d56'), false);
  assert.ok(hex.endsWith('1b6405'));
});

test('48 columnas (opt-in): encabezado en una linea y tablas fusionadas', () => {
  const lines = buildLines(reporte, CFG_48);
  const strings = lines.filter((line) => typeof line === 'string');
  const headings = strings.filter((line) => line.startsWith('=== Cierre '));
  assert.equal(headings.length, 12);
  assert.equal(headings[0], '=== Cierre #42 - Taquilla #3 ===');
  assert.equal(strings.includes('Taquilla #3'), false);
  assert.equal(lines[10], 'Concepto' + sp(23) + 'BS' + sp(12) + 'USD');
  assert.equal(strings.filter((line) => line.startsWith('Metodo')).length, 25);
  assert.ok(strings.every((line) => line.length <= 48));
});
