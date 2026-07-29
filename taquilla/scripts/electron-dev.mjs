import { spawn } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

console.log('🚀 Iniciando Lotto Taquilla en modo desarrollo...\n');

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
