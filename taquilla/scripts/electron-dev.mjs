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

// Esperar a que Astro RESPONDA antes de lanzar Electron. Un timer fijo es
// frágil: en cold start (Vite re-optimizando dependencias) el server puede
// tardar >5s y Electron cargaba una ventana en blanco (conexión rechazada).
const DEV_URL = 'http://localhost:3000';
const POLL_TIMEOUT_MS = 120000;
const POLL_INTERVAL_MS = 400;

async function waitForServer(url, timeoutMs) {
    const startedAt = Date.now();
    while (Date.now() - startedAt < timeoutMs) {
        try {
            await fetch(url, { signal: AbortSignal.timeout(2000) });
            return true;
        } catch {
            await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
        }
    }
    return false;
}

async function startElectron() {
    console.log(`\n⏳ Esperando a que el dev server responda en ${DEV_URL}...`);
    const ready = await waitForServer(DEV_URL, POLL_TIMEOUT_MS);

    if (!ready) {
        console.error(`❌ El dev server no respondió en ${POLL_TIMEOUT_MS / 1000}s. Abortando.`);
        cleanup();
        return;
    }

    console.log('⚡ Iniciando Electron...');

    try {
        electronProcess = spawn('npx', ['electron', '.'], {
            cwd: process.cwd(),
            shell: true,
            env: {
                ...fileEnv,
                ...process.env,
                NODE_ENV: 'development',
                ELECTRON_DEV_URL: DEV_URL
            },
            stdio: 'inherit'
        });

        console.log('✅ Electron iniciado correctamente\n');

    } catch (err) {
        console.error('❌ Error al iniciar Electron:', err.message);
        console.log('   Instalalo con: npm install --save-dev electron');
    }
}

startElectron();

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
