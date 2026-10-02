const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electron', {
    getMac: () => ipcRenderer.invoke('get-mac'),
    printTicket: (data) => ipcRenderer.invoke('print-ticket', data),
    printCierre: (data) => ipcRenderer.invoke('print-cierre', data),
    printReporte: (data) => ipcRenderer.invoke('print-reporte', data),
    getVersion: () => ipcRenderer.invoke('get-version'),
    setApiUpstream: (key) => ipcRenderer.invoke('set-api-upstream', key),

    // ─── Auto-update OTA (TQ-10, U2): eventos main → renderer y llamadas
    // renderer → main (contracto IPC del design §Interfaces).
    onUpdateEvent: (callback) => {
        const channels = ['update:checking', 'update:available', 'update:progress', 'update:downloaded', 'update:error'];
        const handlers = channels.map((channel) => {
            const handler = (_event, payload) => callback(channel, payload);
            ipcRenderer.on(channel, handler);
            return [channel, handler];
        });
        return () => handlers.forEach(([channel, handler]) => ipcRenderer.removeListener(channel, handler));
    },
    onUpdateBusyQuery: (callback) => {
        const handler = (_event, payload) => callback(payload);
        ipcRenderer.on('update:query-busy', handler);
        return () => ipcRenderer.removeListener('update:query-busy', handler);
    },
    respondBusy: (requestId, busy) => ipcRenderer.send('update:respond-busy', { requestId, busy }),
    reportBusy: (busy) => ipcRenderer.send('update:report-busy', busy),
    installUpdate: () => ipcRenderer.invoke('update:install'),
    getUpdateState: () => ipcRenderer.invoke('update:get-state'),
});