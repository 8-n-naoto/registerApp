import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { SaleInput } from '@/api/register'
import RegisterPage from '@/pages/RegisterPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { stubMatchMedia } from '@/test/matchMedia'
import { makeOrder, makeOrderItem } from '@/test/orders'
import { makeBootstrap, makeProduct, makeSale } from '@/test/register'

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  createSale: vi.fn(),
  fetchSale: vi.fn(),
  cancelSale: vi.fn(),
}))
vi.mock('@/api/register', () => api)
const ordersApi = vi.hoisted(() => ({ fetchOrders: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)

async function mountPage(path = '/register') {
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
  await router.push(path)
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
    ordersApi.fetchOrders.mockReset()
    ordersApi.fetchOrders.mockResolvedValue([])
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
    const option = document.querySelector<HTMLButtonElement>('.opt')
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

  it('AC-S02-18：在庫管理 OFF の店舗では売切・残数を出さず、在庫 0 の商品も足せる', async () => {
    const base = makeBootstrap()
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({
      store: { ...base.store, stock_enabled: false },
      products: [makeProduct(2, 'ケーキ', { price: 380, track_stock: true, stock_qty: 0 })],
    }))
    await mountPage()
    expect(tile(2).textContent).not.toContain('売切')
    expect(tile(2).textContent).not.toContain('残')
    for (let i = 0; i < 3; i++) await click(tile(2))
    expect(lines()).toEqual([['ケーキ', '3']])
    expect(tile(2).disabled).toBe(false)
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

  it('ホームの［レジ］（?view=order）：スマホは注文一覧のシートを開いた状態で開き、URL から外す', async () => {
    stubMatchMedia(false)
    const { router } = await mountPage('/register?view=order')
    expect(document.querySelector('.order--sheet')).not.toBeNull()
    expect(document.querySelector('.order--sheet')?.textContent).toContain('お会計へ')
    expect(router.currentRoute.value.fullPath).toBe('/register')
  })

  it('ホームの［レジ］（?view=order）：タブレットは注文一覧が右に出ているのでシートを開かない', async () => {
    const { router } = await mountPage('/register?view=order')
    expect(document.querySelector('.register__order')).not.toBeNull()
    expect(document.querySelector('.order--sheet')).toBeNull()
    expect(router.currentRoute.value.fullPath).toBe('/register')
  })

  it('お会計ダイアログに明細と内訳を出し、支払方法をダイアログで選ぶ', async () => {
    await mountPage()
    expect(document.querySelector('.order [role="radiogroup"]')).toBeNull()
    await click(tile(1))
    await click(tile(1))
    await click(tile(2))
    await click(button('お会計へ'))

    const dialog = document.querySelector<HTMLElement>('.checkout')
    if (!dialog) throw new Error('dialog')
    const detail = [...dialog.querySelectorAll('.cdetail__line')].map((li) => li.textContent?.replace(/\s+/g, ''))
    expect(detail).toEqual(['コーヒー×2¥800', 'ケーキ×1¥380'])
    expect(dialog.querySelector('.cdetail__sums')?.textContent).toContain('小計')
    expect(dialog.querySelector('.cdetail__sums')?.textContent).toContain('3 点')

    const cash = button('現金', dialog)
    expect(cash.getAttribute('aria-checked')).toBe('true')
    expect(dialog.querySelector('.checkout__pad')).not.toBeNull()
    await click(button('カード', dialog))
    expect(button('カード', dialog).getAttribute('aria-checked')).toBe('true')
    expect(dialog.querySelector('.checkout__pad')).toBeNull()
    expect(button('確定', dialog).disabled).toBe(false)

    api.createSale.mockResolvedValue(makeSale())
    await click(button('確定', dialog))
    expect((api.createSale.mock.calls[0]?.[0] as SaleInput)).toMatchObject({ payment_method_id: 2, received: null, expected_total: 1180 })
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
    ordersApi.fetchOrders.mockReset()
    ordersApi.fetchOrders.mockResolvedValue([])
  })

  it('商品ボタンはメモを小さく出し、商品コードは出さない', async () => {
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({
      categories: [],
      products: [makeProduct(1, 'コーヒー', { memo: 'ホット', code: 'HOT-1' }), makeProduct(2, 'コーヒー', { memo: 'アイス' })],
    }))
    await mountPage()
    expect(tile(1).textContent).toContain('ホット')
    expect(tile(2).textContent).toContain('アイス')
    expect(document.querySelector('.tile__code')).toBeNull()
    expect(document.body.textContent).not.toContain('HOT-1')
  })

  it('注文の明細も商品名の下にメモを出し、同じ名前の商品を見分けられる', async () => {
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({
      categories: [],
      products: [makeProduct(1, 'コーヒー', { memo: 'ホット' }), makeProduct(2, 'コーヒー', { memo: 'アイス' })],
    }))
    await mountPage()
    await click(tile(1))
    await click(tile(2))
    expect([...document.querySelectorAll('.line__memo')].map((e) => e.textContent)).toEqual(['ホット', 'アイス'])
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

describe('S02 注文から会計（12 §8.6）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    localStorage.clear()
    stubMatchMedia(true)
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchBootstrap.mockImplementation(() => Promise.resolve(makeBootstrap()))
    ordersApi.fetchOrders.mockReset()
  })

  const O1 = makeOrder({ id: 101, order_no: 1, subtotal: 800, items: [makeOrderItem({ product_id: 1, quantity: 2, line_total: 800 })] })
  const O2 = makeOrder({ id: 102, order_no: 2, subtotal: 380, items: [makeOrderItem({ id: 1002, product_id: 2, product_name: 'ケーキ', quantity: 1, line_total: 380 })] })
  const O3 = makeOrder({ id: 103, order_no: 3, order_table_id: 2, table_name: 'T2', subtotal: 400 })
  const O4 = makeOrder({ id: 104, order_no: 4, order_table_id: null, table_name: null, label: '田中さま', subtotal: 400 })

  async function checkoutByCard(): Promise<void> {
    await click(button('お会計へ'))
    const dialog = document.querySelector<HTMLElement>('.checkout') ?? document
    await click(button('カード', dialog))
    await click(button('確定', dialog))
  }

  function checkbox(selector: string): HTMLButtonElement {
    const el = document.querySelector<HTMLButtonElement>(selector)
    if (!el) throw new Error(`${selector} not found`)
    return el
  }

  it('件数を出し、テーブルを選ぶとその注文をすべて選ぶ。注文ごとに外せる', async () => {
    ordersApi.fetchOrders.mockResolvedValue([O1, O2, O3, O4])
    await mountPage()
    expect(ordersApi.fetchOrders).toHaveBeenCalledWith('unpaid')
    const from = checkbox('[data-from-orders]')
    expect(from.textContent?.trim()).toBe('注文から会計（4）')
    await click(from)
    expect(ordersApi.fetchOrders).toHaveBeenCalledTimes(2)
    expect(document.querySelector('[data-group="t1"]')?.textContent).toContain('T1（2 件・¥1,180）')
    expect(document.querySelector('[data-group="o104"]')?.textContent).toContain('田中さま')

    await click(checkbox('[data-group="t1"] [role="checkbox"]'))
    expect(checkbox('[data-order="101"]').getAttribute('aria-checked')).toBe('true')
    expect(checkbox('[data-order="102"]').getAttribute('aria-checked')).toBe('true')
    expect(checkbox('[data-order="103"]').getAttribute('aria-checked')).toBe('false')
    await click(checkbox('[data-order="102"]'))
    expect(checkbox('[data-group="t1"] [role="checkbox"]').getAttribute('aria-checked')).toBe('mixed')
    await click(checkbox('[data-order="102"]'))

    await click(checkbox('[data-pick-submit]'))
    expect(lines()).toEqual([['コーヒー', '2'], ['ケーキ', '1']])
    expect(document.querySelector('[data-linked-orders]')?.textContent).toContain('会計する注文：#1（T1）、#2（T1）')
    expect(document.querySelector('[role="dialog"]')).toBeNull()

    api.createSale.mockResolvedValue(makeSale())
    await checkoutByCard()
    expect((api.createSale.mock.calls[0]?.[0] as SaleInput).order_ids).toEqual([101, 102])
  })

  it('カートが空でなければ「今の注文に追加しますか？」。［やめる］では足さない', async () => {
    ordersApi.fetchOrders.mockResolvedValue([O1])
    await mountPage()
    await click(tile(1))
    await click(checkbox('[data-from-orders]'))
    await click(checkbox('[data-order="101"]'))
    await click(checkbox('[data-pick-submit]'))
    const confirm = document.querySelector('[role="alertdialog"]')
    expect(confirm?.textContent).toContain('今の注文に追加しますか？')
    await click(button('やめる', confirm ?? document))
    expect(lines()).toEqual([['コーヒー', '1']])
    await click(checkbox('[data-pick-submit]'))
    await click(button('追加する', document.querySelector('[role="alertdialog"]') ?? document))
    expect(lines()).toEqual([['コーヒー', '3']])
  })

  it('入っている注文は選べない', async () => {
    ordersApi.fetchOrders.mockResolvedValue([O1, O3])
    await mountPage()
    await click(checkbox('[data-from-orders]'))
    await click(checkbox('[data-order="101"]'))
    await click(checkbox('[data-pick-submit]'))
    await click(checkbox('[data-from-orders]'))
    expect(checkbox('[data-order="101"]').disabled).toBe(true)
    expect(checkbox('[data-order="101"]').textContent).toContain('カートに入っています')
    expect(checkbox('[data-group="t1"] [role="checkbox"]').disabled).toBe(true)
  })

  it('?table= で開くと、そのテーブルの注文を選んだ状態でダイアログを出し、URL から外す', async () => {
    ordersApi.fetchOrders.mockResolvedValue([O1, O2, O3])
    const { router } = await mountPage('/register?table=1')
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    expect(checkbox('[data-order="101"]').getAttribute('aria-checked')).toBe('true')
    expect(checkbox('[data-order="103"]').getAttribute('aria-checked')).toBe('false')
    expect(router.currentRoute.value.query).toEqual({})
    expect(checkbox('[data-pick-submit]').textContent?.trim()).toBe('カートに入れる（2 件）')
  })

  it('?order= で開くと、その注文だけを選んだ状態でダイアログを出し、URL から外す（S13［送信して会計へ］）', async () => {
    ordersApi.fetchOrders.mockResolvedValue([O1, O2, O4])
    const { router } = await mountPage('/register?order=104')
    expect(document.querySelector('[role="dialog"]')).not.toBeNull()
    expect(checkbox('[data-order="104"]').getAttribute('aria-checked')).toBe('true')
    expect(checkbox('[data-order="101"]').getAttribute('aria-checked')).toBe('false')
    expect(router.currentRoute.value.query).toEqual({})
    await click(checkbox('[data-pick-submit]'))
    expect(document.querySelector('[data-linked-orders]')?.textContent).toContain('#4')
  })

  it('件数が取れなければ件数なしで出す', async () => {
    ordersApi.fetchOrders.mockRejectedValue(apiError(500, { message: 'x' }))
    await mountPage()
    expect(checkbox('[data-from-orders]').textContent?.trim()).toBe('注文から会計')
    await click(checkbox('[data-from-orders]'))
    expect(document.querySelector('[role="dialog"] [role="alert"]')?.textContent).toContain('注文を読み込めませんでした')
  })

  it('AC-S02-17：会計済みの注文は確認して外せる', async () => {
    ordersApi.fetchOrders.mockResolvedValue([O1, O3])
    await mountPage()
    await click(checkbox('[data-from-orders]'))
    await click(checkbox('[data-order="101"]'))
    await click(checkbox('[data-order="103"]'))
    await click(checkbox('[data-pick-submit]'))
    api.createSale.mockRejectedValue(apiError(409, { message: '会計済みの注文が含まれています。画面を更新してください', code: 'ORDER_ALREADY_PAID', details: { order_ids: [101] } }))
    await checkoutByCard()
    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog?.textContent).toContain('この注文はすでに会計されています（#1）')
    await click(button('外す', dialog ?? document))
    expect(lines()).toEqual([['コーヒー', '1']])
    expect(document.querySelector('[data-linked-orders]')?.textContent).toContain('#3（T2）')
    expect(document.querySelector('[data-linked-orders]')?.textContent).not.toContain('#1')
  })
})
