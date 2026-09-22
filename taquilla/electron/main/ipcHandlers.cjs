const { ipcMain, BrowserWindow, app } = require('electron');
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

// Escapar texto libre (referencia/concepto/nombres) antes de interpolar en HTML.
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function fmtMoney(value) {
    return Number(value || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtFecha(iso) {
    if (!iso) return '-';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '-';
    return d.toLocaleString('es-VE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: true
    });
}

const METODOS_CIERRE = ['efectivo', 'transferencia', 'pago_movil', 'punto_venta'];
const METODO_CIERRE_LABELS = {
    efectivo: 'Efectivo',
    transferencia: 'Transferencia',
    pago_movil: 'Pago Movil',
    punto_venta: 'Punto Venta'
};

function generateCierreHtml(cierreData) {
    const d = cierreData || {};

    // Strings libres escapadas antes de interpolar (threat matrix IPC)
    const referencia = escapeHtml(d.referencia || '');
    const concepto = escapeHtml(d.concepto || '');
    const taquillaNombre = escapeHtml(d.taquilla?.nombre || d.taquillaNombre || 'Taquilla');
    const creadorNombre = escapeHtml(d.creador?.name || d.creadorNombre || '');
    const cierreId = escapeHtml(String(d.id ?? ''));

    const rows = [];
    for (const moneda of ['bs', 'usd']) {
        const monedaLabel = moneda === 'bs' ? 'BS' : 'USD';
        for (const metodo of METODOS_CIERRE) {
            const m = d.desglose_metodos?.[moneda]?.[metodo] || { ventas: 0, egresos: 0, efectivo: 0 };
            rows.push(
                `<tr><td>${monedaLabel}</td><td>${METODO_CIERRE_LABELS[metodo]}</td>` +
                `<td>${fmtMoney(m.ventas)}</td><td>${fmtMoney(m.egresos)}</td><td>${fmtMoney(m.efectivo)}</td></tr>`
            );
        }
    }

    const tieneArqueo = d.arqueo_efectivo_bs !== null && d.arqueo_efectivo_bs !== undefined;

    return `
        <style>
            @page { margin: 0; size: 80mm auto; }
            body { font-family: 'Courier New', monospace; font-size: 11px; width: 72mm; margin: 0 auto; padding: 4px 2mm; }
            h2 { text-align: center; font-size: 14px; margin: 0 0 4px; }
            h3 { font-size: 11px; margin: 6px 0 2px; }
            hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
            table { width: 100%; border-collapse: collapse; font-size: 10px; }
            th, td { text-align: left; padding: 1px 0; }
            th { border-bottom: 1px solid #000; }
            .total { font-weight: bold; }
            .text-center { text-align: center; }
        </style>
        <div>
            <h2>CIERRE DE CAJA</h2>
            <p class="text-center">${taquillaNombre}</p>
            <hr>
            <p>Desde: ${fmtFecha(d.fecha_inicio)}</p>
            <p>Hasta: ${fmtFecha(d.fecha_fin)}</p>
            <p>Tasa: ${fmtMoney(d.exchange_rate_cierre)}</p>
            ${referencia ? `<p>Referencia: ${referencia}</p>` : ''}
            ${concepto ? `<p>Concepto: ${concepto}</p>` : ''}
            <hr>
            <h3>Totales</h3>
            <table>
                <thead><tr><th></th><th>BS</th><th>USD</th></tr></thead>
                <tbody>
                    <tr><td>Ventas</td><td>${fmtMoney(d.total_ventas_bs)}</td><td>${fmtMoney(d.total_ventas_usd)}</td></tr>
                    <tr><td>Egresos</td><td>${fmtMoney(d.total_egresos_bs)}</td><td>${fmtMoney(d.total_egresos_usd)}</td></tr>
                    <tr class="total"><td>Efectivo</td><td>${fmtMoney(d.total_efectivo_bs)}</td><td>${fmtMoney(d.total_efectivo_usd)}</td></tr>
                </tbody>
            </table>
            ${tieneArqueo ? `
            <h3>Arqueo / Diferencia</h3>
            <table>
                <thead><tr><th></th><th>BS</th><th>USD</th></tr></thead>
                <tbody>
                    <tr><td>Arqueo</td><td>${fmtMoney(d.arqueo_efectivo_bs)}</td><td>${fmtMoney(d.arqueo_efectivo_usd)}</td></tr>
                    <tr class="total"><td>Falt./Sobr.</td><td>${fmtMoney(d.faltante_sobrante_bs)}</td><td>${fmtMoney(d.faltante_sobrante_usd)}</td></tr>
                </tbody>
            </table>` : '<p>Sin arqueo registrado.</p>'}
            <hr>
            <h3>Desglose por metodo</h3>
            <table>
                <thead><tr><th>Mon</th><th>Metodo</th><th>Ventas</th><th>Egresos</th><th>Efectivo</th></tr></thead>
                <tbody>${rows.join('')}</tbody>
            </table>
            <hr>
            <p class="text-center">Cierre #${cierreId}${creadorNombre ? ' - ' + creadorNombre : ''}</p>
            <p class="text-center">Gracias!</p>
        </div>
    `;
}

// Reporte por rango (AD-12/AD-13): totales + desglose fusionado + listado de
// cierres con su desglose. Strings libres escapadas; montos es-VE (carry-over).
function generateReporteHtml(reporteData) {
    const r = reporteData || {};
    const ventana = r.ventana_cubierta || {};
    const cierres = Array.isArray(r.cierres) ? r.cierres : [];
    const incluidos = ventana.cierres_incluidos !== undefined && ventana.cierres_incluidos !== null
        ? ventana.cierres_incluidos
        : cierres.length;

    const taquillaNombre = escapeHtml(r.taquilla?.nombre || r.taquillaNombre || '');
    const fechaDesde = escapeHtml(r.fecha_desde || '');
    const fechaHasta = escapeHtml(r.fecha_hasta || '');
    const cubiertoDesde = ventana.desde ? fmtFecha(ventana.desde) : '-';
    const cubiertoHasta = ventana.hasta ? fmtFecha(ventana.hasta) : '-';
    const tieneArqueo = r.arqueo_efectivo_bs !== null && r.arqueo_efectivo_bs !== undefined;

    let fusionRows = '';
    for (const moneda of ['bs', 'usd']) {
        const monedaLabel = moneda === 'bs' ? 'BS' : 'USD';
        for (const metodo of METODOS_CIERRE) {
            const m = r.desglose_metodos?.[moneda]?.[metodo] || { ventas: 0, egresos: 0, efectivo: 0 };
            fusionRows += `<tr><td>${monedaLabel}</td><td>${METODO_CIERRE_LABELS[metodo]}</td>` +
                `<td>${fmtMoney(m.ventas)}</td><td>${fmtMoney(m.egresos)}</td><td>${fmtMoney(m.efectivo)}</td></tr>`;
        }
    }

    let cierresHtml = '';
    cierres.forEach((c) => {
        const cierreId = escapeHtml(String(c.id ?? ''));
        const taquillaId = escapeHtml(String(c.taquilla_id ?? ''));
        const tieneArqueoCierre = c.arqueo_efectivo_bs !== null && c.arqueo_efectivo_bs !== undefined;

        let desgloseRows = '';
        for (const moneda of ['bs', 'usd']) {
            const monedaLabel = moneda === 'bs' ? 'BS' : 'USD';
            for (const metodo of METODOS_CIERRE) {
                const m = c.desglose_metodos?.[moneda]?.[metodo] || { ventas: 0, egresos: 0, efectivo: 0 };
                desgloseRows += `<tr><td>${monedaLabel}</td><td>${METODO_CIERRE_LABELS[metodo]}</td>` +
                    `<td>${fmtMoney(m.ventas)}</td><td>${fmtMoney(m.egresos)}</td><td>${fmtMoney(m.efectivo)}</td></tr>`;
            }
        }

        cierresHtml += `
            <hr>
            <h3>Cierre #${cierreId} - Taquilla #${taquillaId}</h3>
            <p>Desde: ${fmtFecha(c.fecha_inicio)}</p>
            <p>Hasta: ${fmtFecha(c.fecha_fin)}</p>
            <p>Tasa: ${fmtMoney(c.exchange_rate_cierre)}</p>
            <table>
                <thead><tr><th></th><th>BS</th><th>USD</th></tr></thead>
                <tbody>
                    <tr><td>Ventas</td><td>${fmtMoney(c.total_ventas_bs)}</td><td>${fmtMoney(c.total_ventas_usd)}</td></tr>
                    <tr><td>Egresos</td><td>${fmtMoney(c.total_egresos_bs)}</td><td>${fmtMoney(c.total_egresos_usd)}</td></tr>
                    <tr class="total"><td>Efectivo</td><td>${fmtMoney(c.total_efectivo_bs)}</td><td>${fmtMoney(c.total_efectivo_usd)}</td></tr>
                </tbody>
            </table>
            ${tieneArqueoCierre ? `
            <table>
                <thead><tr><th></th><th>BS</th><th>USD</th></tr></thead>
                <tbody>
                    <tr><td>Arqueo</td><td>${fmtMoney(c.arqueo_efectivo_bs)}</td><td>${fmtMoney(c.arqueo_efectivo_usd)}</td></tr>
                    <tr class="total"><td>Falt./Sobr.</td><td>${fmtMoney(c.faltante_sobrante_bs)}</td><td>${fmtMoney(c.faltante_sobrante_usd)}</td></tr>
                </tbody>
            </table>` : '<p>Sin arqueo registrado.</p>'}
            <h3>Desglose #${cierreId}</h3>
            <table>
                <thead><tr><th>Mon</th><th>Metodo</th><th>Ventas</th><th>Egresos</th><th>Efectivo</th></tr></thead>
                <tbody>${desgloseRows}</tbody>
            </table>`;
    });

    return `
        <style>
            @page { margin: 0; size: 80mm auto; }
            body { font-family: 'Courier New', monospace; font-size: 11px; width: 72mm; margin: 0 auto; padding: 4px 2mm; }
            h2 { text-align: center; font-size: 14px; margin: 0 0 4px; }
            h3 { font-size: 11px; margin: 6px 0 2px; }
            hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
            table { width: 100%; border-collapse: collapse; font-size: 10px; }
            th, td { text-align: left; padding: 1px 0; }
            th { border-bottom: 1px solid #000; }
            .total { font-weight: bold; }
            .text-center { text-align: center; }
        </style>
        <div>
            <h2>REPORTE POR RANGO</h2>
            <p class="text-center">${taquillaNombre || 'Taquilla'}</p>
            <hr>
            <p>Desde: ${fechaDesde}</p>
            <p>Hasta: ${fechaHasta}</p>
            <p>Cierres incluidos: ${escapeHtml(String(incluidos))}</p>
            <p>Cubierto: ${cubiertoDesde} - ${cubiertoHasta}</p>
            <hr>
            <h3>Totales</h3>
            <table>
                <thead><tr><th></th><th>BS</th><th>USD</th></tr></thead>
                <tbody>
                    <tr><td>Ventas</td><td>${fmtMoney(r.total_ventas_bs)}</td><td>${fmtMoney(r.total_ventas_usd)}</td></tr>
                    <tr><td>Egresos</td><td>${fmtMoney(r.total_egresos_bs)}</td><td>${fmtMoney(r.total_egresos_usd)}</td></tr>
                    <tr class="total"><td>Efectivo</td><td>${fmtMoney(r.total_efectivo_bs)}</td><td>${fmtMoney(r.total_efectivo_usd)}</td></tr>
                </tbody>
            </table>
            ${tieneArqueo ? `
            <table>
                <thead><tr><th></th><th>BS</th><th>USD</th></tr></thead>
                <tbody>
                    <tr><td>Arqueo</td><td>${fmtMoney(r.arqueo_efectivo_bs)}</td><td>${fmtMoney(r.arqueo_efectivo_usd)}</td></tr>
                    <tr class="total"><td>Falt./Sobr.</td><td>${fmtMoney(r.faltante_sobrante_bs)}</td><td>${fmtMoney(r.faltante_sobrante_usd)}</td></tr>
                </tbody>
            </table>` : '<p>Sin arqueo agregado.</p>'}
            <hr>
            <h3>Desglose fusionado</h3>
            <table>
                <thead><tr><th>Mon</th><th>Metodo</th><th>Ventas</th><th>Egresos</th><th>Efectivo</th></tr></thead>
                <tbody>${fusionRows}</tbody>
            </table>
            ${cierresHtml}
            <hr>
            <p class="text-center">Gracias!</p>
        </div>
    `;
}

async function printWithSystemDialog(win, html, noun = 'Documento') {
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
                if (success) resolve({ success: true, message: noun + ' enviado a impresora' });
                else reject(new Error(failureReason || 'Impresion cancelada'));
            });
        });

        printWin.webContents.on('did-fail-load', (event, code, desc) => {
            printWin.close();
            reject(new Error('Error cargando ' + noun.toLowerCase() + ': ' + desc));
        });
    });
}

