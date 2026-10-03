// 15 §7・§8 レシート印刷の状態。直近の 1 件だけを持つ（完了のポップアップを閉じても送信は続き、結果をレジ画面の帯に出すため）。
// 自動では送り直さない。同じ会計の送信中にもう一度押しても送らない（2 枚出るのを防ぐ）
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { PrintFailure } from '@/lib/receipt/sendToPrinter'
import type { PrinterSettings, Sale } from '@/types/api'

export type PrintState = 'printing' | 'printed' | 'failed'

export interface PrintJob {
  key: string
  state: PrintState
  reason: PrintFailure | null
}

/** 会計の見分け。端末に保存しただけの会計（id = 0）は client_uuid で見分ける */
export function saleKey(sale: Sale): string {
  return sale.id > 0 ? `sale:${sale.id}` : `offline:${sale.client_uuid}`
}

type ReceiptLib = typeof import('@/lib/receipt')
let lib: Promise<ReceiptLib> | null = null

/** 印刷の処理は初めて印刷するときに読み込む（初回 JS に含めない）。失敗したら次に押したとき読み直す */
function loadLib(): Promise<ReceiptLib> {
  lib ??= import('@/lib/receipt').catch((err: unknown) => {
    lib = null
    throw err
  })
  return lib
}

export const useReceiptPrinterStore = defineStore('receiptPrinter', () => {
  const job = ref<PrintJob | null>(null)
  // ref に入れた object は reactive の proxy になり === で比べられないため、送信ごとの番号で古い結果を見分ける
  let seq = 0

  function jobFor(key: string): PrintJob | null {
    return job.value?.key === key ? job.value : null
  }

  async function run(key: string, send: () => Promise<{ ok: true } | { ok: false; reason: PrintFailure }>): Promise<void> {
    if (job.value?.key === key && job.value.state === 'printing') return
    const mine = ++seq
    job.value = { key, state: 'printing', reason: null }
    let next: PrintJob
    try {
      const result = await send()
      next = result.ok ? { key, state: 'printed', reason: null } : { key, state: 'failed', reason: result.reason }
    } catch {
      // 遅延読み込みの失敗など
      next = { key, state: 'failed', reason: 'unknown' }
    }
    // 送信中に別の会計の印刷が始まっていたら、古い結果で上書きしない
    if (seq === mine) job.value = next
  }

  function print(printer: PrinterSettings, sale: Sale, kind: 'receipt' | 'reprint'): Promise<void> {
    return run(saleKey(sale), async () => (await loadLib()).printSale(printer, sale, kind))
  }

  function printTest(printer: PrinterSettings, storeName: string): Promise<void> {
    return run('test', async () => (await loadLib()).printTest(printer, storeName))
  }

  /** 帯を閉じる */
  function dismiss(): void {
    if (job.value?.state !== 'printing') job.value = null
  }

  return { job, jobFor, print, printTest, dismiss }
})
