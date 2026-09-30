import { ICONOS } from '../data/iconos.js';

const MAPA: Record<string, string> = ICONOS;

/**
 * SVG inline de Lucide para templates con innerHTML (iconografia-front R1).
 * Devuelve '' si el nombre no existe en el mapa generado; el fallo de
 * inventario lo reporta taquilla/scripts/check-icons.mjs.
 */
export function icono(nombre: string): string {
  return MAPA[nombre] ?? '';
}