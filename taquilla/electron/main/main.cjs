const { app, BrowserWindow, protocol, session, Menu } = require('electron');
const path = require('path');
const fs = require('fs');
const { registerIpcHandlers } = require('./ipcHandlers.cjs');
const { createUpstreamResolver } = require('./upstream.cjs');

let mainWindow;

// Resolver de upstream: dev lee env con fallback prod; empaquetado siempre prod.
const upstream = createUpstreamResolver({ isPackaged: app.isPackaged, env: process.env });

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
                // El archivo no existe -> 404 real (sin fallback SPA a index.html)
                console.log('🚫 Archivo no encontrado:', filePath);
                return new Response('Not Found', { status: 404 });
            }

            // Si es un directorio, buscar index.html dentro
            if (stats.isDirectory()) {
                const indexPath = path.join(filePath, 'index.html');
                try {
                    await fs.promises.stat(indexPath);
                    filePath = indexPath;
                } catch (err) {
                    // Directorio sin index.html -> 404 real (sin fallback a la raíz)
                    console.log('🚫 Directorio sin index.html:', filePath);
                    return new Response('Not Found', { status: 404 });
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
    console.log('🔄 Proxy API configurado:', upstream.get());
    // ... (código anterior)

// Protocolo proxy para la API (normaliza el path: garantiza <upstream>/api/v1/*)
protocol.handle('api', async (request) => {
    try {
        const url = new URL(request.url);
        const origin = request.headers.get('origin');
        const requestedHeaders = request.headers.get('access-control-request-headers');

        // CORS local del proxy: el renderer habla con api:// (corsEnabled) y el upstream
        // recibe Origin: app://index.html (o localhost en dev), por lo que no responde ACAO.
        // El proxy decide el CORS en lugar de depender del CORS de producción.
        const corsHeaders = {
            'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers': requestedHeaders || '*',
            'Access-Control-Max-Age': '600',
        };
        // Eco del Origin del renderer (dev: http://localhost:3000; empaquetado: app://index.html).
        // Sin Origin no se emite ACAO (evita un eco roto tipo "null").
        if (origin) {
            corsHeaders['Access-Control-Allow-Origin'] = origin;
        }

        // Preflight: se responde localmente con 204, nunca se reenvía al upstream.
        if (request.method === 'OPTIONS') {
            return new Response(null, { status: 204, headers: corsHeaders });
        }

        // Chromium normaliza los esquemas estándar promoviendo el primer segmento del
        // path a host (api:///api/v1/x llega como api://api/v1/x), por lo que el pathname
        // pierde el prefijo /api. Se restaura para que el upstream reciba /api/v1/*.
        let pathname = url.pathname;
        if (!pathname.startsWith('/api/')) {
            pathname = '/api' + pathname;
        }

        // Construir la URL de destino (upstream resuelto por request)
        const targetUrl = `${upstream.get()}${pathname}${url.search}`;
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

        // Devolver la respuesta al frontend.
        // Se eliminan los headers access-control-* del upstream (hoy pasan verbatim y
        // entran en conflicto con el CORS local) y se inyectan los del proxy.
        const headers = new Headers();
        for (const [name, value] of response.headers) {
            if (!name.toLowerCase().startsWith('access-control-')) {
                headers.append(name, value);
            }
        }
        for (const [name, value] of Object.entries(corsHeaders)) {
            headers.set(name, value);
        }

        return new Response(response.body, {
            status: response.status,
            statusText: response.statusText,
            headers,
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
    registerIpcHandlers(upstream);
    registerCustomProtocol();  // app://
    registerApiProtocol();     // api://

    // win-fixes3: sin menú nativo. El menú por defecto de Electron asigna
    // F11 a "Toggle Full Screen" y se lo roba al renderer, rompiendo la
    // tecla F11 «Números» de la taquilla. Al desactivarlo, F11 llega al
    // keydown del dashboard. openDevTools() en dev se mantiene programático.
    Menu.setApplicationMenu(null);

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
