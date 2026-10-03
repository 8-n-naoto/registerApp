// 15 §3.6 印刷の入口。初回の JS を増やさないよう、画面からは import('@/lib/receipt') で遅延読み込みする
import { buildReceipt, buildTestPage } from '@/lib/receipt/buildReceipt'
import { sendToPrinter, type PrintResult } from '@/lib/receipt/sendToPrinter'
import type { PrinterSettings, Sale } from '@/types/api'

export type { PrintFailure, PrintResult } from '@/lib/receipt/sendToPrinter'

export function printSale(printer: PrinterSettings, sale: Sale, kind: 'receipt' | 'reprint'): Promise<PrintResult> {
  return sendToPrinter(printer.host, buildReceipt({ sale, paperWidth: printer.paper_width, kind }))
}

export function printTest(printer: PrinterSettings, storeName: string): Promise<PrintResult> {
  return sendToPrinter(printer.host, buildTestPage(storeName, printer.paper_width, new Date()))
}
