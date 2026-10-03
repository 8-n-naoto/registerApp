import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import RegisterPage from '@/pages/RegisterPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { stubMatchMedia } from '@/test/matchMedia'
import { makeBootstrap, makeSale } from '@/test/register'
import type { PrinterSettings } from '@/types/api'

// 15 §8.1・§8.2 会計の確認の［レシート］切替と、完了のポップアップの印刷（T11〜T13）

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  createSale: vi.fn(),
  fetchSale: vi.fn(),
  cancelSale: vi.fn(),
  createOfflineSale: vi.fn(),
}))
vi.mock('@/api/register', () => api)
const ordersApi = vi.hoisted(() => ({ fetchOrders: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)
const receipt = vi.hoisted(() => ({ printSale: vi.fn(), printTest: vi.fn() }))
vi.mock('@/lib/receipt', () => receipt)

const PRINTER: PrinterSettings = { host: '192.168.1.50', paper_width: 80 }

function withPrinter(printer: PrinterSettings | null = PRINTER) {
  const base = makeBootstrap()
  return makeBootstrap({ store: { ...base.store, printer } })
}

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('staff')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/register', name: 'register', component: RegisterPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
      { path: '/sales/:id/receipt', name: 'receipt', component: { template: '<p>receipt</p>' } },
    ],
  })
  await router.push('/register')
  mount(RegisterPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const el = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

async function click(el: HTMLElement): Promise<void> {
  el.click()
  await flushPromises()
}

/** コーヒーを 1 つ入れてお会計を開き、ちょうどを押す */
async function openCheckout(): Promise<HTMLElement> {
  const tile = document.querySelector<HTMLButtonElement>('[data-product="1"]')
  if (!tile) throw new Error('tile')
  await click(tile)
  await click(button('お会計へ'))
  const dialog = document.querySelector<HTMLElement>('.checkout')
  if (!dialog) throw new Error('dialog')
  await click(button('ちょうど', dialog))
  return dialog
}

const printState = (root: ParentNode = document): string | undefined =>
  root.querySelector<HTMLElement>('[data-testid="receipt-print"]')?.dataset.state

describe('15 レシート印刷（S02）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    localStorage.clear()
    stubMatchMedia(true)
    for (const fn of [...Object.values(api), ...Object.values(receipt)]) fn.mockReset()
    api.fetchBootstrap.mockResolvedValue(withPrinter())
    ordersApi.fetchOrders.mockReset()
    ordersApi.fetchOrders.mockResolvedValue([])
  })

  it('T11：プリンターが無い店舗は切替も［レシートを印刷］も出さない', async () => {
    api.fetchBootstrap.mockResolvedValue(withPrinter(null))
    await mountPage()
    const dialog = await openCheckout()
    expect(dialog.querySelector('[data-testid="checkout-print"]')).toBeNull()
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))
    expect(document.querySelector('.done')).not.toBeNull()
    expect(printState()).toBeUndefined()
    expect(receipt.printSale).not.toHaveBeenCalled()
  })

  it('T11：切替は開くたびに「印刷しない」に戻り、印刷しない会計は小さく［レシートを印刷］', async () => {
    await mountPage()
    let dialog = await openCheckout()
    const toggle = dialog.querySelector<HTMLElement>('[data-testid="checkout-print"]')
    if (!toggle) throw new Error('toggle')
    expect(button('印刷しない', toggle).getAttribute('aria-checked')).toBe('true')
    await click(button('印刷する', toggle))
    expect(button('印刷する', toggle).getAttribute('aria-checked')).toBe('true')
    await click(button('戻る', dialog))
    await click(button('お会計へ'))
    dialog = document.querySelector<HTMLElement>('.checkout') ?? dialog
    expect(button('印刷しない', dialog).getAttribute('aria-checked')).toBe('true')

    await click(button('ちょうど', dialog))
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))
    expect(receipt.printSale).not.toHaveBeenCalled()
    expect(printState()).toBe('idle')
    // 頼まれ忘れの救済：押すと再発行ではなく通常の領収書として送る
    receipt.printSale.mockResolvedValue({ ok: true })
    await click(button('レシートを印刷'))
    expect(receipt.printSale).toHaveBeenCalledWith(PRINTER, expect.objectContaining({ id: 501 }), 'receipt')
    expect(printState()).toBe('printed')
    expect(document.querySelector('.done')?.textContent).toContain('レシートを印刷しました')
  })

  it('T12：会計の失敗（409）では印刷を送らない', async () => {
    await mountPage()
    const dialog = await openCheckout()
    await click(button('印刷する', dialog))
    api.createSale.mockRejectedValue(apiError(409, { message: '合計が変わりました', code: 'TOTAL_MISMATCH' }))
    await click(button('確定', dialog))
    expect(document.querySelector('.done')).toBeNull()
    expect(receipt.printSale).not.toHaveBeenCalled()
  })

  it('T13：「印刷する」は確定後に送り、送信中も［次の会計］を押せる。失敗は上の帯に残り［もう一度印刷］', async () => {
    await mountPage()
    const dialog = await openCheckout()
    await click(button('印刷する', dialog))
    let resolve: (v: unknown) => void = () => undefined
    receipt.printSale.mockReturnValue(new Promise((r) => { resolve = r }))
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))

    expect(receipt.printSale).toHaveBeenCalledTimes(1)
    expect(receipt.printSale).toHaveBeenCalledWith(PRINTER, expect.objectContaining({ id: 501 }), 'receipt')
    const done = document.querySelector<HTMLElement>('.done')
    expect(printState(done ?? document)).toBe('printing')
    expect(done?.textContent).toContain('レシートを印刷しています')
    // 押したボタンだけ回転：［次の会計］は押せる
    expect(button('次の会計').disabled).toBe(false)
    await click(button('次の会計'))
    expect(document.querySelector('.done')).toBeNull()
    expect(document.querySelector('[data-testid="print-banner"]')?.textContent).toContain('レシートを印刷しています')

    resolve({ ok: false, reason: 'paper_empty' })
    await flushPromises()
    const banner = document.querySelector<HTMLElement>('[data-testid="print-banner"]')
    expect(banner?.textContent).toContain('紙がありません')
    // 自動では送り直さない
    expect(receipt.printSale).toHaveBeenCalledTimes(1)
    receipt.printSale.mockResolvedValue({ ok: true })
    await click(button('もう一度印刷', banner ?? document))
    expect(receipt.printSale).toHaveBeenCalledTimes(2)
    expect(document.querySelector('[data-testid="print-banner"]')).toBeNull()
  })

  it('印刷した会計を取り消すと、レシートの回収のお願いを出す', async () => {
    await mountPage()
    const dialog = await openCheckout()
    await click(button('印刷する', dialog))
    receipt.printSale.mockResolvedValue({ ok: true })
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))
    api.cancelSale.mockResolvedValue(makeSale({ status: 'cancelled' }))
    const undo = [...document.querySelectorAll<HTMLButtonElement>('.done button')].find((b) => b.textContent?.includes('取り消す'))
    if (!undo) throw new Error('undo')
    await click(undo)
    expect(document.querySelector('[data-testid="done-collect"]')?.textContent).toContain('印刷したレシートを回収してください')
  })
})
