const { app, BrowserWindow, protocol, session } = require('electron');
const path = require('path');
const fs = require('fs');
const { registerIpcHandlers } = require('./ipcHandlers.cjs');

let mainWindow;

// Determinar la ruta de la carpeta dist (estáticos)
function getDistPath() {
    if (process.env.NODE_ENV === 'development' || !app.isPackaged) {
        return path.resolve(__dirname, '../../dist');
    } else {
        return path.resolve(process.resourcesPath, 'dist');
    }
}

// Registrar esquemas privilegiados (incluye 'api')
protocol.registerSchemesAsPrivileged([
    {
        scheme: 'app',
        privileges: {
            standard: true,
            secure: true,
            supportFetchAPI: true,
            corsEnabled: true,
            allowServiceWorkers: true,
        },
    },
    {
        scheme: 'api',
        privileges: {
            standard: true,
            secure: true,
            supportFetchAPI: true,
            corsEnabled: true,
        },
    },
]);

function registerCustomProtocol() {
    const distPath = getDistPath();
    console.log('📂 Sirviendo estáticos desde:', distPath);

    // Verificar que la carpeta dist existe
    if (!fs.existsSync(distPath)) {
        console.error('❌ La carpeta dist no existe en:', distPath);
        return;
    }

    protocol.handle('app', async (request) => {
        try {
            const url = new URL(request.url);
            let pathname = url.pathname;

            // Normalizar: eliminar barra final (excepto raíz)
            if (pathname !== '/' && pathname.endsWith('/')) {
                pathname = pathname.slice(0, -1);
            }
            // Si es raíz o vacío, usar index.html
            if (pathname === '' || pathname === '/') {
                pathname = '/index.html';
            }

            // Construir ruta absoluta
            let filePath = path.join(distPath, pathname);
            console.log('📄 Solicitado:', filePath);

            // Verificar si existe el archivo
            let stats;
            try {
                stats = await fs.promises.stat(filePath);
            } catch (err) {
                // El archivo no existe -> SPA: servir index.html
                filePath = path.join(distPath, 'index.html');
                console.log('🔄 Archivo no encontrado, sirviendo index.html');
                stats = await fs.promises.stat(filePath);
            }

            // Si es un directorio, buscar index.html dentro
            if (stats.isDirectory()) {
                const indexPath = path.join(filePath, 'index.html');
                try {
                    await fs.promises.stat(indexPath);
                    filePath = indexPath;
                } catch (err) {
                    // Si no hay index.html en el directorio, usar el raíz
                    filePath = path.join(distPath, 'index.html');
                    console.log('📁 Directorio sin index.html, usando raíz');
                }
                stats = await fs.promises.stat(filePath);
            }

            // Leer el archivo
            const data = await fs.promises.readFile(filePath);
            const ext = path.extname(filePath).toLowerCase();
            const mimeTypes = {
                '.html': 'text/html',
                '.js': 'text/javascript',
                '.css': 'text/css',
                '.json': 'application/json',
                '.png': 'image/png',
                '.jpg': 'image/jpeg',
                '.gif': 'image/gif',
                '.svg': 'image/svg+xml',
                '.ico': 'image/x-icon',
            };
            const mimeType = mimeTypes[ext] || 'application/octet-stream';

            return new Response(data, {
                headers: { 'Content-Type': mimeType }
            });
        } catch (error) {
            console.error('❌ Error sirviendo archivo:', error);
            return new Response('Internal Server Error', { status: 500 });
        }
    });
}
// Registrar protocolo api:// como proxy a la API real
function registerApiProtocol() {
    const API_BASE = 'https://lotto.gzuz.dev';
    console.log('🔄 Proxy API configurado:', API_BASE);
    // ... (código anterior)

// Protocolo proxy para la API
protocol.handle('api', async (request) => {
    try {
        const url = new URL(request.url);
        // Construir la URL de destino
        const targetUrl = `https://lotto.gzuz.dev/api${url.pathname}${url.search}`;
        console.log('🔀 Reenviando a:', targetUrl);

        // Preparar las opciones para fetch
        const fetchOptions = {
            method: request.method,
            headers: request.headers,
            // Si la petición tiene un cuerpo, lo incluimos y añadimos duplex: 'half'
            duplex: 'half', // <--- CLAVE: Opción requerida por Node.js
        };

        // Leer el cuerpo de la petición si existe
        // (Importante: 'request.body' es un ReadableStream)
        if (request.body) {
            // Para peticiones con cuerpo (POST, PUT, etc.)
            const reader = request.body.getReader();
            const chunks = [];
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                chunks.push(value);
            }
            // Combinar los chunks en un solo Buffer o Uint8Array
            const body = new Uint8Array(chunks.reduce((acc, chunk) => acc + chunk.length, 0));
            let offset = 0;
            for (const chunk of chunks) {
                body.set(chunk, offset);
                offset += chunk.length;
            }
            fetchOptions.body = body;
        }

        // Realizar la petición a la API
        const response = await fetch(targetUrl, fetchOptions);

        // Devolver la respuesta al frontend
        // Nota: response.body es un ReadableStream, podemos pasarlo directamente
        return new Response(response.body, {
            status: response.status,
            statusText: response.statusText,
            headers: response.headers,
        });
    } catch (error) {
        console.error('❌ Error en proxy API:', error);
        return new Response('Error en el proxy de API', { status: 500 });
    }
});

// ... (resto del código)

    }

async function createWindow() {
    mainWindow = new BrowserWindow({
        width: 1024,
        height: 768,
        webPreferences: {
            preload: path.join(__dirname, '../preload/preload.cjs'),
            nodeIntegration: false,
            contextIsolation: true,
        },
        icon: path.join(__dirname, '../../public/icon.ico'),
    });

    // Modo desarrollo
    if (process.env.NODE_ENV === 'development' || process.env.ELECTRON_DEV_URL) {
        const devUrl = process.env.ELECTRON_DEV_URL || 'http://localhost:3000';
        console.log('🚀 Cargando desde servidor de desarrollo:', devUrl);
        await mainWindow.loadURL(devUrl);
        mainWindow.webContents.openDevTools();
    } else {
        // Modo producción: usar protocolo app://
        console.log('📦 Cargando desde protocolo app://');
        await mainWindow.loadURL('app://index.html');
        // mainWindow.webContents.openDevTools(); // Descomentar para debug
    }

    mainWindow.on('closed', () => {
        mainWindow = null;
    });
}

app.whenReady().then(async () => {
    registerIpcHandlers();
    registerCustomProtocol();  // app://
    registerApiProtocol();     // api://

    await createWindow();
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});

app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
        createWindow();
    }
});
