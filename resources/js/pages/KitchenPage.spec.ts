import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import KitchenPage from '@/pages/KitchenPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { makeOrder, makeOrderItem } from '@/test/orders'
import type { KitchenOrders, Order } from '@/types/api'

const ordersApi = vi.hoisted(() => ({ fetchKitchenOrders: vi.fn(), serveAllOrder: vi.fn(), setItemServed: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)
const sound = vi.hoisted(() => ({ loadSoundEnabled: vi.fn(), saveSoundEnabled: vi.fn(), enableSound: vi.fn(), playBeep: vi.fn() }))
vi.mock('@/lib/kitchenSound', () => sound)

const TWO_ITEMS = makeOrder({
  id: 101,
  order_no: 5,
  source: 'customer',
  user_name: null,
  note: 'アレルギー：卵',
  items: [
    makeOrderItem({ id: 1, product_name: 'コーヒー', quantity: 2, memo: '氷なし', options: [{ product_option_id: 9, option_name: '大盛り', price: 100 }] }),
    makeOrderItem({ id: 2, product_name: 'ケーキ' }),
  ],
})

function data(extra: Partial<KitchenOrders> = {}): KitchenOrders {
  return {
    server_time: new Date().toISOString(),
    polling: { interval_sec: 10, active: true, next_change_at: null },
    in_progress: [TWO_ITEMS],
    done: [],
    pending_count: 2,
    ...extra,
  }
}

let wrapper: VueWrapper | null = null

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('staff')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/kitchen', name: 'kitchen', component: KitchenPage },
      { path: '/orders', name: 'orders', component: { template: '<p>orders</p>' } },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
    ],
  })
  await router.push('/kitchen')
  wrapper = mount(KitchenPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { router }
}

function el(selector: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(selector)
  if (!found) throw new Error(`${selector} not found`)
  return found
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const found = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!found) throw new Error(`button ${text} not found`)
  return found
}

function itemButton(id: number): HTMLButtonElement {
  return el(`[data-item="${id}"] button`) as HTMLButtonElement
}

async function click(target: HTMLElement): Promise<void> {
  target.click()
  await flushPromises()
}

function dialog(): HTMLElement | null {
  return document.querySelector<HTMLElement>('[role="dialog"], [role="alertdialog"]')
}

function served(order: Order, ids: number[], done = false): Order {
  return {
    ...order,
    served_at: done ? '2026-09-29T12:20:00+09:00' : null,
    items: order.items.map((i) => (ids.includes(i.id) ? { ...i, served_at: '2026-09-29T12:20:00+09:00' } : i)),
  }
}

