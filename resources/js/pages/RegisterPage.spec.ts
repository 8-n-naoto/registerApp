import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { SaleInput } from '@/api/register'
import RegisterPage from '@/pages/RegisterPage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import { stubMatchMedia } from '@/test/matchMedia'
import { makeBootstrap, makeProduct, makeSale } from '@/test/register'

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  createSale: vi.fn(),
  fetchSale: vi.fn(),
  cancelSale: vi.fn(),
}))
vi.mock('@/api/register', () => api)

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
  const w = mount(RegisterPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function tile(id: number): HTMLButtonElement {
  const el = document.querySelector<HTMLButtonElement>(`[data-product="${id}"]`)
  if (!el) throw new Error(`product ${id} not found`)
  return el
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const el = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

const lines = (): string[][] =>
  [...document.querySelectorAll('.order .line')].map((li) => [
    li.querySelector('.line__name span')?.textContent ?? '',
    li.querySelector('.line__count')?.textContent ?? '',
  ])

async function click(el: HTMLElement): Promise<void> {
  el.click()
  await flushPromises()
}

describe('S02 会計（08 §5.3）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    localStorage.clear()
    stubMatchMedia(true)
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchBootstrap.mockImplementation(() => Promise.resolve(makeBootstrap()))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('AC-S02-1・2：商品を 3 回タップで 1 行・数量 3、合計に反映。通信は bootstrap の 1 回だけ', async () => {
    await mountPage()
    for (let i = 0; i < 3; i++) await click(tile(1))
    expect(lines()).toEqual([['コーヒー', '3']])
    expect(document.querySelector('.sum--total')?.textContent).toContain('¥1,200')
    expect(document.querySelector('.sum--sub')?.textContent).toContain('（内消費税 ¥109）')
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(1)
    expect(api.createSale).not.toHaveBeenCalled()
  })

  it('オプションのある商品は選んでから追加し、オプション違いは別の行', async () => {
    await mountPage()
    await click(tile(3))
    const option = document.querySelector<HTMLButtonElement>('.option')
    if (!option) throw new Error('option')
    await click(option)
    await click(button('追加'))
    await click(tile(3))
    await click(button('追加'))
    await click(tile(3))
    await click(button('追加'))
    expect(lines()).toEqual([['ラテ', '1'], ['ラテ', '2']])
    expect(document.querySelector('.line__options')?.textContent).toBe('ショット')
    expect(document.querySelector('.sum--total')?.textContent).toContain('¥1,550')
  })

  it('AC-S02-4：在庫 2 の商品は 2 個で商品ボタンと［＋］が押せなくなる', async () => {
    await mountPage()
    await click(tile(2))
    expect(tile(2).disabled).toBe(false)
    await click(tile(2))
    expect(tile(2).disabled).toBe(true)
    expect(document.querySelector<HTMLButtonElement>('[aria-label="ケーキ を 1 つ増やす"]')?.disabled).toBe(true)
    expect(tile(1).disabled).toBe(false)
  })

  it('AC-S02-5・6：現金 ¥1,000・合計 ¥780 でお釣り ¥220、［確定］の連打でも 1 件', async () => {
    await mountPage()
    await click(tile(1))
    await click(tile(2))
    await click(button('お会計へ'))

    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    expect(button('確定', dialog).disabled).toBe(true)
    expect(dialog.textContent).toContain('あと ¥780')
    await click(button('1,000', dialog))
    expect(dialog.querySelector('.checkout__change')?.textContent).toContain('¥220')

    let resolve: (v: unknown) => void = () => {}
    api.createSale.mockReturnValue(new Promise((r) => { resolve = r }))
    button('確定', dialog).click()
    button('確定', dialog).click()
    await flushPromises()
    expect(button('確定', dialog).disabled).toBe(true)
    resolve(makeSale())
    await flushPromises()

    expect(api.createSale).toHaveBeenCalledTimes(1)
    expect((api.createSale.mock.calls[0]?.[0] as SaleInput)).toMatchObject({ received: 1000, expected_total: 780 })
    expect(document.querySelector('.checkout')).toBeNull()
    expect(document.querySelector('.done')?.textContent).toContain('¥220')
    expect(lines()).toEqual([])
  })

  it('数字キーで預かり金を入れ、⌫ と クリアが効く', async () => {
    await mountPage()
    await click(tile(1))
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    await click(button('5', dialog))
    await click(button('00', dialog))
    await click(button('0', dialog))
    expect(dialog.querySelector('.checkout__received-value')?.textContent).toBe('¥5,000')
    await click(button('⌫', dialog))
    expect(dialog.querySelector('.checkout__received-value')?.textContent).toBe('¥500')
    expect(dialog.querySelector('.checkout__change')?.textContent).toContain('¥100')
    await click(button('クリア', dialog))
    expect(dialog.querySelector('.checkout__received-value')?.textContent).toBe('¥—')
  })

  it('AC-S02-7：通信断ではダイアログと入力が残り、エラーを出す', async () => {
    const { AxiosError } = await import('axios')
    await mountPage()
    await click(tile(1))
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    await click(button('ちょうど', dialog))
    api.createSale.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    await click(button('確定', dialog))

    expect(document.querySelector('.checkout')).not.toBeNull()
    expect(dialog.querySelector('.checkout__received-value')?.textContent).toBe('¥400')
    expect(dialog.querySelector('.checkout__error')?.textContent).toContain('通信できません')
    expect(lines()).toEqual([['コーヒー', '1']])
  })

  it('AC-S02-10：確定後 5 秒だけ［取り消す］を出す', async () => {
    vi.useFakeTimers()
    await mountPage()
    await click(tile(1))
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    await click(button('ちょうど', dialog))
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))

    expect(button('取り消す（5）')).toBeTruthy()
    vi.advanceTimersByTime(2000)
    await flushPromises()
    api.cancelSale.mockResolvedValue(makeSale({ status: 'cancelled' }))
    await click(button('取り消す（3）'))
    expect(api.cancelSale).toHaveBeenCalledWith(501)
    expect(document.querySelector('.done')).toBeNull()
    expect(document.querySelector('.register__notice--info')?.textContent).toContain('会計を取り消しました')
  })

  it('5 秒経つと［取り消す］が消え、［次の会計］で閉じる', async () => {
    vi.useFakeTimers()
    await mountPage()
    await click(tile(1))
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    await click(button('ちょうど', dialog))
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))

    vi.advanceTimersByTime(5000)
    await flushPromises()
    expect(document.querySelector('.done')?.textContent).not.toContain('取り消す')
    await click(button('次の会計'))
    expect(document.querySelector('.done')).toBeNull()
  })

  it('［領収書を表示］で S03 へ', async () => {
    const { router } = await mountPage()
    await click(tile(1))
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    await click(button('ちょうど', dialog))
    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))
    await click(button('領収書を表示'))
    expect(router.currentRoute.value.fullPath).toBe('/sales/501/receipt')
  })

  it('AC-S02-8：在庫不足は注文画面に不足の内容を出してダイアログを閉じる', async () => {
    const { apiError } = await import('@/test/helpers')
    await mountPage()
    await click(tile(2))
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    await click(button('ちょうど', dialog))
    api.createSale.mockRejectedValue(apiError(409, {
      message: '在庫が足りません', code: 'OUT_OF_STOCK',
      details: { shortages: [{ product_id: 2, product_name: 'ケーキ', stock_qty: 0, requested: 1 }] },
    }))
    await click(button('確定', dialog))
    expect(document.querySelector('.checkout')).toBeNull()
    expect(document.querySelector('.register__notice--error')?.textContent).toContain('在庫が足りません：ケーキ（残り 0）')
  })

  it('保留は注文を空にし、一覧から呼び出すと戻る（今の注文があれば確認）', async () => {
    await mountPage()
    await click(tile(1))
    await click(button('保留'))
    expect(lines()).toEqual([])
    await click(tile(2))
    await click(button('保留一覧 (1)'))
    await click(button('呼び出す'))
    expect(document.body.textContent).toContain('今の注文を消して、保留を呼び出しますか')
    const confirm = [...document.querySelectorAll<HTMLButtonElement>('.dialog button')].find((b) => b.textContent?.trim() === '呼び出す')
    if (!confirm) throw new Error('confirm')
    await click(confirm)
    expect(lines()).toEqual([['コーヒー', '1']])
  })

  it('AC-S02-14：スマホは合計バーを下に出し、注文はシートで開く', async () => {
    stubMatchMedia(false)
    await mountPage()
    await click(tile(1))
    expect(document.querySelector('.register__order')).toBeNull()
    expect(document.querySelector('.total-bar')?.textContent).toContain('¥400')
    await click(button('合計・1 点¥400注文を見る'))
    expect(lines()).toEqual([['コーヒー', '1']])
  })

  it('読み込みに失敗したら再読み込みを出す', async () => {
    const { apiError } = await import('@/test/helpers')
    api.fetchBootstrap.mockRejectedValueOnce(apiError(500, { message: 'エラー' }))
    await mountPage()
    expect(document.querySelector('.register__message')?.textContent).toContain('エラー')
    await click(button('再読み込み'))
    expect(document.querySelector('[data-product="1"]')).not.toBeNull()
  })
})

