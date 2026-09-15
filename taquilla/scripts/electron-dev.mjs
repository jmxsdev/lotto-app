import { spawn } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import { readFileSync } from 'fs';
import { parseEnvFile } from '../electron/main/upstream.cjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const projectRoot = join(__dirname, '..');

console.log('🚀 Iniciando Lotto Taquilla en modo desarrollo...\n');

// Cargar .env.development (parser puro de upstream.cjs) e inyectarlo en el
// env del proceso Electron. Precedencia en main: override IPC > env > prod;
// aqui el env del hijo combina shell (gana) con archivo (relleno).
let fileEnv = {};
try {
  fileEnv = parseEnvFile(readFileSync(join(projectRoot, '.env.development'), 'utf8'));
  console.log('🌐 Entorno dev cargado:', fileEnv.API_UPSTREAM || '(sin API_UPSTREAM, default prod)');
} catch (err) {
  console.warn('⚠️ No se pudo leer .env.development:', err.message);
}

// Iniciar servidor de Astro
console.log('📡 Iniciando servidor Astro (http://localhost:3000)...');
const astroProcess = spawn('npx', ['astro', 'dev'], {
    stdio: 'inherit',
    shell: true,
    env: { ...process.env }
});

let electronProcess = null;

// Esperar a que Astro esté listo (~5 segundos)
setTimeout(() => {
    console.log('\n⚡ Iniciando Electron...');
    
    try {
        electronProcess = spawn('npx', ['electron', '.'], {
            cwd: process.cwd(),
            shell: true,
            env: {
                ...fileEnv,
                ...process.env,
                NODE_ENV: 'development',
                ELECTRON_DEV_URL: 'http://localhost:3000'
            },
            stdio: 'inherit'
        });

        console.log('✅ Electron iniciado correctamente\n');
        
    } catch (err) {
        console.error('❌ Error al iniciar Electron:', err.message);
        console.log('   Instalalo con: npm install --save-dev electron');
    }
}, 5000);

// Manejar cierre limpio
function cleanup() {
    console.log('\n🛑 Cerrando procesos...');
    
    if (astroProcess) {
        astroProcess.kill('SIGINT');
    }
    
    if (electronProcess) {
        electronProcess.kill('SIGINT');
    }
    
    process.exit(0);
}

process.on('SIGINT', cleanup);
process.on('SIGTERM', cleanup);
