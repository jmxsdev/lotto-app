/**
 * Navegación del grid de Juegos (feat/taquilla-logos-juegos) — módulo PURO,
 * sin DOM ni I/O. Lin-testable vía scripts/check-pure.mjs (Node 24
 * type-stripping: solo sintaxis TS borrable — sin enums, sin namespaces,
 * sin parameter properties).
 *
 * El grid de juegos del dashboard es de 2 columnas (CSS .juegos-list); estos
 * helpers traducen ↑/↓ (fila) y ←/→ (columna) a índices destino sobre la
 * lista DOM en orden de lectura.
 */

/**
 * Índice destino de un movimiento VERTICAL en filas. `paso` es 1 en las listas
 * simples y 2 en el grid de 2 columnas de la zona Juegos: se salta de fila
 * conservando la columna. Clamp en los extremos (sin wrap): si la última fila
 * es impar y no hay celda en esa columna, devuelve null (no-op) en vez de
 * cambiar de columna. idx === -1 (foco en el contenedor) entra por el extremo
 * según la dirección, como en la lista clásica.
 */
export function indiceDestinoFila(idx: number, total: number, delta: number, paso: number): number | null {
  if (total === 0) return null;
  if (idx === -1) return delta > 0 ? 0 : total - 1;
  const destino = idx + delta * paso;
  if (destino < 0 || destino >= total) return null;
  return destino;
}

/**
 * Índice destino de un movimiento LATERAL (←/→) dentro del grid: la celda
 * adyacente de la MISMA fila (columna ±1, `columnas` = 2). Sin wrap: si no hay
 * celda en esa dirección (borde de fila, fila impar, total 1) devuelve null
 * para que el glue caiga al cambio de pestaña. idx === -1 (foco en el
 * contenedor) o fuera de rango → null (mantiene el cambio de pestaña directo).
 */
export function indiceDestinoColumna(idx: number, total: number, delta: number, columnas = 2): number | null {
  if (total === 0) return null;
  if (idx < 0 || idx >= total) return null;
  const destino = idx + delta;
  if (destino < 0 || destino >= total) return null;
  // Sin wrap entre filas: la celda destino debe pertenecer a la misma fila.
  if (Math.floor(destino / columnas) !== Math.floor(idx / columnas)) return null;
  return destino;
}