describe('S02 商品 500 件（WP 6-2・08 §10）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    localStorage.clear()
    stubMatchMedia(true)
    for (const fn of Object.values(api)) fn.mockReset()
  })

  it('500 件を並べ、商品タップ → 合計の反映が遅くならない（jsdom の中央値で後退を検出）', async () => {
    const products = Array.from({ length: 500 }, (_, i) => makeProduct(i + 1, `商品 ${i + 1}`, { price: 100 + (i % 10) * 10 }))
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({ categories: [], products }))
    await mountPage()
    expect(document.querySelectorAll('[data-product]')).toHaveLength(500)

    const taps: number[] = []
    for (let i = 0; i < 20; i++) {
      const started = performance.now()
      tile(500 - i).click()
      await nextTick()
      taps.push(performance.now() - started)
    }
    expect(lines()).toHaveLength(20)
    taps.sort((a, b) => a - b)
    const median = taps[10] ?? 0
    // 目標（100ms）は実機で判定する。jsdom は実ブラウザより DOM の処理が遅く、並列実行でも揺れるため、
    // ここでは大きな後退だけを検出する（2026-09-29 の jsdom 実測：単独実行で中央値約 40ms。v-memo を付ける前は約 69ms）
    expect(median).toBeLessThan(250)
  })
})
