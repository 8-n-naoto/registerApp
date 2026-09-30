import { AxiosError } from 'axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { StaffOrderInput } from '@/api/orders'
import OrderNewPage from '@/pages/OrderNewPage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import { stubMatchMedia } from '@/test/matchMedia'
import { makeOrder, makeTable } from '@/test/orders'
import { makeBootstrap } from '@/test/register'

const registerApi = vi.hoisted(() => ({ fetchBootstrap: vi.fn() }))
vi.mock('@/api/register', () => registerApi)
const tablesApi = vi.hoisted(() => ({ fetchOrderTables: vi.fn() }))
vi.mock('@/api/orderTables', () => tablesApi)
const ordersApi = vi.hoisted(() => ({ createOrder: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)

async function mountPage(path = '/orders/new') {
  return (await mountWithRouter(path)).w
}

async function mountWithRouter(path = '/orders/new') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('staff')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/orders/new', name: 'order-new', component: OrderNewPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
      { path: '/register', name: 'register', component: { template: '<p>register</p>' } },
    ],
  })
  await router.push(path)
  const w = mount(OrderNewPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function tile(id: number): HTMLButtonElement {
  const el = document.querySelector<HTMLButtonElement>(`[data-product="${id}"]`)
  if (!el) throw new Error(`product ${id} not found`)
  return el
}

function buttons(text: string): HTMLButtonElement[] {
  return [...document.querySelectorAll<HTMLButtonElement>('button')].filter((b) => b.textContent?.trim() === text)
}

async function click(el: HTMLElement | undefined): Promise<void> {
  if (!el) throw new Error('element not found')
  el.click()
  await flushPromises()
}

function select(): HTMLSelectElement {
  const el = document.querySelector<HTMLSelectElement>('#order-table')
  if (!el) throw new Error('select not found')
  return el
}

/** 注文の一覧の［厨房へ送信］→ 送信のシートの［厨房へ送信］ */
async function sendFromPanel(): Promise<void> {
  await click(buttons('厨房へ送信')[0])
  await click(buttons('厨房へ送信').at(-1))
}

function sentInput(call = 0): StaffOrderInput {
  return ordersApi.createOrder.mock.calls[call]?.[0] as StaffOrderInput
}

describe('OrderNewPage（S13）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    localStorage.clear()
    stubMatchMedia(true)
    registerApi.fetchBootstrap.mockReset().mockImplementation(() => Promise.resolve(makeBootstrap()))
    tablesApi.fetchOrderTables.mockReset().mockResolvedValue([
      makeTable(),
      makeTable({ id: 2, name: 'T2', opened_at: '2026-09-29T11:00:00+09:00' }),
      makeTable({ id: 3, name: '停止', is_active: false }),
    ])
    ordersApi.createOrder.mockReset()
  })

  it('AC-S13-1：テーブルは「テーブルなし」と有効なものだけで、利用中を示す。呼び名はテーブルなしのときだけ', async () => {
    await mountPage()
    expect([...select().options].map((o) => o.text.trim())).toEqual(['テーブルなし', 'T1', 'T2（利用中）'])
    expect(document.querySelector('#order-label')).not.toBeNull()

    select().value = '1'
    select().dispatchEvent(new Event('change'))
    await flushPromises()
    expect(document.querySelector('#order-label')).toBeNull()
  })

  it('?table= で来たときはそのテーブルを選ぶ（無効なテーブルは選ばない）', async () => {
    await mountPage('/orders/new?table=2')
    expect(select().value).toBe('2')
    document.body.innerHTML = ''
    localStorage.clear()
    await mountPage('/orders/new?table=3')
    expect(select().value).toBe('')
  })

  it('商品のタップで品目に入り、送信のシートで注文メモを付けて送る。成功すると品目が空になり番号を出す', async () => {
    await mountPage('/orders/new?table=1')
    await click(tile(1))
    await click(tile(1))
    expect(document.querySelector('.line__count')?.textContent).toBe('2')

    await click(buttons('厨房へ送信')[0])
    const note = document.querySelector<HTMLTextAreaElement>('#order-note')
    if (!note) throw new Error('note not found')
    note.value = 'すぐ出す'
    note.dispatchEvent(new Event('input'))
    ordersApi.createOrder.mockResolvedValue(makeOrder({ order_no: 7 }))
    await click(buttons('厨房へ送信').at(-1))

    expect(sentInput()).toMatchObject({ order_table_id: 1, note: 'すぐ出す', expected_subtotal: 800, items: [{ product_id: 1, quantity: 2 }] })
    expect(document.querySelector('.order-new__notice')?.textContent).toContain('#7 を送信しました')
    expect(document.querySelectorAll('.line')).toHaveLength(0)
    expect(select().value).toBe('1')
  })

  it('［送信して会計へ］は送信に成功したら S02 を ?order=<注文 ID> で開く', async () => {
    const { router } = await mountWithRouter()
    await click(tile(1))
    await click(buttons('厨房へ送信')[0])
    ordersApi.createOrder.mockResolvedValue(makeOrder({ id: 55, order_no: 8 }))
    await click(buttons('送信して会計へ')[0])

    expect(ordersApi.createOrder).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('register')
    expect(router.currentRoute.value.query).toEqual({ order: '55' })
  })

  it('［送信して会計へ］が失敗したら画面に残り、品目を残してエラーを出す', async () => {
    const { router } = await mountWithRouter()
    await click(tile(1))
    await click(buttons('厨房へ送信')[0])
    ordersApi.createOrder.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    await click(buttons('送信して会計へ')[0])

    expect(router.currentRoute.value.name).toBe('order-new')
    expect(document.querySelector('[role="alert"]')?.textContent).toContain('注文は送信されていません')
    expect(document.querySelectorAll('.line')).toHaveLength(1)
  })

  it('AC-S13-2：通信断は品目を残してエラーを出し、送り直しは同じ client_uuid', async () => {
    await mountPage()
    await click(tile(1))
    ordersApi.createOrder.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))

    await sendFromPanel()
    expect(document.querySelector('[role="alert"]')?.textContent).toContain('注文は送信されていません')
    expect(document.querySelectorAll('.line')).toHaveLength(1)

    await sendFromPanel()
    expect(ordersApi.createOrder).toHaveBeenCalledTimes(2)
    expect(sentInput(1).client_uuid).toBe(sentInput(0).client_uuid)
  })

  it('AC-S13-3：品目をタップするとメモを入れられる', async () => {
    await mountPage()
    await click(tile(1))
    await click(document.querySelector<HTMLButtonElement>('.line__name') ?? undefined)
    const input = document.querySelector<HTMLInputElement>('.line__input')
    if (!input) throw new Error('memo input not found')
    input.value = '氷なし'
    input.dispatchEvent(new Event('input'))
    await click(buttons('完了')[0])
    expect(document.querySelector('.line__memo')?.textContent).toContain('氷なし')

    ordersApi.createOrder.mockResolvedValue(makeOrder())
    await sendFromPanel()
    expect(sentInput().items[0]?.memo).toBe('氷なし')
  })

  it('品目が無いときは送信できない', async () => {
    await mountPage()
    expect(buttons('厨房へ送信')[0]?.disabled).toBe(true)
  })
})
