import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { STORAGE_KEY, TOTAL_QUANTITY_MAX, useTableOrderStore } from '@/stores/tableOrder'
import { apiError } from '@/test/helpers'
import type { PublicMenu, PublicMenuProduct, PublicOrder } from '@/types/api'

const api = vi.hoisted(() => ({ fetchMenu: vi.fn(), createCustomerOrder: vi.fn(), fetchCustomerOrders: vi.fn() }))
vi.mock('@/api/publicTable', () => api)

const TOKEN = 'tokenAAAA-0123456789abcdefghijklmnop'
const OTHER = 'tokenBBBB-0123456789abcdefghijklmnop'

function product(extra: Partial<PublicMenuProduct> = {}): PublicMenuProduct {
  return { id: 1, category_id: 10, name: 'コーヒー', memo: null, price: 400, color: 'blue', sold_out: false, options: [{ id: 5, name: '大盛り', price: 100 }], ...extra }
}

function menu(products: PublicMenuProduct[] = [product(), product({ id: 2, name: 'ケーキ', price: 500, options: [] })]): PublicMenu {
  return {
    store_name: 'テスト店 A',
    table_name: 'T1',
    price_mode: 'tax_included',
    accepting: true,
    not_accepting_reason: null,
    categories: [{ id: 10, name: 'ドリンク' }],
    products,
    limits: { max_items: 30, max_quantity: 20, max_orders_per_session: 20 },
  }
}

const ORDER: PublicOrder = { order_no: 3, status: 'active', created_at: '2026-09-30T12:00:00+09:00', subtotal: 1000, items: [] }

async function opened() {
  const store = useTableOrderStore()
  await store.open(TOKEN)
  return store
}

function coffee(store: ReturnType<typeof useTableOrderStore>) {
  const p = store.products.get(1)
  if (!p) throw new Error('no product')
  return p
}

