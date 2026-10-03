import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { saleKey, useReceiptPrinterStore } from '@/stores/receiptPrinter'
import { makeSale } from '@/test/register'
import type { PrinterSettings } from '@/types/api'

const receipt = vi.hoisted(() => ({ printSale: vi.fn(), printTest: vi.fn() }))
vi.mock('@/lib/receipt', () => receipt)

const PRINTER: PrinterSettings = { host: '192.168.1.50', paper_width: 80 }

type Result = { ok: true } | { ok: false; reason: string }

function deferred(): { promise: Promise<Result>; resolve: (v: Result) => void } {
  let resolve: (v: Result) => void = () => undefined
  const promise = new Promise<Result>((r) => { resolve = r })
  return { promise, resolve }
}

describe('receiptPrinter ストア', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    receipt.printSale.mockReset()
    receipt.printTest.mockReset()
  })

  it('端末に保存しただけの会計は client_uuid で見分ける', () => {
    expect(saleKey(makeSale())).toBe('sale:501')
    expect(saleKey(makeSale({ id: 0, client_uuid: 'abc' }))).toBe('offline:abc')
  })

  it('送信中に同じ会計をもう一度押しても送らない', async () => {
    const store = useReceiptPrinterStore()
    const d = deferred()
    receipt.printSale.mockReturnValue(d.promise)
    const first = store.print(PRINTER, makeSale(), 'receipt')
    await store.print(PRINTER, makeSale(), 'receipt')
    d.resolve({ ok: true })
    await first
    expect(receipt.printSale).toHaveBeenCalledTimes(1)
    expect(store.jobFor('sale:501')?.state).toBe('printed')
  })

  it('失敗は理由を持ち、閉じると消える（送信中は消さない）', async () => {
    const store = useReceiptPrinterStore()
    receipt.printSale.mockResolvedValue({ ok: false, reason: 'cover_open' })
    await store.print(PRINTER, makeSale(), 'receipt')
    expect(store.job).toEqual({ key: 'sale:501', state: 'failed', reason: 'cover_open' })
    store.dismiss()
    expect(store.job).toBeNull()

    receipt.printSale.mockReturnValue(deferred().promise)
    void store.print(PRINTER, makeSale(), 'receipt')
    store.dismiss()
    expect(store.job?.state).toBe('printing')
  })

  it('読み込みの失敗などの例外は unknown', async () => {
    const store = useReceiptPrinterStore()
    receipt.printSale.mockRejectedValue(new Error('chunk'))
    await store.print(PRINTER, makeSale(), 'receipt')
    expect(store.job?.reason).toBe('unknown')
  })

  it('送信中に別の会計の印刷が始まったら、古い結果で上書きしない', async () => {
    const store = useReceiptPrinterStore()
    const old = deferred()
    // 遅延読み込みの解決順に頼らず、会計ごとに応答を決める
    receipt.printSale.mockImplementation((_p: PrinterSettings, sale: { id: number }) => (sale.id === 501 ? old.promise : Promise.resolve({ ok: true })))
    const first = store.print(PRINTER, makeSale(), 'receipt')
    await store.print(PRINTER, makeSale({ id: 502 }), 'receipt')
    expect(store.job?.key).toBe('sale:502')
    old.resolve({ ok: false, reason: 'busy' })
    await first
    expect(store.job).toEqual({ key: 'sale:502', state: 'printed', reason: null })
  })

  it('テスト印刷は店舗名を渡す', async () => {
    const store = useReceiptPrinterStore()
    receipt.printTest.mockResolvedValue({ ok: true })
    await store.printTest(PRINTER, 'テスト店 A')
    expect(receipt.printTest).toHaveBeenCalledWith(PRINTER, 'テスト店 A')
    expect(store.jobFor('test')?.state).toBe('printed')
  })
})
