const { ipcMain } = require('electron');
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
            body { font-family: 'Courier New', monospace; font-size: 12px; width: 72mm; margin: 0 auto; padding: 5px 3mm; }
            h2 { text-align: center; font-size: 16px; margin: 0 0 5px; }
            hr { border: none; border-top: 1px dashed #000; margin: 5px 0; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th, td { text-align: left; padding: 2px 0; }
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

function registerIpcHandlers() {
    ipcMain.handle('get-mac', () => {
        return getMacAddress();
    });

    ipcMain.handle('print-ticket', async (event, data) => {
        const { ticketData } = data;
        if (!ticketData || !ticketData.lines || ticketData.lines.length === 0) {
            return { success: false, message: 'Datos de ticket invalidos' };
        }

        try {
            const html = generateTicketHtml(ticketData);

            let posPrinter;
            try {
                posPrinter = require('electron-pos-printer');
            } catch (e) {
                console.log('electron-pos-printer no disponible, usando impresion simulada');
                console.log('Ticket HTML:', html);
                return { success: true, message: 'Impresion simulada (electron-pos-printer no instalado)' };
            }

            const printerOptions = {
                preview: false,
                width: '80mm',
                margin: '0 0 0 0',
                copies: 1,
                printerName: 'POS-80',
                timeOutPerLine: 400,
                pageSize: { height: 150000, width: 800 }
            };

            await posPrinter.POSPrinter.print(html, printerOptions);

            return { success: true, message: 'Ticket impreso correctamente' };
        } catch (error) {
            console.error('Error imprimiendo ticket:', error);
            return { success: false, message: error.message };
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