describe('tableOrder ストア（C01）', () => {
  beforeEach(() => {
    sessionStorage.clear()
    setActivePinia(createPinia())
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchMenu.mockResolvedValue(menu())
  })

  it('同じ商品・オプション・メモは 1 行にまとめ、目安の小計は（価格 + オプション）× 数量', async () => {
    const store = await opened()
    store.add(coffee(store), [5], 1, '')
    store.add(coffee(store), [5], 2, ' ')
    store.add(coffee(store), [], 1, '')
    expect(store.lines).toHaveLength(2)
    expect(store.lines[0]?.quantity).toBe(3)
    expect(store.subtotal).toBe(500 * 3 + 400)
    expect(store.itemCount).toBe(4)
  })

  it('上限：品目数・数量の合計・1 行の数量', async () => {
    api.fetchMenu.mockResolvedValue({ ...menu(), limits: { max_items: 1, max_quantity: 20, max_orders_per_session: 20 } })
    const store = await opened()
    expect(store.add(coffee(store), [], 25, '')).toBeNull()
    expect(store.lines[0]?.quantity).toBe(20)
    expect(store.add(coffee(store), [5], 1, '')).toBe('一度に注文できるのは 1 品目までです')
    store.setQuantity(store.lines[0]?.key ?? '', 99)
    expect(store.lines[0]?.quantity).toBe(20)
    expect(store.add(coffee(store), [], TOTAL_QUANTITY_MAX, '')).toBe('一度に注文できる数量は合計 50 までです')
  })

  it('カートは sessionStorage に残り、同じ QR で開き直すと戻る。別のテーブルの QR では捨てる', async () => {
    let store = await opened()
    store.add(coffee(store), [5], 2, '氷なし')
    store.setNote('取り皿 2 枚')
    await nextTick()
    const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '{}') as Record<string, unknown>
    expect(saved.t).toBe('tokenAAA') // トークン全体は保存しない
    expect(JSON.stringify(saved)).not.toContain(TOKEN)

    setActivePinia(createPinia())
    store = await opened()
    expect(store.lines).toHaveLength(1)
    expect(store.note).toBe('取り皿 2 枚')

    setActivePinia(createPinia())
    store = useTableOrderStore()
    await store.open(OTHER)
    expect(store.lines).toHaveLength(0)
    expect(store.note).toBe('')
  })

  it('AC-C01-3：開き直したとき売切・販売終了になった行は外す', async () => {
    let store = await opened()
    store.add(coffee(store), [], 1, '')
    store.add(store.products.get(2) ?? product(), [], 1, '')
    await nextTick()
    api.fetchMenu.mockResolvedValue(menu([product({ sold_out: true })]))
    setActivePinia(createPinia())
    store = await opened()
    expect(store.lines).toHaveLength(0)
  })

  it('AC-C01-2：通信断の再送は同じ client_uuid。成功したらカートを空にする', async () => {
    const store = await opened()
    store.add(coffee(store), [5], 2, '氷なし')
    api.createCustomerOrder.mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
    const first = await store.send()
    expect(first).toEqual({ ok: false, message: '通信できません。つながってからもう一度お試しください' })
    expect(store.lines).toHaveLength(1)

    api.createCustomerOrder.mockResolvedValueOnce(ORDER)
    const second = await store.send()
    expect(second.ok).toBe(true)
    const [call1, call2] = api.createCustomerOrder.mock.calls
    expect(call1?.[0]).toBe(TOKEN)
    expect(call1?.[1]).toEqual({
      client_uuid: expect.any(String),
      items: [{ product_id: 1, quantity: 2, option_ids: [5], memo: '氷なし' }],
      note: null,
      expected_subtotal: 1000,
    })
    expect((call2?.[1] as { client_uuid: string }).client_uuid).toBe((call1?.[1] as { client_uuid: string }).client_uuid)
    expect(store.lines).toHaveLength(0)
    expect(store.pendingUuid).toBeNull()
    expect(store.lastOrder).toEqual(ORDER)
  })

  it('AC-C01-2：送信中の二度押しは送らない', async () => {
    const store = await opened()
    store.add(coffee(store), [], 1, '')
    let resolve: (o: PublicOrder) => void = () => {}
    api.createCustomerOrder.mockReturnValueOnce(new Promise<PublicOrder>((r) => { resolve = r }))
    const a = store.send()
    const b = await store.send()
    expect(b).toEqual({ ok: false, message: '' })
    resolve(ORDER)
    await a
    expect(api.createCustomerOrder).toHaveBeenCalledTimes(1)
  })

  it('内容を変えたら新しい client_uuid にする', async () => {
    const store = await opened()
    store.add(coffee(store), [], 1, '')
    api.createCustomerOrder.mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
    await store.send()
    const uuid = store.pendingUuid
    expect(uuid).not.toBeNull()
    store.setQuantity(store.lines[0]?.key ?? '', 2)
    expect(store.pendingUuid).toBeNull()
  })

  it('429 は待つ秒数を出し、UUID を残す', async () => {
    const store = await opened()
    store.add(coffee(store), [], 1, '')
    api.createCustomerOrder.mockRejectedValueOnce(apiError(429, { message: 'Too Many Attempts.' }, { 'retry-after': '30' }))
    const out = await store.send()
    expect(out).toEqual({ ok: false, message: 'しばらく待ってからもう一度お試しください（30 秒）' })
    expect(store.pendingUuid).not.toBeNull()
  })

  it('売切（OUT_OF_STOCK）はメニューを取り直し、該当の行を外して名前を出す', async () => {
    const store = await opened()
    store.add(coffee(store), [], 1, '')
    store.add(store.products.get(2) ?? product(), [], 1, '')
    api.createCustomerOrder.mockRejectedValueOnce(apiError(409, { message: '売り切れの商品があります', code: 'OUT_OF_STOCK', details: { product_ids: [2] } }))
    const out = await store.send()
    expect(out.ok).toBe(false)
    expect(!out.ok && out.message).toBe('売り切れの商品があります\n販売が終わった・売切になったため外しました：ケーキ')
    expect(store.lines.map((l) => l.product_id)).toEqual([1])
    expect(store.pendingUuid).toBeNull()
    expect(api.fetchMenu).toHaveBeenCalledTimes(2)
  })

  it('トークンが無効（404）は invalid にする', async () => {
    api.fetchMenu.mockRejectedValue(apiError(404, { message: 'Not Found' }))
    const store = await opened()
    expect(store.loadState).toBe('invalid')
    expect(store.menu).toBeNull()
  })
})
