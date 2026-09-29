import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import OrdersPage from '@/pages/OrdersPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { makeOrder, makeTable } from '@/test/orders'

const tablesApi = vi.hoisted(() => ({ fetchOrderTables: vi.fn(), openOrderTable: vi.fn(), closeOrderTable: vi.fn() }))
vi.mock('@/api/orderTables', () => tablesApi)
const ordersApi = vi.hoisted(() => ({ fetchOrders: vi.fn(), acceptOrder: vi.fn(), cancelOrder: vi.fn() }))
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

  it('確認待ち：［受け付ける］で受け付け、一覧を取り直す', async () => {
    ordersApi.acceptOrder.mockResolvedValue({ ...PENDING, status: 'active' })
    await mountPage()
    await click(card('[data-tab="pending"]'))
    expect(ordersApi.fetchOrders).toHaveBeenLastCalledWith('pending')
    await click(button('受け付ける', card('[data-order="201"]')))
    expect(ordersApi.acceptOrder).toHaveBeenCalledWith(201)
    expect(document.querySelector('.adm-ok')?.textContent).toContain('#3 を受け付けました')
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
})
