import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import type { StaffOrderInput } from '@/api/orders'
import { useOrderDraftStore } from '@/stores/orderDraft'
import { apiError } from '@/test/helpers'
import { makeOrder, makeTable } from '@/test/orders'
import { makeBootstrap } from '@/test/register'

const registerApi = vi.hoisted(() => ({ fetchBootstrap: vi.fn() }))
vi.mock('@/api/register', () => registerApi)
const tablesApi = vi.hoisted(() => ({ fetchOrderTables: vi.fn() }))
vi.mock('@/api/orderTables', () => tablesApi)
const ordersApi = vi.hoisted(() => ({ createOrder: vi.fn() }))
vi.mock('@/api/orders', () => ordersApi)

const KEY = 'regi:1:order-draft'

async function loaded() {
  setActivePinia(createPinia())
  const draft = useOrderDraftStore()
  await draft.load()
  return draft
}

function product(id: number) {
  const p = useOrderDraftStore().products.get(id)
  if (!p) throw new Error(`product ${id}`)
  return p
}

function sentInput(call = 0): StaffOrderInput {
  return ordersApi.createOrder.mock.calls[call]?.[0] as StaffOrderInput
}

describe('orderDraftStore（12 §8.4・§8.10）', () => {
  beforeEach(() => {
    localStorage.clear()
    registerApi.fetchBootstrap.mockReset().mockImplementation(() => Promise.resolve(makeBootstrap()))
    tablesApi.fetchOrderTables.mockReset().mockResolvedValue([makeTable(), makeTable({ id: 2, name: 'T2' }), makeTable({ id: 3, name: '停止', is_active: false })])
    ordersApi.createOrder.mockReset()
  })

  it('品目の追加で点数と小計の目安が変わり、テーブルは有効なものだけ選べる', async () => {
    const draft = await loaded()
    draft.add(product(1))
    draft.add(product(1))
    draft.add(product(3), [31])
    expect(draft.itemCount).toBe(3)
    expect(draft.subtotal).toBe(400 * 2 + 550)
    expect(draft.activeTables.map((t) => t.id)).toEqual([1, 2])
  })

  it('在庫管理 ON は在庫の数まで、OFF は制限しない', async () => {
    const draft = await loaded()
    for (let i = 0; i < 3; i++) draft.add(product(2))
    expect(draft.lines[0]?.quantity).toBe(2)

    await nextTick() // 前の店舗の保存が済んでから消す
    localStorage.clear()
    const base = makeBootstrap()
    registerApi.fetchBootstrap.mockResolvedValue({ ...base, store: { ...base.store, stock_enabled: false } })
    const off = await loaded()
    for (let i = 0; i < 3; i++) off.add(product(2))
    expect(off.lines[0]?.quantity).toBe(3)
  })

  it('品目・テーブル・メモを localStorage に保存し、開き直すと戻す。無効のテーブルは「テーブルなし」に戻す', async () => {
    const draft = await loaded()
    draft.add(product(1))
    draft.setMemo(draft.lines[0]?.key ?? '', '氷少なめ')
    draft.setTable(2)
    draft.setNote('急ぎ')
    await nextTick()
    expect(JSON.parse(localStorage.getItem(KEY) ?? '{}')).toMatchObject({ table_id: 2, note: '急ぎ' })

    const again = await loaded()
    expect(again.lines).toHaveLength(1)
    expect(again.lines[0]?.memo).toBe('氷少なめ')
    expect(again.tableId).toBe(2)

    localStorage.setItem(KEY, JSON.stringify({ lines: [], table_id: 3, label: '', note: '' }))
    expect((await loaded()).tableId).toBeNull()
  })

  it('壊れた保存内容は無視する', async () => {
    localStorage.setItem(KEY, '{broken')
    expect((await loaded()).lines).toEqual([])

    localStorage.setItem(KEY, JSON.stringify({ lines: [{ key: '1:', product_id: 1, option_ids: [], quantity: 1 }], table_id: 'x' }))
    const again = await loaded()
    expect(again.lines).toEqual([]) // memo の無い行は受け付けない
    expect(again.tableId).toBeNull()
  })

  it('送信：成功すると品目・メモを空にし、テーブルは選んだまま利用中にする', async () => {
    const draft = await loaded()
    draft.setTable(1)
    draft.add(product(1))
    draft.setMemo(draft.lines[0]?.key ?? '', ' 氷なし ')
    ordersApi.createOrder.mockResolvedValue(makeOrder({ order_table_id: 1 }))

    const outcome = await draft.send()
    expect(outcome.ok).toBe(true)
    expect(sentInput()).toMatchObject({
      order_table_id: 1,
      label: null,
      note: null,
      expected_subtotal: 400,
      items: [{ product_id: 1, quantity: 1, option_ids: [], memo: '氷なし' }],
    })
    expect(draft.lines).toEqual([])
    expect(draft.tableId).toBe(1)
    expect(draft.tables.find((t) => t.id === 1)?.opened_at).not.toBeNull()
    expect(draft.pendingUuid).toBeNull()
  })

  it('テーブルなしのときだけ呼び名を送る', async () => {
    const draft = await loaded()
    draft.add(product(1))
    draft.setLabel('窓側の 2 名')
    ordersApi.createOrder.mockResolvedValue(makeOrder({ order_table_id: null, table_name: null, label: '窓側の 2 名' }))
    await draft.send()
    expect(sentInput()).toMatchObject({ order_table_id: null, label: '窓側の 2 名' })
  })

  it('AC-S13-2：通信断は内容を残し、送り直しは同じ client_uuid。内容を変えたら新しい UUID', async () => {
    const draft = await loaded()
    draft.add(product(1))
    ordersApi.createOrder.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))

    expect((await draft.send()).ok).toBe(false)
    expect(draft.lines).toHaveLength(1)
    await draft.send()
    expect(sentInput(1).client_uuid).toBe(sentInput(0).client_uuid)

    draft.add(product(1))
    await draft.send()
    expect(sentInput(2).client_uuid).not.toBe(sentInput(0).client_uuid)
  })

  it('在庫不足（409）は不足の商品を出して商品を読み直す', async () => {
    const draft = await loaded()
    draft.add(product(2))
    ordersApi.createOrder.mockRejectedValue(apiError(409, {
      message: '在庫が足りません',
      code: 'OUT_OF_STOCK',
      details: { shortages: [{ product_id: 2, product_name: 'ケーキ', requested: 1, stock_qty: 0 }] },
    }))
    const outcome = await draft.send()
    expect(outcome.ok).toBe(false)
    expect(outcome.ok ? '' : outcome.message).toContain('ケーキ')
    expect(registerApi.fetchBootstrap).toHaveBeenCalledTimes(2)
  })

  it('無効になったテーブル（422 order_table_id）は UUID を捨て、テーブルなしに戻す', async () => {
    const draft = await loaded()
    draft.setTable(2)
    draft.add(product(1))
    ordersApi.createOrder.mockRejectedValue(apiError(422, { message: '入力内容を確認してください', errors: { order_table_id: ['このテーブルは使えません'] } }))
    tablesApi.fetchOrderTables.mockResolvedValue([makeTable()])

    const outcome = await draft.send()
    expect(outcome).toEqual({ ok: false, message: 'このテーブルは使えません' })
    expect(draft.pendingUuid).toBeNull()
    expect(draft.tableId).toBeNull()
    expect(draft.lines).toHaveLength(1)
  })
})
