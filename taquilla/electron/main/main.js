const { app, BrowserWindow } = require('electron');
const path = require('path');
const { registerIpcHandlers } = require('./ipcHandlers');

let mainWindow;

function createWindow() {
    mainWindow = new BrowserWindow({
        width: 1024,
        height: 768,
        webPreferences: {
            preload: path.join(__dirname, '../preload/preload.js'),
            nodeIntegration: false,
            contextIsolation: true,
        },
        icon: path.join(__dirname, '../../public/icon.ico'),
    });

    // Usar servidor de desarrollo de Astro en modo Electron dev
    const electronDevUrl = process.env.ELECTRON_DEV_URL || 'http://localhost:3000';
    
    if (process.env.NODE_ENV === 'development' || process.env.ELECTRON_DEV_URL) {
        console.log('🚀 Cargando desde servidor de desarrollo:', electronDevUrl);
        mainWindow.loadURL(electronDevUrl);
        
        mainWindow.webContents.openDevTools();
    } else {
        // Modo producción: cargar archivos estáticos
        console.log('📦 Cargando archivos estáticos');
        const indexPath = path.join(__dirname, '../../dist/login/index.html');
        mainWindow.loadFile(indexPath);
    }

    mainWindow.on('closed', () => {
        mainWindow = null;
    });
}

app.whenReady().then(() => {
    registerIpcHandlers();
    createWindow();
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
