import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { OfflineSaleInput } from '@/api/register'
import { ja } from '@/i18n/ja'
import { kvGet, resetOfflineDbForTest } from '@/lib/offlineDb'
import { OUTBOX_VERSION, useOutboxStore, type OutboxEntry } from '@/stores/outbox'
import { apiError } from '@/test/helpers'
import { makeSale } from '@/test/register'
import type { Sale } from '@/types/api'

const api = vi.hoisted(() => ({ createOfflineSale: vi.fn<(input: OfflineSaleInput) => Promise<Sale>>() }))
vi.mock('@/api/register', () => api)

function makeEntry(uuid: string, createdAt: string, storeId = 1): OutboxEntry {
  const input: OfflineSaleInput = {
    client_uuid: uuid,
    tax_type_id: 1,
    payment_method_id: 1,
    items: [{ product_id: 1, quantity: 1, option_ids: [], unit_price: 400, option_prices: [] }],
    discount: null,
    received: 400,
    customer_count: null,
    memo: null,
    device_name: null,
    expected_total: 400,
    order_ids: [],
    sold_at: createdAt,
    operator_id: 1,
    tax_rate_permille: 100,
    price_mode: 'tax_included',
    rounding: 'floor',
  }
  return {
    version: OUTBOX_VERSION,
    store_id: storeId,
    client_uuid: uuid,
    created_at: createdAt,
    input,
    sale: makeSale({ id: 0, client_uuid: uuid, is_offline: true, total: 400 }),
    status: 'pending',
    attempts: 0,
    last_error: null,
  }
}

const networkError = (): AxiosError => new AxiosError('Network Error', 'ERR_NETWORK')

describe('outbox（14 §7.2）', () => {
  beforeEach(async () => {
    localStorage.clear()
    resetOfflineDbForTest()
    setActivePinia(createPinia())
    api.createOfflineSale.mockReset()
    await useOutboxStore().init(1)
  })

  afterEach(() => {
    useOutboxStore().stop()
  })

  it('加えた会計は端末に残り、読み直しても古い順に並ぶ。他店舗の分は数えない', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('b', '2026-10-03T10:00:02Z'))
    await outbox.add(makeEntry('a', '2026-10-03T10:00:01Z'))
    await outbox.add(makeEntry('x', '2026-10-03T10:00:00Z', 2))
    expect(outbox.entries.map((e) => e.client_uuid)).toEqual(['a', 'b'])

    setActivePinia(createPinia())
    const again = useOutboxStore()
    api.createOfflineSale.mockRejectedValue(networkError())
    await again.init(1)
    expect(again.entries.map((e) => e.client_uuid)).toEqual(['a', 'b'])
    again.stop()
  })

  it('古い順に送り、送れた分（201）だけ端末から消す。通信できなければそこで止めて残す', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('a', '2026-10-03T10:00:01Z'))
    await outbox.add(makeEntry('b', '2026-10-03T10:00:02Z'))
    await outbox.add(makeEntry('c', '2026-10-03T10:00:03Z'))
    api.createOfflineSale
      .mockResolvedValueOnce(makeSale({ id: 11 }))
      .mockRejectedValueOnce(networkError())

    await outbox.syncNow()

    expect(api.createOfflineSale.mock.calls.map(([input]) => input.client_uuid)).toEqual(['a', 'b'])
    expect(outbox.entries.map((e) => e.client_uuid)).toEqual(['b', 'c'])
    expect(outbox.pending).toHaveLength(2)
    expect(outbox.lastError).toBe(ja.outbox.networkError)
    expect(await kvGet('outbox:1:a')).toBeUndefined()
    expect(await kvGet('outbox:1:b')).toBeDefined()
  })

  it('5xx・429 は送信待ちのまま。401 / 419 は「ログインし直すと送る」にして止める', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('a', '2026-10-03T10:00:01Z'))
    api.createOfflineSale.mockRejectedValueOnce(apiError(503, {}))
    await outbox.syncNow()
    expect(outbox.pending).toHaveLength(1)

    api.createOfflineSale.mockRejectedValueOnce(apiError(429, {}))
    await outbox.syncNow()
    expect(outbox.pending).toHaveLength(1)

    api.createOfflineSale.mockRejectedValueOnce(apiError(419, {}))
    await outbox.syncNow()
    expect(outbox.authNeeded).toBe(true)
    expect(outbox.pending).toHaveLength(1)
    expect(outbox.failed).toHaveLength(0)
  })

  it('422 は failed にして次へ進む。［もう一度送る］で送り直し、通れば消す', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('a', '2026-10-03T10:00:01Z'))
    await outbox.add(makeEntry('b', '2026-10-03T10:00:02Z'))
    api.createOfflineSale
      .mockRejectedValueOnce(apiError(422, { message: '入力内容を確認してください' }))
      .mockResolvedValueOnce(makeSale({ id: 12 }))

    await outbox.syncNow()

    expect(outbox.entries.map((e) => [e.client_uuid, e.status, e.last_error])).toEqual([['a', 'failed', '入力内容を確認してください']])
    expect(await kvGet('outbox:1:a')).toMatchObject({ status: 'failed' })

    api.createOfflineSale.mockResolvedValueOnce(makeSale({ id: 13 }))
    await outbox.retryFailed()
    expect(outbox.count).toBe(0)
  })

  it('送れなかった会計は書き出せ、端末から消せる', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('a', '2026-10-03T10:00:01Z'))
    api.createOfflineSale.mockRejectedValueOnce(apiError(404, { message: 'x' }))
    await outbox.syncNow()

    const exported = JSON.parse(outbox.exportJson()) as { kind: string; store_id: number; entries: { status: string; input: OfflineSaleInput }[] }
    expect(exported.kind).toBe('regi-offline-sales')
    expect(exported.store_id).toBe(1)
    expect(exported.entries.map((e) => [e.status, e.input.client_uuid])).toEqual([['failed', 'a']])

    await outbox.deleteFailed('a')
    expect(outbox.count).toBe(0)
    expect(await kvGet('outbox:1:a')).toBeUndefined()
  })

  it('［取り消す］：送る前なら端末から消し、送れていればサーバーの会計 ID を返す。送信中なら終わるのを待つ', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('a', '2026-10-03T10:00:01Z'))
    expect(await outbox.discard(1, 'a')).toBe('removed')
    expect(outbox.count).toBe(0)
    expect(await outbox.discard(1, 'a')).toBeNull()

    await outbox.add(makeEntry('b', '2026-10-03T10:00:02Z'))
    let resolve: (v: ReturnType<typeof makeSale>) => void = () => undefined
    api.createOfflineSale.mockImplementationOnce(() => new Promise((r) => { resolve = r }))
    const sync = outbox.syncNow()
    const discarding = outbox.discard(1, 'b')
    resolve(makeSale({ id: 21 }))
    await sync
    expect(await discarding).toBe(21)
  })

  it('一覧を読む前でも、端末に残っている会計なら［取り消す］で消せる', async () => {
    const outbox = useOutboxStore()
    await outbox.add(makeEntry('z', '2026-10-03T10:00:01Z', 2))
    expect(await outbox.discard(2, 'z')).toBe('removed')
    expect(await kvGet('outbox:2:z')).toBeUndefined()
  })
})
