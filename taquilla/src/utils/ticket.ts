function pad(text: string | number, length: number): string {
  const s = String(text);
  return s.length >= length ? s.slice(0, length) : s + ' '.repeat(length - s.length);
}

function separator(char: string = '═', length: number = 36): string {
  return char.repeat(length);
}

export interface TicketLine {
  animal: string;
  number: number | string;
  amountBs: number;
  amountUsd: number;
}

export interface TicketData {
  ticketCode: string;
  date: string;
  time: string;
  game: string;
  lines: TicketLine[];
}

export function generateTicketText(data: TicketData): string {
  const totalBs = data.lines.reduce((s, l) => s + (l.amountBs || 0), 0);
  const totalUsd = data.lines.reduce((s, l) => s + (l.amountUsd || 0), 0);

  const header = [
    `╔${separator()}╗`,
    `║${pad('LOTTO TICKET', 36)}║`,
    `╠${separator()}╣`,
    `║ Ticket: ${pad(data.ticketCode, 28)}║`,
    `║ Fecha:  ${pad(data.date, 28)}║`,
    `║ Hora:   ${pad(data.time, 28)}║`,
    `║ Juego:  ${pad(data.game, 28)}║`,
    `╠${separator()}╣`,
  ];

  const bodyLines = data.lines.map((l, i) => {
    return `║ ${pad((i + 1) + '.', 3)} ${pad(l.animal, 10)} #${pad(l.number, 2)}  Bs.${pad(l.amountBs.toFixed(2), 8)} $${pad(l.amountUsd.toFixed(2), 8)}║`;
  });

  const footer = [
    `╠${separator()}╣`,
    `║${pad('Total Bs.: ' + totalBs.toFixed(2), 36)}║`,
    `║${pad('Total USD: $' + totalUsd.toFixed(2), 36)}║`,
    `╚${separator()}╝`,
  ];

  return [...header, ...bodyLines, ...footer].join('\n');
}

export function generateTicketHtml(data: TicketData): string {
  const totalBs = data.lines.reduce((s, l) => s + (l.amountBs || 0), 0);
  const totalUsd = data.lines.reduce((s, l) => s + (l.amountUsd || 0), 0);

  const linesHtml = data.lines.map((l, i) =>
    `<tr><td>${i + 1}.</td><td>${l.animal}</td><td>#${l.number}</td><td>Bs. ${l.amountBs.toFixed(2)}</td><td>$${l.amountUsd.toFixed(2)}</td></tr>`
  ).join('');

  return `
    <div style="font-family:monospace;font-size:12px;width:280px;padding:10px;">
      <h2 style="text-align:center;margin:0 0 8px;">LOTTO TICKET</h2>
      <hr>
      <p>Ticket: ${data.ticketCode}</p>
      <p>Fecha: ${data.date} - ${data.time}</p>
      <p>Juego: ${data.game}</p>
      <hr>
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr><th>#</th><th>Animal</th><th>N</th><th>BS</th><th>USD</th></tr></thead>
        <tbody>${linesHtml}</tbody>
      </table>
      <hr>
      <p><strong>Total BS: Bs. ${totalBs.toFixed(2)}</strong></p>
      <p><strong>Total USD: $${totalUsd.toFixed(2)}</strong></p>
    </div>
  `.trim();
}
