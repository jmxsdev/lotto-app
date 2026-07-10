import { defineConfig } from 'astro/config';

export default defineConfig({
  // El build estático es perfecto para Electron
  output: 'static',
  // Base URL para archivos locales
  base: './',
  // Directorio de salida
  outDir: './dist',
  // Servidor de desarrollo para pruebas
  server: {
    port: 3000,
  },
});
