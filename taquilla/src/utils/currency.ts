export function formatBs(amount: number): string {
  return `Bs. ${amount.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function formatUsd(amount: number): string {
  return `$ ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function calculateEquivalent(amountBs: number, amountUsd: number, rate: number): number {
  return amountBs + (amountUsd * rate);
}

export function bsToUsd(amountBs: number, rate: number): number {
  return rate > 0 ? amountBs / rate : 0;
}

export function usdToBs(amountUsd: number, rate: number): number {
  return amountUsd * rate;
}

export function getCurrentTime(): string {
  return new Date().toLocaleTimeString('es-VE', { hour: '2-digit', minute: '2-digit' });
}

export function formatDate(date: Date): string {
  return date.toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
