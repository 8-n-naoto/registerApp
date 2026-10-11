import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ja } from '@/i18n/ja'
import OrdersPage from '@/pages/OrdersPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { makeOrder, makeOrderItem, makeTable } from '@/test/orders'

const tablesApi = vi.hoisted(() => ({ fetchOrderTables: vi.fn(), openOrderTable: vi.fn(), closeOrderTable: vi.fn() }))
vi.mock('@/api/orderTables', () => tablesApi)
const ordersApi = vi.hoisted(() => ({ fetchOrders: vi.fn(), acceptOrder: vi.fn(), cancelOrder: vi.fn(), cancelOrderItem: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)

async function mountPage(path = '/orders') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('staff')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/orders', name: 'orders', component: OrdersPage },
      { path: '/orders/new', name: 'order-new', component: { template: '<p>new</p>' } },
      { path: '/register', name: 'register', component: { template: '<p>register</p>' } },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
    ],
  })
  await router.push(path)
  const w = mount(OrdersPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const el = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

function hasButton(text: string, root: ParentNode = document): boolean {
  return [...root.querySelectorAll('button')].some((b) => b.textContent?.trim() === text)
}

async function click(el: HTMLElement): Promise<void> {
  el.click()
  await flushPromises()
}

function dialog(): HTMLElement {
  const el = document.querySelector<HTMLElement>('[role="dialog"], [role="alertdialog"]')
  if (!el) throw new Error('dialog not found')
  return el
}

function card(selector: string): HTMLElement {
  const el = document.querySelector<HTMLElement>(selector)
  if (!el) throw new Error(`${selector} not found`)
  return el
}

const PENDING = makeOrder({ id: 201, order_no: 3, status: 'pending', source: 'customer', user_name: null })
const PAID = makeOrder({ id: 202, order_no: 1, sale_id: 900 })
const ACTIVE = makeOrder({ id: 203, order_no: 2 })

describe('OrdersPage（S15）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    for (const fn of [...Object.values(tablesApi), ...Object.values(ordersApi)]) fn.mockReset()
    tablesApi.fetchOrderTables.mockResolvedValue([
      makeTable(),
      makeTable({ id: 2, name: 'T2', opened_at: new Date(Date.now() - 25 * 60000).toISOString(), unpaid_order_count: 2, unpaid_subtotal: 1800 }),
      makeTable({ id: 3, name: '停止', is_active: false }),
    ])
    ordersApi.fetchOrders.mockImplementation((view: string) => Promise.resolve(view === 'pending' ? [PENDING] : [PAID, ACTIVE, PENDING]))
  })

  it('テーブルのタブ：利用中・経過・未会計を出し、無効で空いているテーブルは出さない。確認待ちの件数をタブに出す', async () => {
    await mountPage()
    expect([...document.querySelectorAll('.table__name')].map((e) => e.textContent)).toEqual(['T1', 'T2'])
    const t2 = card('[data-table="2"]')
    expect(t2.textContent).toContain('利用中')
    expect(t2.textContent).toContain('利用開始から 25 分')
    expect(t2.textContent).toContain('未会計 2 件')
    expect(card('[data-table="1"]').textContent).toContain('空席')
    expect(card('[data-tab="pending"]').textContent?.trim()).toBe('確認待ち 1')
  })

  it('?tab=pending（厨房の件数から）で確認待ちのタブを開く', async () => {
    await mountPage('/orders?tab=pending')
    expect(card('[data-tab="pending"]').getAttribute('aria-pressed')).toBe('true')
    expect(card('[data-order="201"]').textContent).toContain('#3')
  })

  it('［利用開始］は確認してから開く', async () => {
    tablesApi.openOrderTable.mockResolvedValue(makeTable({ opened_at: '2026-09-29T12:00:00+09:00' }))
    await mountPage()
    await click(button('利用開始', card('[data-table="1"]')))
    expect(tablesApi.openOrderTable).not.toHaveBeenCalled()
    expect(dialog().textContent).toContain('「T1」の利用を始めますか')
    await click(button('利用開始', dialog()))
    expect(tablesApi.openOrderTable).toHaveBeenCalledWith(1)
    expect(tablesApi.fetchOrderTables).toHaveBeenCalledTimes(2)
  })

  it('［利用終了］は未会計の件数を出して確認する', async () => {
    tablesApi.closeOrderTable.mockResolvedValue(makeTable({ id: 2 }))
    await mountPage()
    await click(button('利用終了', card('[data-table="2"]')))
    expect(dialog().textContent).toContain('未会計の注文が 2 件あります')
    await click(button('利用終了', dialog()))
    expect(tablesApi.closeOrderTable).toHaveBeenCalledWith(2)
  })

  it('［注文を追加］はそのテーブルを選んだ注文入力へ', async () => {
    const { router } = await mountPage()
    const link = [...card('[data-table="1"]').querySelectorAll('a')].find((a) => a.textContent?.trim() === '注文を追加')
    if (!link) throw new Error('link not found')
    link.click()
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/orders/new?table=1')
  })

  it('［会計へ］は未会計があるテーブルだけに出し、そのテーブルを選んだ会計へ（12 §8.6）', async () => {
    const { router } = await mountPage()
    expect(document.querySelector('[data-to-register="1"]')).toBeNull()
    const link = card('[data-to-register="2"]')
    expect(link.textContent?.trim()).toBe('会計へ')
    link.click()
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/register?table=2')
  })

  it('テーブル・持ち帰り：テーブルのカードの後に、テーブルなしの未会計の注文を「テーブル未設定#n」で並べ、［会計へ］でその注文を選んだ会計へ', async () => {
    const takeout = makeOrder({
      id: 301, order_no: 4, order_table_id: null, table_name: null, takeout_no: 1, subtotal: 1200, created_at: '2026-09-29T12:34:00+09:00',
    })
    const labeled = makeOrder({ id: 302, order_no: 6, order_table_id: null, table_name: null, label: '田中', takeout_no: 2 })
    ordersApi.fetchOrders.mockImplementation((view: string) =>
      Promise.resolve(view === 'pending' ? [PENDING] : view === 'unpaid' ? [ACTIVE, takeout, labeled] : []))
    const { router } = await mountPage()

    expect(card('[data-tab="tables"]').textContent?.trim()).toBe('テーブル・持ち帰り')
    expect([...document.querySelectorAll('.table__name')].map((e) => e.textContent))
      .toEqual(['T1', 'T2', 'テーブル未設定#1', 'テーブル未設定#2（田中）'])
    const c = card('[data-takeout="301"]')
    expect(c.textContent).toContain('注文 #4・12:34')
    expect(c.textContent).toContain('未会計 ¥1,200')
    expect(c.textContent).toContain('提供中')
    card('[data-to-register-order="301"]').click()
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/register?order=301')
  })

  it('テーブルが無くても持ち帰りの注文があれば出し、どちらも無ければ案内を出す', async () => {
    tablesApi.fetchOrderTables.mockResolvedValue([])
    ordersApi.fetchOrders.mockImplementation((view: string) =>
      Promise.resolve(view === 'unpaid' ? [makeOrder({ id: 301, order_table_id: null, table_name: null, takeout_no: 1 })] : []))
    await mountPage()
    expect([...document.querySelectorAll('.table__name')].map((e) => e.textContent)).toEqual(['テーブル未設定#1'])

    document.body.innerHTML = ''
    ordersApi.fetchOrders.mockResolvedValue([])
    await mountPage()
    expect(document.querySelector('.tables')).toBeNull()
    expect(document.body.textContent).toContain('テーブルと持ち帰りの注文はありません')
  })

  it('確認待ち：［受け付ける］で受け付け、一覧を取り直す', async () => {
    ordersApi.acceptOrder.mockResolvedValue({ ...PENDING, status: 'active' })
    await mountPage()
    await click(card('[data-tab="pending"]'))
    expect(ordersApi.fetchOrders).toHaveBeenLastCalledWith('pending')
    await click(button('受け付ける', card('[data-order="201"]')))
    expect(ordersApi.acceptOrder).toHaveBeenCalledWith(201)
    expect(document.querySelector('.adm-ok')?.textContent).toContain('#3 を受け付けました')
  })

  it('［受け付ける］は応答を待たずに確認待ちから外して件数を減らし、失敗したら理由を出して取り直す', async () => {
    let rejectAccept: (e: unknown) => void = () => undefined
    ordersApi.acceptOrder.mockImplementation(() => new Promise((_, rej) => { rejectAccept = rej }))
    await mountPage()
    await click(card('[data-tab="pending"]'))
    await click(button('受け付ける', card('[data-order="201"]')))
    // 応答はまだ：すでに一覧から消え、件数は 0、受け付けた旨を出している
    expect(document.querySelector('[data-order="201"]')).toBeNull()
    expect(card('[data-tab="pending"]').textContent).toContain('0')
    expect(document.querySelector('.adm-ok')?.textContent).toContain('#3 を受け付けました')

    rejectAccept(apiError(409, { message: 'この注文は受け付けできません' }))
    await flushPromises()
    expect(document.querySelector('.adm-ok')).toBeNull()
    expect(document.querySelector('.adm-error')?.textContent).toContain('この注文は受け付けできません')
    expect(document.querySelector('[data-order="201"]')).not.toBeNull() // 取り直した結果で戻る
  })

  it('タブを戻ったときは前に取った一覧を先に出し、古いタブの応答で今のタブの表示を変えない', async () => {
    await mountPage()
    await click(card('[data-tab="today"]'))
    expect(document.querySelector('[data-order="202"]')).not.toBeNull()

    let resolveToday: (v: unknown) => void = () => undefined
    ordersApi.fetchOrders.mockImplementation((view: string) =>
      view === 'today' ? new Promise((r) => { resolveToday = r }) : Promise.resolve([PENDING]),
    )
    await click(card('[data-tab="pending"]'))
    card('[data-tab="today"]').click()
    await Promise.resolve()
    // 取得中でも前回の一覧を出している
    expect(document.querySelector('[data-order="202"]')).not.toBeNull()
    expect(document.body.textContent).not.toContain(ja.common.loading)

    await click(card('[data-tab="pending"]'))
    resolveToday([ACTIVE])
    await flushPromises()
    expect(document.querySelector('[data-order="203"]')).toBeNull()
    expect(document.querySelector('[data-order="201"]')).not.toBeNull()
  })

  it('AC-S15-3：会計済みの注文には［取り消す］が無く、未会計は確認してから取り消す', async () => {
    ordersApi.cancelOrder.mockResolvedValue({ ...ACTIVE, status: 'cancelled' })
    await mountPage()
    await click(card('[data-tab="today"]'))
    expect(hasButton('取り消す', card('[data-order="202"]'))).toBe(false)
    expect(card('[data-order="202"]').textContent).toContain('会計済み')

    await click(button('取り消す', card('[data-order="203"]')))
    expect(ordersApi.cancelOrder).not.toHaveBeenCalled()
    expect(dialog().textContent).toContain('#2（T1）を取り消しますか')
    await click(button('取り消す', dialog()))
    expect(ordersApi.cancelOrder).toHaveBeenCalledWith(203)
  })

  it('取り消しが 409（会計済みになっていた）ならメッセージを出して一覧を取り直す', async () => {
    ordersApi.cancelOrder.mockRejectedValue(apiError(409, { message: 'この注文は会計済みです', code: 'ORDER_ALREADY_PAID' }))
    await mountPage()
    await click(card('[data-tab="unpaid"]'))
    const calls = ordersApi.fetchOrders.mock.calls.length
    await click(button('取り消す', card('[data-order="203"]')))
    await click(button('取り消す', dialog()))
    expect(document.querySelector('.adm-error')?.textContent).toContain('この注文は会計済みです')
    expect(ordersApi.fetchOrders.mock.calls.length).toBeGreaterThan(calls)
    expect(document.querySelector('[role="dialog"], [role="alertdialog"]')).toBeNull()
  })

  // ── 17 §8.2 品目の取消
  const CURRY = makeOrderItem({ id: 501, product_name: 'カレー', unit_price: 800, quantity: 3, line_total: 2400 })
  const SALAD = makeOrderItem({ id: 502, product_name: 'サラダ', unit_price: 500, quantity: 1, line_total: 500 })
  const TWO = makeOrder({ id: 204, order_no: 5, subtotal: 2900, items: [CURRY, SALAD] })

  async function openItemCancel(order = TWO, itemId = 501): Promise<void> {
    ordersApi.fetchOrders.mockImplementation((view: string) => Promise.resolve(view === 'pending' ? [] : [order]))
    await mountPage()
    await click(card('[data-tab="unpaid"]'))
    await click(card(`button[data-item="${itemId}"]`))
  }

  it('AC-S15-4：品目の行を押すと取り消す数を選べ、［取り消す］で送ってカードを差し替える', async () => {
    const after = makeOrder({
      ...TWO,
      subtotal: 1300,
      items: [{ ...CURRY, quantity: 1, line_total: 800 }, { ...CURRY, id: 503, quantity: 2, line_total: 1600, cancelled_at: '2026-09-29T12:30:00+09:00' }, SALAD],
    })
    ordersApi.cancelOrderItem.mockResolvedValue(after)
    await openItemCancel()
    expect(ordersApi.cancelOrderItem).not.toHaveBeenCalled()
    const d = dialog()
    expect(d.textContent).toContain('品目の取消')
    expect(d.textContent).toContain('#5（T1）カレー ×3')
    expect(card('[data-cancel-count]').textContent).toBe('1')
    expect(d.textContent).toContain('残り 2 個になります')
    expect(d.querySelector('[data-whole-order]')).toBeNull()
    expect(card('[data-cancel-decrease]').hasAttribute('disabled')).toBe(true)

    await click(card('[data-cancel-increase]'))
    await click(card('[data-cancel-increase]'))
    expect(card('[data-cancel-count]').textContent).toBe('3')
    expect(card('[data-cancel-increase]').hasAttribute('disabled')).toBe(true)
    expect(d.textContent).not.toContain('残り')
    await click(card('[data-cancel-decrease]'))

    await click(button('取り消す', dialog()))
    expect(ordersApi.cancelOrderItem).toHaveBeenCalledWith(501, { quantity: 2, expected_quantity: 3 })
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
    expect(document.querySelector('.adm-ok')?.textContent).toContain('#5 の カレー を 2 個取り消しました')
    const c = card('[data-order="204"]')
    expect(c.textContent).toContain('1,300')
    const cancelled = card('[data-item="503"]')
    expect(cancelled.tagName).toBe('DIV')
    expect(cancelled.textContent).toContain(ja.orders.itemCancelled)
    expect(cancelled.classList.contains('item--cancelled')).toBe(true)
  })

  it('AC-S15-5：最後の品目を全数取り消すときは「注文ごと取消になります」を出す。数量 1 なら数の入力は出さない', async () => {
    await openItemCancel(makeOrder({ id: 205, order_no: 6, items: [SALAD], subtotal: 500 }), 502)
    expect(document.querySelector('[data-cancel-count]')).toBeNull()
    expect(card('[data-whole-order]').textContent).toContain('注文ごと取消になります')

    // 注文ごと取消になったら一覧を取り直し、未会計から外す
    const cancelled = makeOrder({ id: 205, order_no: 6, status: 'cancelled', items: [{ ...SALAD, cancelled_at: '2026-09-29T12:30:00+09:00' }], subtotal: 500 })
    ordersApi.cancelOrderItem.mockResolvedValue(cancelled)
    ordersApi.fetchOrders.mockResolvedValue([])
    await click(button('取り消す', dialog()))
    expect(ordersApi.cancelOrderItem).toHaveBeenCalledWith(502, { quantity: 1, expected_quantity: 1 })
    expect(document.querySelector('[data-order="205"]')).toBeNull()
  })

  it('提供済みの品目は「提供済みの品目です」を添える', async () => {
    await openItemCancel(makeOrder({ ...TWO, items: [CURRY, { ...SALAD, served_at: '2026-09-29T12:10:00+09:00' }] }), 502)
    expect(dialog().textContent).toContain('提供済みの品目です')
    expect(document.querySelector('[data-whole-order]')).toBeNull()
  })

  it('AC-S15-6：409（ほかの端末で変わった）なら閉じて「ほかの端末で注文が変わりました」を出し、一覧を取り直す', async () => {
    ordersApi.cancelOrderItem.mockRejectedValue(apiError(409, { message: 'x', code: 'ORDER_STATE_CONFLICT', details: { quantity: 2 } }))
    await openItemCancel()
    const calls = ordersApi.fetchOrders.mock.calls.length
    await click(button('取り消す', dialog()))
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
    expect(document.querySelector('.adm-error')?.textContent).toContain(ja.orders.itemCancel.conflict)
    expect(ordersApi.fetchOrders.mock.calls.length).toBeGreaterThan(calls)
  })

  it('AC-S15-7：会計済みの注文・取消済みの品目は行を押せない', async () => {
    const paid = makeOrder({ id: 206, order_no: 7, sale_id: 901, items: [makeOrderItem({ id: 601 })] })
    const withCancelled = makeOrder({ id: 207, order_no: 8, items: [makeOrderItem({ id: 701 }), makeOrderItem({ id: 702, cancelled_at: '2026-09-29T12:30:00+09:00' })] })
    ordersApi.fetchOrders.mockImplementation((view: string) => Promise.resolve(view === 'pending' ? [] : [paid, withCancelled]))
    await mountPage()
    await click(card('[data-tab="today"]'))
    expect(card('[data-item="601"]').tagName).toBe('DIV')
    expect(card('[data-item="702"]').tagName).toBe('DIV')
    expect(card('[data-item="702"]').textContent).toContain('取消')
    expect(card('[data-item="701"]').tagName).toBe('BUTTON')
    await click(card('[data-item="601"]'))
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
  })
})
