// Contracts for the native ESC/POS printing module.

export type PrintKind = 'ticket' | 'recibo' | 'cierre' | 'reporte';
export type Paper = '58mm' | '80mm' | '80mm-512';
export type CodePage = 'cp437' | 'cp850' | 'cp858' | 'cp1252' | 'ascii';
export type Cut = 'partial' | 'full' | 'none';

/** Fully resolved config: env > printer-config.json > defaults. */
export interface EffectiveConfig {
  printerName: string | null;
  paper: Paper;
  columns: number;
  dots: number;
  codePage: CodePage;
  cut: Cut;
  feedLines: number;
  cutPerBlock: boolean;
  printTest: boolean;
  warnings: readonly string[];
}

export interface TicketLine {
  game?: string;
  jugada?: string;
  sorteo?: string;
  animal?: string;
  number?: string | number;
  amountBs: number;
  amountUsd: number;
}

export interface TicketData {
  ticketCode: string;
  date: string;
  time: string;
  game?: string;
  tipoPago?: string;
  lines: TicketLine[];
}

export interface ReceiptLine extends TicketLine {
  estado: 'GANADA' | 'PERDIDA' | 'PENDIENTE' | 'PAGADA';
  premioBs: number;
  premioUsd: number;
}

export interface ReceiptData {
  kind: 'recibo';
  ticketCode: string;
  date: string;
  time: string;
  lines: ReceiptLine[];
  premioTotalBs: number;
  premioTotalUsd: number;
}

export type PrintData = TicketData | ReceiptData;

/** Electron printer entry; extra display fields are allowed. */
export interface PrinterInfo {
  name: string;
  displayName?: string;
  description?: string;
  [key: string]: unknown;
}

export type GetPrintersAsync = () => Promise<PrinterInfo[]>;

export interface PrinterRef {
  name: string;
}

export interface PrintResult {
  success: boolean;
  message: string;
}

export type PrintErrorCode =
  | 'E_CONFIG_INVALID'
  | 'E_PRINTER_UNCONFIGURED'
  | 'E_PRINTER_NOT_FOUND'
  | 'E_DISCOVERY_TIMEOUT'
  | 'E_TRANSPORT_INVALID'
  | 'E_TRANSPORT_UNAVAILABLE'
  | 'E_TRANSPORT_TIMEOUT'
  | 'E_TRANSPORT_FAILED'
  | 'E_PRINT_BUSY'
  | 'E_PRINT_LOCK_INVALID'
  | 'E_PAYLOAD_INVALID'
  | 'E_NO_WEBCONTENTS';

export type PrintError = Error & {
  code: PrintErrorCode;
  details?: unknown;
};

/** Minimal Electron surface: keeps the module Electron-free for pure tests. */
export interface WebContentsLike {
  getPrintersAsync(): Promise<PrinterInfo[]>;
}

export interface DocumentRenderer<T> {
  render(data: T, config: Partial<EffectiveConfig>): Buffer;
}

export interface PrinterDiscovery {
  resolve(input: ResolvePrinterInput): Promise<PrinterRef>;
}

export interface Transport {
  sendRaw(printerName: string, bytes: Buffer): Promise<void>;
}

export interface PrintLock {
  run<T>(task: () => Promise<T>): Promise<T>;
  isBusy(): boolean;
}

export interface PrintRequest {
  kind: PrintKind;
  data: PrintData;
  webContents: WebContentsLike;
}

export interface BuildBufferInput {
  kind: PrintKind;
  data: PrintData;
  config: EffectiveConfig;
}

export type ReadFile = (path: string, encoding: 'utf8') => string;

export interface ResolveConfigInput {
  env?: Record<string, string | undefined>;
  userDataPath?: string;
  readFile?: ReadFile;
}

export interface ResolvePrinterInput {
  printerName?: string | null;
  getPrintersAsync?: GetPrintersAsync;
  timeoutMs?: number;
}

export interface PosPrinterLike {
  sendRawCommand(printerName: string, bytes: Buffer): Promise<void>;
}

export interface SendRawOptions {
  timeoutMs?: number;
  posPrinter?: PosPrinterLike | (() => PosPrinterLike);
}

export interface Printing {
  printDocument(request: PrintRequest): Promise<PrintResult>;
  maybeRunStartupTest(): Promise<PrintResult | null>;
  resolveEffectiveConfig(): EffectiveConfig;
  buildBuffer(input: BuildBufferInput): Buffer;
}

export interface CreatePrintingOptions {
  env?: Record<string, string | undefined>;
  userDataPath?: string;
  getWindow?: () => { webContents?: WebContentsLike } | null;
  sendRaw?: Transport['sendRaw'];
  lock?: PrintLock;
  readFile?: ReadFile;
}
