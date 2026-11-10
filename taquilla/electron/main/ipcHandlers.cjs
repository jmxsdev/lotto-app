const { ipcMain, app } = require('electron');
const os = require('os');

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

/**
 * @param {import('./printing/types').Printing|undefined} printing
 */
function registerIpcHandlers(upstream, updater, printing) {
    ipcMain.handle('get-mac', () => {
        return getMacAddress();
    });

    // Selector dev-only de entorno: whitelist fija; empaquetado siempre rechaza.
    ipcMain.handle('set-api-upstream', (event, key) => {
        return upstream.setOverride(key);
    });

    ipcMain.handle('print-ticket', async (event, data) => {
        const { ticketData } = data;
        if (!ticketData || !ticketData.lines || ticketData.lines.length === 0) {
            return { success: false, message: 'Datos de ticket invalidos' };
        }
        if (!printing || typeof printing.printDocument !== 'function') {
            return { success: false, message: 'Impresion no disponible' };
        }

        return printing.printDocument({
            kind: ticketData.kind === 'recibo' ? 'recibo' : 'ticket',
            data: ticketData,
            webContents: event.sender,
        });
    });

    ipcMain.handle('print-cierre', async (event, data) => {
        const { cierreData } = data;
        if (!cierreData || typeof cierreData !== 'object' || Array.isArray(cierreData)) {
            return { success: false, message: 'Datos de cierre invalidos' };
        }
        if (!printing || typeof printing.printDocument !== 'function') return { success: false, message: 'Impresion no disponible' };

        return printing.printDocument({
            kind: 'cierre',
            data: cierreData,
            webContents: event.sender,
        });
    });

    // Reporte por rango: totales + listado de cierres con desglose (AD-13).
    ipcMain.handle('print-reporte', async (event, data) => {
        const { reporteData } = data;
        if (!reporteData || typeof reporteData !== 'object' || Array.isArray(reporteData)) {
            return { success: false, message: 'Datos de reporte invalidos' };
        }
        if (!printing || typeof printing.printDocument !== 'function') return { success: false, message: 'Impresion no disponible' };

        return printing.printDocument({
            kind: 'reporte',
            data: reporteData,
            webContents: event.sender,
        });
    });

    ipcMain.handle('get-version', () => {
        return app.getVersion();
    });

    // ─── Auto-update OTA (TQ-10, U2): contracto IPC del design §Interfaces ───
    // renderer → main. install() aplica el guard de venta (query-busy 500 ms
    // = busy + cache reportBusy); get-state rehidrata tras recargar el
    // renderer; report-busy/respond-busy alimentan el cache de busy.
    if (updater) {
        ipcMain.handle('update:install', () => updater.install());
        ipcMain.handle('update:get-state', () => updater.getState());
        ipcMain.on('update:report-busy', (event, busy) => updater.reportBusy(busy));
        ipcMain.on('update:respond-busy', (event, { requestId, busy }) => updater.respondBusy(requestId, busy));
    }
}

module.exports = {
    getMacAddress,
    registerIpcHandlers
};
