// 15 §3.6 印刷の入口。初回の JS を増やさないよう、画面からは import('@/lib/receipt') で遅延読み込みする
import { buildReceipt, buildTestPage } from '@/lib/receipt/buildReceipt'
import { rasterRequest } from '@/lib/receipt/raster'
import { sendToPrinter, type PrintResult } from '@/lib/receipt/sendToPrinter'
import type { PrinterSettings, Sale } from '@/types/api'

export type { PrintFailure, PrintResult } from '@/lib/receipt/sendToPrinter'

// 漢字を確実に出すため、レシートは画像にして送る（§3.5。mC-Print2 の実機で文字の要素は化けた）
export function printSale(printer: PrinterSettings, sale: Sale, kind: 'receipt' | 'reprint'): Promise<PrintResult> {
  const doc = buildReceipt({ sale, paperWidth: printer.paper_width, kind })
  return sendToPrinter(printer.host, rasterRequest(doc, printer.paper_width))
}

export function printTest(printer: PrinterSettings, storeName: string): Promise<PrintResult> {
  const doc = buildTestPage(storeName, printer.paper_width, new Date())
  return sendToPrinter(printer.host, rasterRequest(doc, printer.paper_width))
}