// Deteccion de impresora POS con fallback a dialogo del sistema (AD-9).
async function printHtml(win, html, noun = 'Documento') {
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
                return { success: true, message: noun + ' impreso en ' + printerName };
            }
        } catch (e) {
            console.warn('electron-pos-printer fallo, usando dialogo del sistema:', e.message);
        }
    }

    try {
        return await printWithSystemDialog(win, html, noun);
    } catch (e) {
        return { success: false, message: e.message };
    }
}

function registerIpcHandlers(upstream) {
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

        const win = BrowserWindow.getFocusedWindow();
        const html = generateTicketHtml(ticketData);
        return await printHtml(win, html, 'Ticket');
    });

    ipcMain.handle('print-cierre', async (event, data) => {
        const { cierreData } = data;
        if (!cierreData || typeof cierreData !== 'object' || Array.isArray(cierreData)) {
            return { success: false, message: 'Datos de cierre invalidos' };
        }

        const win = BrowserWindow.getFocusedWindow();
        const html = generateCierreHtml(cierreData);
        return await printHtml(win, html, 'Cierre');
    });

    // Reporte por rango: totales + listado de cierres con desglose (AD-13).
    ipcMain.handle('print-reporte', async (event, data) => {
        const { reporteData } = data;
        if (!reporteData || typeof reporteData !== 'object' || Array.isArray(reporteData)) {
            return { success: false, message: 'Datos de reporte invalidos' };
        }

        const win = BrowserWindow.getFocusedWindow();
        const html = generateReporteHtml(reporteData);
        return await printHtml(win, html, 'Reporte');
    });

    ipcMain.handle('get-version', () => {
        return app.getVersion();
    });
}

module.exports = {
    getMacAddress,
    generateCierreHtml,
    generateReporteHtml,
    registerIpcHandlers
};
