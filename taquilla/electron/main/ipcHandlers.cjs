const { ipcMain, BrowserWindow } = require('electron');
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

function generateTicketHtml(ticketData) {
    const { ticketCode, date, time, game, lines } = ticketData;
    const totalBs = lines.reduce((s, l) => s + (l.amountBs || 0), 0);
    const totalUsd = lines.reduce((s, l) => s + (l.amountUsd || 0), 0);

    const rows = lines.map((l, i) =>
        `<tr><td>${i + 1}.</td><td>${l.animal}</td><td>#${l.number}</td><td>Bs. ${(l.amountBs || 0).toFixed(2)}</td><td>$${(l.amountUsd || 0).toFixed(2)}</td></tr>`
    ).join('');

    return `
        <style>
            @page { margin: 0; size: 80mm auto; }
            body { font-family: 'Courier New', monospace; font-size: 11px; width: 72mm; margin: 0 auto; padding: 4px 2mm; }
            h2 { text-align: center; font-size: 14px; margin: 0 0 4px; }
            hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
            table { width: 100%; border-collapse: collapse; font-size: 10px; }
            th, td { text-align: left; padding: 1px 0; }
            th { border-bottom: 1px solid #000; }
            .total { font-weight: bold; }
            .text-center { text-align: center; }
        </style>
        <div>
            <h2>LOTTO TICKET</h2>
            <p class="text-center">${game}</p>
            <hr>
            <p>Ticket: ${ticketCode}</p>
            <p>Fecha: ${date} - ${time}</p>
            <hr>
            <table>
                <thead><tr><th>#</th><th>Animal</th><th>N</th><th>BS</th><th>USD</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <hr>
            <p class="total">Total BS: Bs. ${totalBs.toFixed(2)}</p>
            <p class="total">Total USD: $${totalUsd.toFixed(2)}</p>
            <hr>
            <p class="text-center">Gracias por su compra!</p>
        </div>
    `;
}

async function printWithSystemDialog(win, html) {
    return new Promise((resolve, reject) => {
        const printWin = new BrowserWindow({
            width: 300,
            height: 400,
            show: false,
            webPreferences: { nodeIntegration: false, contextIsolation: true }
        });

        printWin.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));

        printWin.webContents.on('did-finish-load', () => {
            printWin.webContents.print({
                silent: false,
                printBackground: true,
                margins: { marginType: 'none' },
                pageSize: { width: 80000, height: 150000 }
            }, (success, failureReason) => {
                printWin.close();
                if (success) resolve({ success: true, message: 'Ticket enviado a impresora' });
                else reject(new Error(failureReason || 'Impresion cancelada'));
            });
        });

        printWin.webContents.on('did-fail-load', (event, code, desc) => {
            printWin.close();
            reject(new Error('Error cargando ticket: ' + desc));
        });
    });
}

function registerIpcHandlers() {
    ipcMain.handle('get-mac', () => {
        return getMacAddress();
    });

    ipcMain.handle('print-ticket', async (event, data) => {
        const { ticketData } = data;
        if (!ticketData || !ticketData.lines || ticketData.lines.length === 0) {
            return { success: false, message: 'Datos de ticket invalidos' };
        }

        const win = BrowserWindow.getFocusedWindow();
        const html = generateTicketHtml(ticketData);

        const posPrinterAvailable = (() => {
            try {
                require('electron-pos-printer');
                return true;
            } catch (e) {
                return false;
            }
        })();

        if (posPrinterAvailable) {
            try {
                const posPrinter = require('electron-pos-printer');
                const printers = await posPrinter.POSPrinter.getPrinterList();
                const posPrinters = printers.filter(p =>
                    p.name.toLowerCase().includes('pos') ||
                    p.name.toLowerCase().includes('thermal') ||
                    p.name.toLowerCase().includes('ticket') ||
                    p.name.toLowerCase().includes('receipt')
                );

                if (posPrinters.length > 0) {
                    const printerName = posPrinters[0].name;
                    await posPrinter.POSPrinter.print(html, {
                        preview: false,
                        width: '80mm',
                        margin: '0 0 0 0',
                        copies: 1,
                        printerName: printerName,
                        timeOutPerLine: 400,
                        pageSize: { height: 150000, width: 800 }
                    });
                    return { success: true, message: 'Ticket impreso en ' + printerName };
                }
            } catch (e) {
                console.warn('electron-pos-printer fallo, usando dialogo del sistema:', e.message);
            }
        }

        try {
            return await printWithSystemDialog(win, html);
        } catch (e) {
            return { success: false, message: e.message };
        }
    });

    ipcMain.handle('get-version', () => {
        return process.env.npm_package_version || '0.1.0';
    });
}

module.exports = {
    getMacAddress,
    registerIpcHandlers
};
