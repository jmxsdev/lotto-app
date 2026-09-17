const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electron', {
    getMac: () => ipcRenderer.invoke('get-mac'),
    printTicket: (data) => ipcRenderer.invoke('print-ticket', data),
    printCierre: (data) => ipcRenderer.invoke('print-cierre', data),
    getVersion: () => ipcRenderer.invoke('get-version'),
    setApiUpstream: (key) => ipcRenderer.invoke('set-api-upstream', key),
});
