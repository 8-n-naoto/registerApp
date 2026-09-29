import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useKitchenStore } from '@/stores/kitchen'
import { makeOrder } from '@/test/orders'
import type { KitchenOrders, Order, PollingState } from '@/types/api'

const ordersApi = vi.hoisted(() => ({ fetchKitchenOrders: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)

const NOW = Date.parse('2026-09-29T12:00:00+09:00')
let visibility: DocumentVisibilityState = 'visible'

function payload(extra: Partial<KitchenOrders> = {}, polling: Partial<PollingState> = {}): KitchenOrders {
  return {
    server_time: new Date(Date.now()).toISOString(),
    polling: { interval_sec: 10, active: true, next_change_at: null, ...polling },
    in_progress: [makeOrder()],
    done: [],
    pending_count: 0,
    ...extra,
  }
}

function changed(data: KitchenOrders, etag = '"o-1-1"') {
  return Promise.resolve({ changed: true, etag, data })
}

function setVisibility(state: DocumentVisibilityState): void {
  visibility = state
  document.dispatchEvent(new Event('visibilitychange'))
}

describe('kitchenStore（12 §6.1.3）', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    visibility = 'visible'
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility })
    setActivePinia(createPinia())
    ordersApi.fetchKitchenOrders.mockReset()
  })

  afterEach(() => {
    useKitchenStore().stop()
    vi.useRealTimers()
  })

  it('自動更新 ON は 10 秒ごとに ETag を付けて取得し、304 は内容を変えずに最終更新だけ進める', async () => {
    ordersApi.fetchKitchenOrders
      .mockImplementationOnce(() => changed(payload()))
      .mockResolvedValue({ changed: false, etag: '"o-1-1"' })
    const kitchen = useKitchenStore()
    kitchen.start()
    await vi.advanceTimersByTimeAsync(0)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledWith(null)
    expect(kitchen.inProgress.map((o) => o.id)).toEqual([101])
    const first = kitchen.lastUpdatedAt

    await vi.advanceTimersByTimeAsync(9_999)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(2)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenLastCalledWith('"o-1-1"')
    expect(kitchen.inProgress.map((o) => o.id)).toEqual([101])
    expect(kitchen.lastUpdatedAt).toBeGreaterThan(first ?? 0)
  })

  it('AC-S14-5：時間帯の外は自動更新せず、next_change_at（サーバー時刻で補正）を過ぎたら取得する', async () => {
    // 端末の時計が 30 秒遅れている
    const serverNow = NOW + 30_000
    const at = new Date(serverNow + 60_000).toISOString()
    ordersApi.fetchKitchenOrders
      .mockImplementationOnce(() => changed(payload({ server_time: new Date(serverNow).toISOString() }, { active: false, next_change_at: at })))
      .mockImplementation(() => changed(payload({ server_time: new Date(Date.now() + 30_000).toISOString() })))
    const kitchen = useKitchenStore()
    kitchen.start()
    await vi.advanceTimersByTimeAsync(0)
    expect(kitchen.clockOffset).toBe(30_000)

    await vi.advanceTimersByTimeAsync(30_000)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(31_000) // 60 秒 + 1 秒
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(2)
    expect(kitchen.polling?.active).toBe(true)
  })

  it('OFF で次の切り替えが無ければ自動では取らず、［更新］では取る', async () => {
    ordersApi.fetchKitchenOrders.mockImplementation(() => changed(payload({}, { active: false })))
    const kitchen = useKitchenStore()
    kitchen.start()
    await vi.advanceTimersByTimeAsync(60_000)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(1)
    await kitchen.refreshNow()
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(2)
  })

  it('AC-S14-7：裏にすると止まり、戻すとすぐ 1 回取得する', async () => {
    ordersApi.fetchKitchenOrders.mockImplementation(() => changed(payload()))
    const kitchen = useKitchenStore()
    kitchen.start()
    await vi.advanceTimersByTimeAsync(0)
    setVisibility('hidden')
    await vi.advanceTimersByTimeAsync(60_000)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(1)

    setVisibility('visible')
    await vi.advanceTimersByTimeAsync(0)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(10_000)
    expect(ordersApi.fetchKitchenOrders).toHaveBeenCalledTimes(3)
    expect(kitchen.loaded).toBe(true)
  })

  it('連続 3 回の失敗で offline、成功で戻る。一度も読めていなければ OFF の設定が分からないので 10 秒ごとに取り直す', async () => {
    ordersApi.fetchKitchenOrders.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    const kitchen = useKitchenStore()
    kitchen.start()
    await vi.advanceTimersByTimeAsync(0)
    await vi.advanceTimersByTimeAsync(10_000)
    expect(kitchen.offline).toBe(false)
    await vi.advanceTimersByTimeAsync(10_000)
    expect(kitchen.failures).toBe(3)
    expect(kitchen.offline).toBe(true)

    ordersApi.fetchKitchenOrders.mockImplementation(() => changed(payload()))
    await vi.advanceTimersByTimeAsync(10_000)
    expect(kitchen.offline).toBe(false)
    expect(kitchen.loaded).toBe(true)
  })

  it('新しい注文だけを 5 秒強調し、通知する（最初の読み込みでは通知しない）', async () => {
    const onNew = vi.fn()
    ordersApi.fetchKitchenOrders
      .mockImplementationOnce(() => changed(payload()))
      .mockImplementation(() => changed(payload({ in_progress: [makeOrder(), makeOrder({ id: 102, order_no: 2 })] }), '"o-1-2"'))
    const kitchen = useKitchenStore()
    kitchen.start({ onNew })
    await vi.advanceTimersByTimeAsync(0)
    expect(onNew).not.toHaveBeenCalled()
    expect(kitchen.highlighted.size).toBe(0)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(onNew).toHaveBeenCalledWith([102])
    expect([...kitchen.highlighted]).toEqual([102])
    await vi.advanceTimersByTimeAsync(5_000)
    expect(kitchen.highlighted.size).toBe(0)
  })

  it('applyOrder：完了は完了の先頭へ、未提供に戻したら調理中へ（受付順）、取消は消す', () => {
    const kitchen = useKitchenStore()
    const a = makeOrder({ id: 1, created_at: '2026-09-29T12:00:00+09:00' })
    const b = makeOrder({ id: 2, created_at: '2026-09-29T12:05:00+09:00' })
    kitchen.inProgress = [a, b]
    const served: Order = { ...a, served_at: '2026-09-29T12:10:00+09:00' }

    kitchen.applyOrder(served)
    expect(kitchen.inProgress.map((o) => o.id)).toEqual([2])
    expect(kitchen.done.map((o) => o.id)).toEqual([1])

    kitchen.applyOrder(a)
    expect(kitchen.inProgress.map((o) => o.id)).toEqual([1, 2])
    expect(kitchen.done).toEqual([])

    kitchen.applyOrder({ ...b, status: 'cancelled' })
    expect(kitchen.inProgress.map((o) => o.id)).toEqual([1])
  })
})
