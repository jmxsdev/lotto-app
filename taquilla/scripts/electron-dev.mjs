import { spawn } from 'child_process';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

console.log('🚀 Iniciando Electron + Astro en modo desarrollo...');

// Iniciar servidor de Astro
const astro = spawn('npx', ['astro', 'dev'], {
  stdio: 'inherit',
  shell: true
});

let electron;

// Esperar a que Astro esté listo (~5 segundos)
setTimeout(() => {
  console.log('⚡ Iniciando Electron...');
  
  try {
    const electronPath = require.resolve('electron');
    electron = spawn(electronPath, [], {
      env: {
        ...process.env,
        NODE_ENV: 'development',
        ELECTRON_DEV_URL: 'http://localhost:3000'
      },
      stdio: 'inherit'
    });

    electron.on('error', (err) => {
      console.error('❌ Error al iniciar Electron:', err);
    });

  } catch (err) {
    console.error('❌ No se pudo encontrar Electron. Instálalo con: npm install --save-dev electron');
  }
}, 5000);

// Manejar cierre limpio
process.on('SIGINT', () => {
  console.log('\n🛑 Cerrando procesos...');
  astro.kill('SIGINT');
  if (electron) electron.kill('SIGINT');
  process.exit(0);
});
