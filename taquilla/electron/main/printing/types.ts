// Contracts for the native ESC/POS printing module.

export type PrintKind = 'ticket' | 'recibo' | 'cierre' | 'reporte';
export type Paper = '58mm' | '80mm' | '80mm-512';
export type CodePage = 'cp437' | 'cp850' | 'cp858' | 'cp1252' | 'ascii';
export type Cut = 'partial' | 'full' | 'none';

/** Fully resolved config: printer-config.json > defaults. */
export interface EffectiveConfig {
  printerName: string | null;
  paper: Paper;
  columns: number;
  dots: number;
  codePage: CodePage;
  cut: Cut;
  feedLines: number;
  cutPerBlock: boolean;
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
  options?: Record<string, string>;
  [key: string]: unknown;
}

export interface PrinterListResult {
  printers: PrinterInfo[];
  suggested: string | null;
}

export interface ListPrintersInput {
  getPrintersAsync?: GetPrintersAsync;
  timeoutMs?: number;
}

export type GetPrintersAsync = () => Promise<PrinterInfo[]>;

export interface PrinterRef {
  name: string;
}

export interface PrintResult {
  success: boolean;
  message: string;
  code?: PrintErrorCode;
}

/** IPC response for print:list-printers. */
export interface PrinterListResponse {
  success: boolean;
  message: string;
  printers: PrinterInfo[];
  suggested: string | null;
  code?: PrintErrorCode;
}

/** IPC response for print:get-config / print:set-config. */
export interface PrinterConfigResponse {
  success: boolean;
  message: string;
  config?: EffectiveConfig;
  code?: PrintErrorCode;
}

export type PrintErrorCode =
  | 'E_CONFIG_INVALID'
  | 'E_CONFIG_WRITE_FAILED'
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
  | 'E_PAYLOAD_TOO_LARGE'
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
export type WriteFile = (path: string, data: string, encoding: 'utf8') => void;
export type RenameFile = (from: string, to: string) => void;

export interface SavePrinterConfigInput {
  printerName: string | null;
  paper?: Paper;
  columns?: number | null; // null removes an explicit override
  codePage?: CodePage;
  cut?: Cut;
  feedLines?: number;
  cutPerBlock?: boolean;
}

/** Optional overrides for the commissioning slip (print:test). */
export interface TestPrintInput {
  printerName?: string | null;
  paper?: Paper;
}

export type PrinterConfigFile = {
  version?: unknown;
  printerName?: unknown;
  paper?: unknown;
  columns?: unknown;
  codePage?: unknown;
  cut?: unknown;
  feedLines?: unknown;
  cutPerBlock?: unknown;
  [key: string]: unknown;
};

export interface SavedPrinterConfig {
  version: 1;
  printerName: string | null;
  paper: Paper;
  columns?: number;
  codePage: CodePage;
  cut: Cut;
  feedLines: number;
  cutPerBlock: boolean;
}

export interface ResolveConfigInput {
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
  resolveEffectiveConfig(): EffectiveConfig;
  buildBuffer(input: BuildBufferInput): Buffer;
  listPrinters(webContents: WebContentsLike): Promise<PrinterListResponse>;
  getConfig(): PrinterConfigResponse;
  /** Async because the renderer-supplied name is re-enumerated in main before persisting. */
  saveConfig(webContents: WebContentsLike, patch: SavePrinterConfigInput): Promise<PrinterConfigResponse>;
  testPrint(webContents: WebContentsLike, input?: TestPrintInput): Promise<PrintResult>;
}

export interface CreatePrintingOptions {
  userDataPath?: string;
  sendRaw?: Transport['sendRaw'];
  lock?: PrintLock;
  readFile?: ReadFile;
  writeFile?: WriteFile;
  renameFile?: RenameFile;
}