describe('KitchenPage（S14）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    for (const fn of [...Object.values(ordersApi), ...Object.values(sound)]) fn.mockReset()
    sound.loadSoundEnabled.mockReturnValue(false)
    ordersApi.fetchKitchenOrders.mockImplementation(() => Promise.resolve({ changed: true, etag: '"o-1-1"', data: data() }))
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('カード：番号・置き場所・客の印・品目（オプション・×n・メモ）・注文メモ。自動更新の状態と最終更新を出す', async () => {
    await mountPage()
    const card = el('[data-order="101"]')
    expect(card.querySelector('.kcard__no')?.textContent).toBe('#5')
    expect(card.querySelector('.kcard__place')?.textContent).toBe('T1')
    expect(card.querySelector('.kcard__source')?.textContent).toBe('客')
    const row = el('[data-item="1"]')
    expect(row.textContent).toContain('大盛り')
    expect(row.textContent).toContain('×2')
    expect(row.textContent).toContain('メモ：氷なし')
    expect(card.textContent).toContain('アレルギー：卵')
    expect(itemButton(1).getAttribute('aria-label')).toBe('コーヒー を提供済みにする')
    expect(el('[data-polling]').textContent).toBe('自動更新：ON（10 秒）')
    expect(document.querySelector('.kitchen__updated')?.textContent).toMatch(/^最終 \d{2}:\d{2}:\d{2}$/)
  })

  it('10 分を超えた未完了の注文は遅延として出す', async () => {
    const old = makeOrder({ id: 102, order_no: 6, created_at: new Date(Date.now() - 11 * 60000).toISOString() })
    ordersApi.fetchKitchenOrders.mockResolvedValue({ changed: true, etag: null, data: data({ in_progress: [old] }) })
    await mountPage()
    const elapsed = el('[data-order="102"] .kcard__elapsed')
    expect(elapsed.textContent).toContain('11 分・遅延')
    expect(elapsed.classList.contains('kcard__elapsed--late')).toBe(true)
  })

  it('AC-S14-8：確認待ちは件数だけを出し、注文確認の確認待ちタブへ移れる', async () => {
    const { router } = await mountPage()
    const link = [...document.querySelectorAll('a')].find((a) => a.textContent?.trim() === '確認待ち 2 件')
    if (!link) throw new Error('link not found')
    link.click()
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/orders?tab=pending')
  })

  it('品目の［提供済］は確認なしで送り、応答の行だけ提供済みにする。［✓提供済］は確認なしで戻す', async () => {
    ordersApi.setItemServed.mockResolvedValueOnce(served(TWO_ITEMS, [1]))
    await mountPage()
    await click(itemButton(1))
    expect(dialog()).toBeNull()
    expect(ordersApi.setItemServed).toHaveBeenCalledWith(1, true)
    expect(itemButton(1).textContent?.trim()).toBe('✓提供済')
    expect(itemButton(2).textContent?.trim()).toBe('提供済')

    ordersApi.setItemServed.mockResolvedValueOnce(TWO_ITEMS)
    await click(itemButton(1))
    expect(dialog()).toBeNull()
    expect(ordersApi.setItemServed).toHaveBeenLastCalledWith(1, false)
    expect(itemButton(1).textContent?.trim()).toBe('提供済')
  })

  it('AC-S14-4：最後の品目は確認する。［やめる］では何も変わらず、［完了にする］で完了タブへ移る', async () => {
    const one = served(TWO_ITEMS, [1])
    ordersApi.fetchKitchenOrders.mockResolvedValue({ changed: true, etag: null, data: data({ in_progress: [one] }) })
    ordersApi.setItemServed.mockResolvedValue(served(TWO_ITEMS, [1, 2], true))
    await mountPage()

    await click(itemButton(2))
    expect(dialog()?.textContent).toContain('#5（T1）のすべての品目が提供済みになります')
    await click(button('やめる'))
    expect(dialog()).toBeNull()
    expect(ordersApi.setItemServed).not.toHaveBeenCalled()

    await click(itemButton(2))
    await click(button('完了にする'))
    expect(ordersApi.setItemServed).toHaveBeenCalledWith(2, true)
    expect(document.querySelector('[data-order="101"]')).toBeNull()
    expect(el('[data-tab="done"]').textContent?.trim()).toBe('完了 1')
    await click(el('[data-tab="done"]'))
    expect(el('[data-order="101"]').classList.contains('kcard--done')).toBe(true)
  })

  it('AC-S14-3：［まとめて提供済みにする］は未提供の数を出して確認し、［やめる］では何も変わらない', async () => {
    ordersApi.serveAllOrder.mockResolvedValue(served(TWO_ITEMS, [1, 2], true))
    await mountPage()
    await click(button('まとめて提供済みにする'))
    expect(dialog()?.textContent).toContain('#5（T1）の未提供 2 品をすべて提供済みにします')
    await click(button('やめる'))
    expect(ordersApi.serveAllOrder).not.toHaveBeenCalled()
    expect(document.querySelector('[data-order="101"]')).not.toBeNull()

    await click(button('まとめて提供済みにする'))
    await click(button('提供済みにする'))
    expect(ordersApi.serveAllOrder).toHaveBeenCalledWith(101)
    expect(el('[data-tab="progress"]').textContent?.trim()).toBe('調理中 0')
  })

  it('操作の失敗は赤い帯を出し、ボタンを戻す。取り消されていた（404）ときは取り直す', async () => {
    ordersApi.setItemServed.mockRejectedValue(apiError(404, { message: '注文が見つかりません' }))
    await mountPage()
    const calls = ordersApi.fetchKitchenOrders.mock.calls.length
    await click(itemButton(1))
    expect(document.querySelector('.adm-error')?.textContent).toContain('注文が見つかりません')
    expect(itemButton(1).disabled).toBe(false)
    expect(itemButton(1).textContent?.trim()).toBe('提供済')
    expect(ordersApi.fetchKitchenOrders.mock.calls.length).toBeGreaterThan(calls)
  })

  it('自動更新 OFF は切り替わる時刻を出し、［更新］はいつでも押せる', async () => {
    ordersApi.fetchKitchenOrders.mockResolvedValueOnce({
      changed: true,
      etag: null,
      data: { ...data(), polling: { interval_sec: 10, active: false, next_change_at: '2026-09-30T17:00:00+09:00' } },
    })
    await mountPage()
    expect(el('[data-polling]').textContent).toBe('自動更新：OFF（17:00 から ON）')
    await click(el('[data-refresh]'))
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(2)
  })

  it('音は既定 OFF。ON にするとタップの中で有効にして保存し、新しい注文で鳴らす', async () => {
    await mountPage()
    expect(el('[data-sound]').textContent?.trim()).toBe('音 OFF')
    await click(el('[data-sound]'))
    expect(sound.enableSound).toHaveBeenCalled()
    expect(sound.saveSoundEnabled).toHaveBeenCalledWith(true)
    expect(el('[data-sound]').textContent?.trim()).toBe('音 ON')

    ordersApi.fetchKitchenOrders.mockResolvedValue({
      changed: true,
      etag: null,
      data: data({ in_progress: [TWO_ITEMS, makeOrder({ id: 103, order_no: 7 })] }),
    })
    await click(el('[data-refresh]'))
    expect(sound.playBeep).toHaveBeenCalledTimes(1)
    expect(el('[data-order="103"]').classList.contains('kcard--new')).toBe(true)
  })
})
