const { ipcMain } = require('electron');
const os = require('os');

// Obtener MAC address del sistema
function getMacAddress() {
    const networkInterfaces = os.networkInterfaces();
    for (const interfaceName of Object.keys(networkInterfaces)) {
        for (const iface of networkInterfaces[interfaceName]) {
            if (!iface.internal && iface.mac && iface.mac !== '00:00:00:00:00:00') {
                return iface.mac;
            }
        }
    }
    return '00:00:00:00:00:00';
}

// Registrar los handlers IPC
function registerIpcHandlers() {
    ipcMain.handle('get-mac', () => {
        return getMacAddress();
    });

    // Placeholder para impresión (luego lo conectamos con electron-pos-printer)
    ipcMain.handle('print-ticket', async (event, data) => {
        console.log('🖨️ Imprimiendo ticket:', data);
        // Aquí irá la lógica real con electron-pos-printer
        return { success: true, message: 'Impresión simulada' };
    });

    // Handler para obtener la versión de la app
    ipcMain.handle('get-version', () => {
        return process.env.npm_package_version || '0.1.0';
    });
}

module.exports = {
    getMacAddress,
    registerIpcHandlers
};
