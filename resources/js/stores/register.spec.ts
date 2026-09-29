import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import type { SaleInput } from '@/api/register'
import { HELD_MAX, useRegisterStore } from '@/stores/register'
import { apiError } from '@/test/helpers'
import { makeOrder, makeOrderItem } from '@/test/orders'
import { makeBootstrap, makeProduct, makeSale } from '@/test/register'

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  createSale: vi.fn(),
  fetchSale: vi.fn(),
  cancelSale: vi.fn(),
}))
vi.mock('@/api/register', () => api)

const KEY = 'regi:1:register'
const EXTRA = { received: 1000, customer_count: null, memo: null }

async function loaded() {
  setActivePinia(createPinia())
  const register = useRegisterStore()
  await register.load()
  return register
}

function product(id: number) {
  const p = useRegisterStore().products.get(id)
  if (!p) throw new Error(`product ${id}`)
  return p
}

function networkError(): AxiosError {
  return new AxiosError('Network Error', 'ERR_NETWORK')
}

function sentInput(call = 0): SaleInput {
  return api.createSale.mock.calls[call]?.[0] as SaleInput
}

describe('registerStore（08 §7.2）', () => {
  beforeEach(() => {
    localStorage.clear()
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchBootstrap.mockImplementation(() => Promise.resolve(makeBootstrap()))
  })

  it('既定の税区分と現金が選ばれ、商品の追加で合計と内消費税が変わる（通信しない）', async () => {
    const register = await loaded()
    expect(register.taxTypeId).toBe(1)
    expect(register.paymentMethodId).toBe(1)

    register.add(product(1))
    register.add(product(2))
    expect(register.amounts).toMatchObject({ subtotal: 780, total: 780, tax_amount: 70 })
    expect(register.itemCount).toBe(2)

    register.setTaxType(2) // 税込モードは合計が変わらず、税額が変わる
    expect(register.amounts).toMatchObject({ total: 780, tax_amount: 57 })
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(1)
    expect(api.createSale).not.toHaveBeenCalled()
  })

  it('値引き（率）を合計に反映する', async () => {
    const register = await loaded()
    register.add(product(1))
    register.setDiscount({ type: 'percent', value: 10 })
    expect(register.amounts).toMatchObject({ subtotal: 400, discount_amount: 40, total: 360 })
  })

  it('AC-S02-11：注文を localStorage に保存し、開き直すと復元する', async () => {
    const first = await loaded()
    first.add(product(1))
    first.add(product(1))
    first.setTaxType(2)
    await nextTick()
    expect(JSON.parse(localStorage.getItem(KEY) ?? '{}')).toMatchObject({ tax_type_id: 2 })

    const again = await loaded()
    expect(again.lines).toEqual([{ key: '1:', product_id: 1, option_ids: [], quantity: 2 }])
    expect(again.taxTypeId).toBe(2)
  })

  it('壊れた保存値は捨て、販売を終えた商品は注文から外して知らせる', async () => {
    localStorage.setItem(KEY, JSON.stringify({ tax_type_id: 99, lines: [{ key: '9:', product_id: 9, option_ids: [], quantity: 1 }, { key: '1:', product_id: 1, option_ids: [], quantity: 1 }], discount: 'x', held: [{}] }))
    const register = await loaded()
    expect(register.lines.map((l) => l.key)).toEqual(['1:'])
    expect(register.taxTypeId).toBe(1)
    expect(register.discount).toBeNull()
    expect(register.held).toEqual([])

    localStorage.setItem(KEY, '{broken')
    const again = await loaded()
    expect(again.lines).toEqual([])
  })

  it('AC-S02-12：保留 → 別の注文 → 呼び出しで戻る。11 件目は拒否', async () => {
    const register = await loaded()
    register.add(product(1))
    register.setDiscount({ type: 'amount', value: 50 })
    expect(register.hold()).toBe(true)
    expect(register.lines).toEqual([])
    expect(register.discount).toBeNull()

    register.add(product(3), [31])
    register.clearOrder()
    register.recall(0)
    expect(register.lines.map((l) => l.key)).toEqual(['1:'])
    expect(register.discount).toEqual({ type: 'amount', value: 50 })
    expect(register.held).toEqual([])

    for (let i = 0; i < HELD_MAX; i++) {
      register.add(product(1))
      expect(register.hold()).toBe(true)
    }
    register.add(product(1))
    expect(register.hold()).toBe(false)
    expect(register.notice?.text).toBe('保留は 10 件までです')
    expect(register.held).toHaveLength(HELD_MAX)
  })

  it('AC-S02-5：確定で合計・預かり・client_uuid を送り、注文を空にして在庫表示を減らす', async () => {
    const register = await loaded()
    register.add(product(1))
    register.add(product(2))
    register.startCheckout()
    const uuid = register.pendingUuid
    api.createSale.mockResolvedValue(makeSale())

    const outcome = await register.confirm({ received: 1000, customer_count: 2, memo: 'テイクアウト' })
    expect(outcome).toMatchObject({ ok: true })
    expect(sentInput()).toMatchObject({
      client_uuid: uuid, tax_type_id: 1, payment_method_id: 1, expected_total: 780, received: 1000,
      customer_count: 2, memo: 'テイクアウト', discount: null,
      items: [{ product_id: 1, quantity: 1, option_ids: [] }, { product_id: 2, quantity: 1, option_ids: [] }],
    })
    expect(register.lines).toEqual([])
    expect(register.pendingUuid).toBeNull()
    expect(register.lastSale?.id).toBe(501)
    expect(product(2).stock_qty).toBe(1)
  })

  it('現金以外は預かり金を送らない', async () => {
    const register = await loaded()
    register.add(product(1))
    register.setPaymentMethod(2)
    api.createSale.mockResolvedValue(makeSale())
    await register.confirm(EXTRA)
    expect(sentInput()).toMatchObject({ payment_method_id: 2, received: null })
  })

  it('AC-S02-7：通信断では注文とダイアログを残し、押し直しは同じ client_uuid。支払方法を変えても同じ', async () => {
    const register = await loaded()
    register.add(product(1))
    register.startCheckout()
    api.createSale.mockRejectedValueOnce(networkError()).mockResolvedValueOnce(makeSale())

    const failed = await register.confirm(EXTRA)
    expect(failed).toEqual({ ok: false, message: '通信できません。会計は保存されていません', closeDialog: false })
    expect(register.lines).toHaveLength(1)

    register.setPaymentMethod(2)
    await register.confirm(EXTRA)
    expect(sentInput(1).client_uuid).toBe(sentInput(0).client_uuid)
  })

  it('注文を変えたら client_uuid を作り直す', async () => {
    const register = await loaded()
    register.add(product(1))
    register.startCheckout()
    const first = register.pendingUuid
    register.add(product(1))
    expect(register.pendingUuid).toBeNull()
    register.startCheckout()
    expect(register.pendingUuid).not.toBe(first)
  })

  it('AC-S02-8：409 OUT_OF_STOCK は不足の商品名と残りを出し、bootstrap を取り直す', async () => {
    const register = await loaded()
    register.add(product(2))
    register.add(product(2))
    api.createSale.mockRejectedValue(apiError(409, {
      message: '在庫が足りません', code: 'OUT_OF_STOCK',
      details: { shortages: [{ product_id: 2, product_name: 'ケーキ', stock_qty: 1, requested: 2 }] },
    }))
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({ products: [makeProduct(2, 'ケーキ', { price: 380, track_stock: true, stock_qty: 1 })] }))

    const outcome = await register.confirm(EXTRA)
    expect(outcome).toEqual({ ok: false, message: '在庫が足りません：ケーキ（残り 1）', closeDialog: true })
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(2)
    expect(product(2).stock_qty).toBe(1)
    expect(register.lines).toHaveLength(1)
  })

  it('AC-S02-9：422 ITEM_UNAVAILABLE は取り直して使えない商品を外し、次は新しい client_uuid', async () => {
    const register = await loaded()
    register.add(product(1))
    register.add(product(3))
    register.startCheckout()
    const first = register.pendingUuid
    api.createSale.mockRejectedValue(apiError(422, { message: '販売していない商品が含まれています', code: 'ITEM_UNAVAILABLE' }))
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({ products: [makeProduct(1, 'コーヒー')] }))

    const outcome = await register.confirm(EXTRA)
    expect(outcome).toMatchObject({ ok: false, closeDialog: true })
    expect(outcome.ok ? '' : outcome.message).toBe('商品情報が更新されました。内容を確認してください\n販売を終えた商品を注文から外しました：ラテ')
    expect(register.lines.map((l) => l.key)).toEqual(['1:'])
    expect(register.pendingUuid).toBeNull()
    register.startCheckout()
    expect(register.pendingUuid).not.toBe(first)
  })

  it('422 の入力エラーはダイアログに最初の項目のメッセージを出す', async () => {
    const register = await loaded()
    register.add(product(1))
    api.createSale.mockRejectedValue(apiError(422, { message: '入力に誤りがあります', code: 'VALIDATION', errors: { memo: ['メモは 200 文字以内です'] } }))
    expect(await register.confirm(EXTRA)).toEqual({ ok: false, message: 'メモは 200 文字以内です', closeDialog: false })
  })

  it('AC-S02-10：直前の会計を取り消し、bootstrap を取り直す', async () => {
    const register = await loaded()
    register.add(product(2))
    api.createSale.mockResolvedValue(makeSale())
    await register.confirm(EXTRA)
    api.cancelSale.mockResolvedValue(makeSale({ status: 'cancelled' }))

    expect(await register.undoLastSale()).toBe(true)
    expect(api.cancelSale).toHaveBeenCalledWith(501)
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(2)
    expect(register.lastSale).toBeNull()
    expect(register.notice?.kind).toBe('info')
  })

  it('取消に失敗したら知らせる', async () => {
    const register = await loaded()
    register.add(product(1))
    api.createSale.mockResolvedValue(makeSale())
    await register.confirm(EXTRA)
    api.cancelSale.mockRejectedValue(apiError(422, { message: 'x', code: 'CANCEL_NOT_ALLOWED' }))
    expect(await register.undoLastSale()).toBe(false)
    expect(register.notice).toEqual({ kind: 'error', text: '取り消せませんでした。売上確認の画面から取り消してください' })
  })

  it('別の店舗で読み込んだら前の店舗の注文を持ち越さない', async () => {
    const register = await loaded()
    register.add(product(1))
    api.fetchBootstrap.mockResolvedValue(makeBootstrap({ store: { id: 2, name: 'B', price_mode: 'tax_included', rounding: 'floor', day_cutoff_time: '00:00', stock_enabled: true } }))
    await register.load()
    expect(register.lines).toEqual([])
  })

  describe('注文から会計（12 §8.6）', () => {
    // T1：コーヒー ×2 とラテ（ショット）×1、T2：コーヒー ×1
    const T1 = makeOrder({ id: 101, order_no: 1, items: [
      makeOrderItem({ product_id: 1, quantity: 2 }),
      makeOrderItem({ id: 1002, product_id: 3, product_name: 'ラテ', quantity: 1, options: [{ product_option_id: 31, option_name: 'ショット', price: 50 }] }),
    ] })
    const T2 = makeOrder({ id: 102, order_no: 2, order_table_id: 2, table_name: 'T2', items: [makeOrderItem({ product_id: 1, quantity: 1 })] })

    it('注文の品目をカートに足し、確定で order_ids を送る。成功したら外す', async () => {
      const register = await loaded()
      register.add(product(1))
      expect(register.addOrders([T1, T2])).toBe(true)
      expect(register.lines).toEqual([
        { key: '1:', product_id: 1, option_ids: [], quantity: 4 },
        { key: '3:31', product_id: 3, option_ids: [31], quantity: 1 },
      ])
      expect(register.orderIds).toEqual([101, 102])
      expect(register.notice).toEqual({ kind: 'info', text: '注文 2 件をカートに入れました' })
      expect(register.addOrders([T1])).toBe(false) // 入っている注文は足さない
      expect(register.lines[0]?.quantity).toBe(4)

      api.createSale.mockResolvedValue(makeSale())
      await register.confirm(EXTRA)
      expect(sentInput().order_ids).toEqual([101, 102])
      expect(register.orderIds).toEqual([])
    })

    it('注文が無ければ order_ids は空で送る', async () => {
      const register = await loaded()
      register.add(product(1))
      api.createSale.mockResolvedValue(makeSale())
      await register.confirm(EXTRA)
      expect(sentInput().order_ids).toEqual([])
    })

    it('販売を終えた商品の品目は足さずに名前を出す', async () => {
      api.fetchBootstrap.mockResolvedValue(makeBootstrap({ products: [makeProduct(1, 'コーヒー')] }))
      const register = await loaded()
      register.addOrders([T1])
      expect(register.lines.map((l) => l.key)).toEqual(['1:'])
      expect(register.orderIds).toEqual([101])
      expect(register.notice).toEqual({ kind: 'error', text: '販売を終えた商品は入れませんでした：ラテ' })
    })

    it('1 回の会計に入れられる注文は 20 件まで', async () => {
      const register = await loaded()
      const many = Array.from({ length: 21 }, (_, i) => makeOrder({ id: 200 + i, order_no: i + 1 }))
      expect(register.addOrders(many)).toBe(false)
      expect(register.orderIds).toEqual([])
      expect(register.notice?.text).toBe('1 回の会計に入れられる注文は 20 件までです')
    })

    it('外すとその注文で足した数量を引く。クリアでも外れる', async () => {
      const register = await loaded()
      register.add(product(1))
      register.addOrders([T1, T2])
      register.removeOrders([101])
      expect(register.lines).toEqual([{ key: '1:', product_id: 1, option_ids: [], quantity: 2 }])
      expect(register.orderIds).toEqual([102])
      register.clearOrder()
      expect(register.orderIds).toEqual([])
    })

    it('保留・呼び出し・開き直しで注文を持ち越す', async () => {
      const register = await loaded()
      register.addOrders([T1])
      register.hold()
      expect(register.orderIds).toEqual([])
      register.recall(0)
      expect(register.orderIds).toEqual([101])
      await nextTick()
      const again = await loaded()
      expect(again.orderIds).toEqual([101])
    })

    it('AC-S02-17：409 ORDER_ALREADY_PAID は「すでに会計されています」と該当の注文を返す', async () => {
      const register = await loaded()
      register.addOrders([T1, T2])
      register.startCheckout()
      api.createSale.mockRejectedValue(apiError(409, { message: '会計済みの注文が含まれています。画面を更新してください', code: 'ORDER_ALREADY_PAID', details: { order_ids: [102, 999] } }))
      const outcome = await register.confirm(EXTRA)
      expect(outcome).toEqual({
        ok: false,
        message: 'この注文はすでに会計されています',
        closeDialog: true,
        staleOrders: { ids: [102], message: 'この注文はすでに会計されています' },
      })
      expect(register.pendingUuid).toBeNull()
      expect(register.orderIds).toEqual([101, 102]) // 外すかは画面で選ぶ
    })

    it('422 の見つからない注文もサーバーのメッセージで該当の注文を返す', async () => {
      const register = await loaded()
      register.addOrders([T1])
      api.createSale.mockRejectedValue(apiError(422, { message: '見つからない注文があります。画面を更新してください', code: 'ITEM_UNAVAILABLE', details: { order_ids: [101] } }))
      const outcome = await register.confirm(EXTRA)
      expect(outcome.ok ? null : outcome.staleOrders).toEqual({ ids: [101], message: '見つからない注文があります。画面を更新してください' })
    })
  })
})
