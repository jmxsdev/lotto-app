const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electron', {
    getMac: () => ipcRenderer.invoke('get-mac'),
    printTicket: (data) => ipcRenderer.invoke('print-ticket', data),
    getVersion: () => ipcRenderer.invoke('get-version'),
});
