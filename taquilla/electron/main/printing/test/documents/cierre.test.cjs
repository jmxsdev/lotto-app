'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { buildLines } = require('../../dist/documents/cierre.js');

const CFG_32 = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3 };
const CFG_48 = { ...CFG_32, columns: 48 };
const sp = (count) => ' '.repeat(count);
const RULE32 = '-'.repeat(32);
const cierre = {
  id: 42, taquillaNombre: 'Taquilla 1', creadorNombre: 'Maria Perez',
  fecha_inicio: '2026-11-09T12:00:00Z', fecha_fin: '2026-11-09T22:00:00Z',
  exchange_rate_cierre: 40, referencia: 'C-1042', concepto: 'Cierre turno tarde',
  total_ventas_bs: 12500, total_ventas_usd: 312.5,
  total_egresos_bs: 1200, total_egresos_usd: 30,
  total_efectivo_bs: 11300, total_efectivo_usd: 282.5,
  arqueo_efectivo_bs: 11250, arqueo_efectivo_usd: 281.25,
  faltante_sobrante_bs: -50, faltante_sobrante_usd: -1.25,
  desglose_metodos: {
    bs: {
      efectivo: { ventas: 10000, egresos: 500, efectivo: 1234567.89 },
      transferencia: { ventas: 2000, egresos: 400, efectivo: 1600 },
      pago_movil: { ventas: 500, egresos: 300, efectivo: 200 },
      punto_venta: { ventas: 0, egresos: 0, efectivo: 0 },
    },
    usd: {
      efectivo: { ventas: 50, egresos: 10, efectivo: 40 },
      transferencia: { ventas: 20, egresos: 0, efectivo: 20 },
      pago_movil: { ventas: 10, egresos: 5, efectivo: 5 },
      punto_venta: { ventas: 0, egresos: 0, efectivo: 0 },
    },
  },
};

test('32 columnas: cabecera, totales y arqueo apilados por moneda', () => {
  const lines = buildLines(cierre, CFG_32);
  assert.equal(lines[0], sp(9) + 'CIERRE DE CAJA' + sp(9));
  assert.equal(lines[1], sp(11) + 'Taquilla 1' + sp(11));
  assert.deepEqual(lines.slice(3, 8), [
    'Desde: 09/11/2026 08:00', 'Hasta: 09/11/2026 18:00', 'Tasa: 40,00',
    'Referencia: C-1042', 'Concepto: Cierre turno tarde',
  ]);
  assert.deepEqual(lines.slice(9, 27), [
    'TOTALES', 'Ventas',
    '  BS ' + sp(18) + '12.500,00', '  USD' + sp(21) + '312,50',
    'Egresos',
    '  BS ' + sp(19) + '1.200,00', '  USD' + sp(22) + '30,00',
    'Efectivo',
    '  BS ' + sp(18) + '11.300,00', '  USD' + sp(21) + '282,50',
    RULE32, 'ARQUEO / DIFERENCIA', 'Arqueo',
    '  BS ' + sp(18) + '11.250,00', '  USD' + sp(21) + '281,25',
    'Falt./Sobr.',
    '  BS ' + sp(21) + '-50,00', '  USD' + sp(22) + '-1,25',
  ]);
});

test('32 columnas: desglose apilado de los cuatro metodos en BS y USD, sin truncar', () => {
  const lines = buildLines(cierre, CFG_32);
  assert.deepEqual(lines.slice(27, 34), [
    RULE32, 'DESGLOSE POR METODO', 'BS', ' Efectivo',
    '   Ventas  ' + sp(12) + '10.000,00',
    '   Egresos ' + sp(15) + '500,00',
    '   Efectivo' + sp(9) + '1.234.567,89',
  ]);
  const methods = lines.filter((line) => /^ (Efectivo|Transferencia|Pago Movil|Punto Venta)$/.test(line));
  assert.deepEqual(methods, [' Efectivo', ' Transferencia', ' Pago Movil', ' Punto Venta', ' Efectivo', ' Transferencia', ' Pago Movil', ' Punto Venta']);
  assert.ok(lines.every((line) => line.length <= 32));
});

test('sin arqueo la seccion desaparece; referencia y concepto quedan en una linea', () => {
  const lines = buildLines({
    ...cierre, arqueo_efectivo_bs: null, arqueo_efectivo_usd: null,
    faltante_sobrante_bs: null, faltante_sobrante_usd: null,
    referencia: 'A\nB', concepto: 'Cierre\n turno   tarde',
  }, CFG_32);
  assert.equal(lines.includes('ARQUEO / DIFERENCIA'), false);
  assert.equal(lines.includes('Falt./Sobr.'), false);
  assert.equal(lines.find((line) => line.startsWith('Referencia:')), 'Referencia: A B');
  assert.equal(lines.find((line) => line.startsWith('Concepto:')), 'Concepto: Cierre turno tarde');
});

test('48 columnas (opt-in): totales en tabla y desglose con 7 digitos apilado', () => {
  const lines = buildLines(cierre, CFG_48);
  assert.equal(lines[10], 'Concepto' + sp(23) + 'BS' + sp(12) + 'USD');
  assert.equal(lines[11], 'Ventas' + sp(18) + '12.500,00' + sp(9) + '312,50');
  assert.equal(lines.filter((line) => line.startsWith('Metodo')).length, 1);
  assert.ok(lines.includes('   Efectivo' + sp(25) + '1.234.567,89'));
  assert.ok(lines.every((line) => line.length <= 48));
});
