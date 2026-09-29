import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { cancelSale, createSale, fetchBootstrap, type RegisterBootstrap, type StockShortage } from '@/api/register'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { addOne, canAddOne, isCartLines, lineKey, MAX_LINES, reconcile, removeOne, toPricingItems, type CartLine } from '@/lib/cart'
import { loadDeviceName } from '@/lib/deviceName'
import { nameWithMemo } from '@/lib/productLabel'
import { calculateAmounts, PricingError, type PricingAmounts } from '@/lib/pricing'
import { uuidV4 } from '@/lib/uuid'
import type { DiscountType, Product, Sale } from '@/types/api'

export interface Discount {
  type: DiscountType
  value: number
}

/** 保留中の注文（08 §5.3 A 案。端末の localStorage に最大 10 件） */
export interface HeldOrder {
  tax_type_id: number | null
  lines: CartLine[]
  discount: Discount | null
  held_at: string // ISO 8601
}

export const HELD_MAX = 10

/** 会計の確定の結果。失敗時の closeDialog は「ダイアログを閉じて注文を直してもらう」エラーか */
export type ConfirmOutcome =
  | { ok: true; sale: Sale }
  | { ok: false; message: string; closeDialog: boolean }

export interface ConfirmExtra {
  received: number | null
  customer_count: number | null
  memo: string | null
}

type Notice = { kind: 'error' | 'info'; text: string }

function isDiscount(v: unknown): v is Discount {
  if (typeof v !== 'object' || v === null) return false
  const r = v as Record<string, unknown>
  return (r.type === 'amount' || r.type === 'percent') && Number.isSafeInteger(r.value) && (r.value as number) > 0
}

function isHeldOrder(v: unknown): v is HeldOrder {
  if (typeof v !== 'object' || v === null) return false
  const r = v as Record<string, unknown>
  return (r.tax_type_id === null || Number.isSafeInteger(r.tax_type_id))
    && isCartLines(r.lines)
    && (r.discount === null || isDiscount(r.discount))
    && typeof r.held_at === 'string'
}

/** S02 会計の状態（08 §7.2）。金額は lib/pricing.ts で計算し、画面では計算しない */
export const useRegisterStore = defineStore('register', () => {
  const bootstrap = ref<RegisterBootstrap | null>(null)
  const loading = ref(false)
  const loadError = ref<string | null>(null)

  const taxTypeId = ref<number | null>(null)
  const lines = ref<CartLine[]>([])
  const discount = ref<Discount | null>(null)
  const paymentMethodId = ref<number | null>(null)
  /** お会計ダイアログを開いたときに作った client_uuid。確定するか注文を変えたら破棄する */
  const pendingUuid = ref<string | null>(null)
  const held = ref<HeldOrder[]>([])
  /** 直前に確定した会計（5 秒間の取消用） */
  const lastSale = ref<Sale | null>(null)
  const submitting = ref(false)
  const notice = ref<Notice | null>(null)
  /** localStorage に保存できているか */
  const storageOk = ref(true)
  /** 注文を復元した店舗。復元が済むまでは保存しない（空の注文で上書きしないため） */
  let restoredFor: number | null = null

  const products = computed(() => new Map((bootstrap.value?.products ?? []).map((p) => [p.id, p])))
  const taxType = computed(() => bootstrap.value?.tax_types.find((t) => t.id === taxTypeId.value) ?? null)
  const paymentMethod = computed(() => bootstrap.value?.payment_methods.find((m) => m.id === paymentMethodId.value) ?? null)
  const itemCount = computed(() => lines.value.reduce((sum, l) => sum + l.quantity, 0))

  const pricing = computed<{ amounts: PricingAmounts | null; error: boolean }>(() => {
    const b = bootstrap.value
    const t = taxType.value
    if (!b || !t) return { amounts: null, error: false }
    try {
      return {
        amounts: calculateAmounts({
          price_mode: b.store.price_mode,
          rounding: b.store.rounding,
          tax_rate_permille: t.rate_permille,
          items: toPricingItems(lines.value, products.value),
          discount: discount.value,
        }),
        error: false,
      }
    } catch (err) {
      if (err instanceof PricingError) return { amounts: null, error: true }
      throw err
    }
  })
  const amounts = computed(() => pricing.value.amounts)
  const pricingError = computed(() => pricing.value.error)
  const canCheckout = computed(() => lines.value.length > 0 && amounts.value !== null && paymentMethod.value !== null)

  // ─── 端末への保存（08 §7.2：lines・discount・taxTypeId・held。localStorage が使えなければ保存せずに動く） ───

  function storageKey(): string | null {
    const id = bootstrap.value?.store.id
    return id === undefined ? null : `regi:${id}:register`
  }

  function persist(): void {
    const key = storageKey()
    if (key === null || restoredFor !== bootstrap.value?.store.id) return
    try {
      localStorage.setItem(key, JSON.stringify({
        tax_type_id: taxTypeId.value,
        lines: lines.value,
        discount: discount.value,
        held: held.value,
      }))
      storageOk.value = true
    } catch {
      storageOk.value = false
    }
  }

  function restore(): void {
    const key = storageKey()
    if (key === null) return
    let saved: unknown = null
    try {
      const raw = localStorage.getItem(key)
      saved = raw === null ? null : JSON.parse(raw)
    } catch {
      storageOk.value = false // 使えない、または壊れた値
    }
    if (typeof saved !== 'object' || saved === null) return
    const r = saved as Record<string, unknown>
    if (Number.isSafeInteger(r.tax_type_id)) taxTypeId.value = r.tax_type_id as number
    if (isCartLines(r.lines)) lines.value = r.lines
    if (r.discount === null || isDiscount(r.discount)) discount.value = r.discount
    if (Array.isArray(r.held)) held.value = r.held.filter(isHeldOrder).slice(0, HELD_MAX)
  }

  watch([taxTypeId, lines, discount, held], persist, { deep: true })

  // ─── マスタ ───

  function defaultTaxTypeId(): number | null {
    const types = bootstrap.value?.tax_types ?? []
    return (types.find((t) => t.is_default) ?? types[0])?.id ?? null
  }

  function defaultPaymentMethodId(): number | null {
    const methods = bootstrap.value?.payment_methods ?? []
    return (methods.find((m) => m.is_cash) ?? methods[0])?.id ?? null // 現金が既定（08 §5.3）
  }

  /** 読み込んだマスタに選択と注文を合わせる。外した商品名を返す */
  function applyMasters(previous: ReadonlyMap<number, Product>): string[] {
    const b = bootstrap.value
    if (!b) return []
    if (!b.tax_types.some((t) => t.id === taxTypeId.value)) taxTypeId.value = defaultTaxTypeId()
    if (!b.payment_methods.some((m) => m.id === paymentMethodId.value)) paymentMethodId.value = defaultPaymentMethodId()
    const result = reconcile(lines.value, products.value, previous)
    if (result.lines.length !== lines.value.length) {
      lines.value = result.lines
      pendingUuid.value = null
    }
    return result.removed
  }

  /** GET /register/bootstrap。初回は端末に保存した注文を復元する。quiet は画面を「読み込み中」にしない */
  async function load(options: { quiet?: boolean } = {}): Promise<string[]> {
    if (!options.quiet) loading.value = true
    loadError.value = null
    try {
      const previous = products.value
      const data = await fetchBootstrap()
      // 店舗の在庫管理が OFF（12 §6.6）：どの商品も在庫管理 OFF として扱い、売切・残数・在庫による数量の制限を出さない
      bootstrap.value = data.store.stock_enabled ? data : { ...data, products: data.products.map((p) => ({ ...p, track_stock: false })) }
      if (restoredFor !== data.store.id) {
        // 別の店舗でログインし直した：前の店舗の注文を持ち越さない
        taxTypeId.value = null
        lines.value = []
        discount.value = null
        held.value = []
        pendingUuid.value = null
        lastSale.value = null
        restore()
        restoredFor = data.store.id
      }
      const removed = applyMasters(previous)
      if (removed.length > 0) notice.value = { kind: 'error', text: fmt(ja.register.removedItems, { names: removed.join('、') }) }
      persist()
      return removed
    } catch (err) {
      if (!options.quiet) loadError.value = isNetworkError(err) ? ja.register.loadFailed : (errorBody(err)?.message ?? ja.register.loadFailed)
      return []
    } finally {
      loading.value = false
    }
  }

  // ─── 注文の操作（通信しない。AC-S02-1） ───

  function orderChanged(): void {
    pendingUuid.value = null
  }

  function add(product: Product, optionIds: readonly number[] = []): void {
    const key = lineKey(product.id, optionIds)
    if (!lines.value.some((l) => l.key === key) && lines.value.length >= MAX_LINES) {
      notice.value = { kind: 'error', text: ja.register.lineTooLong }
      return
    }
    if (!canAddOne(lines.value, product, key)) return
    lines.value = addOne(lines.value, product, optionIds)
    orderChanged()
  }

  function increment(key: string): void {
    const line = lines.value.find((l) => l.key === key)
    const product = line ? products.value.get(line.product_id) : undefined
    if (line && product) add(product, line.option_ids)
  }

  function decrement(key: string): void {
    lines.value = removeOne(lines.value, key)
    orderChanged()
  }

  function removeLine(key: string): void {
    lines.value = lines.value.filter((l) => l.key !== key)
    orderChanged()
  }

  function clearOrder(): void {
    lines.value = []
    discount.value = null
    orderChanged()
  }

  function setDiscount(value: Discount | null): void {
    discount.value = value
    orderChanged()
  }

  function setTaxType(id: number): void {
    if (taxTypeId.value === id) return
    taxTypeId.value = id
    orderChanged()
  }

  /** 支払方法は注文の内容ではないため client_uuid を作り直さない（通信断の後に変えても二重に作らない） */
  function setPaymentMethod(id: number): void {
    paymentMethodId.value = id
  }

  // ─── 保留（A 案） ───

  function hold(): boolean {
    if (lines.value.length === 0) return false
    if (held.value.length >= HELD_MAX) {
      notice.value = { kind: 'error', text: ja.register.heldFull }
      return false
    }
    held.value = [...held.value, {
      tax_type_id: taxTypeId.value,
      lines: lines.value,
      discount: discount.value,
      held_at: new Date().toISOString(),
    }]
    clearOrder()
    notice.value = { kind: 'info', text: ja.register.heldSaved }
    return true
  }

  /** 保留を呼び出して今の注文と入れ替える（今の注文は消える。確認は画面で行う） */
  function recall(index: number): void {
    const order = held.value[index]
    if (!order) return
    held.value = held.value.filter((_, i) => i !== index)
    const result = reconcile(order.lines, products.value)
    lines.value = result.lines
    discount.value = order.discount
    if (bootstrap.value?.tax_types.some((t) => t.id === order.tax_type_id)) taxTypeId.value = order.tax_type_id
    orderChanged()
    notice.value = result.removed.length > 0
      ? { kind: 'error', text: fmt(ja.register.removedItems, { names: result.removed.join('、') }) }
      : null
  }

  function deleteHeld(index: number): void {
    held.value = held.value.filter((_, i) => i !== index)
  }

  // ─── お会計 ───

  /** ［お会計へ］：この会計の client_uuid を作る（通信断の後の押し直しでは同じものを使う） */
  function startCheckout(): void {
    pendingUuid.value ??= uuidV4()
  }

  /** 確定した分を手元の在庫表示から引く（次の bootstrap まで。最終判定はサーバーの 409） */
  function deductStock(sold: readonly CartLine[]): void {
    for (const line of sold) {
      const product = bootstrap.value?.products.find((p) => p.id === line.product_id)
      if (product?.track_stock) product.stock_qty = Math.max(0, product.stock_qty - line.quantity)
    }
  }

  async function confirm(extra: ConfirmExtra): Promise<ConfirmOutcome> {
    const tax = taxType.value
    const pay = paymentMethod.value
    const total = amounts.value?.total
    if (submitting.value || !tax || !pay || total === undefined || lines.value.length === 0) {
      return { ok: false, message: ja.register.pricingError, closeDialog: false }
    }
    startCheckout()
    const uuid = pendingUuid.value ?? uuidV4()
    const sold = lines.value

    submitting.value = true
    try {
      const sale = await createSale({
        client_uuid: uuid,
        tax_type_id: tax.id,
        payment_method_id: pay.id,
        items: sold.map((l) => ({ product_id: l.product_id, quantity: l.quantity, option_ids: l.option_ids })),
        discount: discount.value,
        received: pay.is_cash ? extra.received : null,
        customer_count: extra.customer_count,
        memo: extra.memo,
        device_name: loadDeviceName() || null,
        expected_total: total,
      })
      deductStock(sold)
      lastSale.value = sale
      lines.value = []
      discount.value = null
      pendingUuid.value = null
      paymentMethodId.value = defaultPaymentMethodId()
      notice.value = null
      return { ok: true, sale }
    } catch (err) {
      return await confirmFailed(err)
    } finally {
      submitting.value = false
    }
  }

  async function confirmFailed(err: unknown): Promise<ConfirmOutcome> {
    // 通信できない：注文とダイアログを残し、同じ client_uuid で押し直してもらう（AC-S02-7）
    if (isNetworkError(err)) return { ok: false, message: ja.error.network, closeDialog: false }

    const body = errorBody(err)
    const status = errorStatus(err)
    if (status === 409 && body?.code === 'OUT_OF_STOCK') {
      // AC-S02-8：不足の内容を出し、在庫表示を取り直す
      const shortages = (body.details?.shortages ?? []) as StockShortage[]
      const items = shortages.map((s) => fmt(ja.register.outOfStockItem, { name: nameWithMemo(s.product_name, products.value.get(s.product_id)?.memo), n: s.stock_qty })).join('、')
      await load({ quiet: true })
      return { ok: false, message: fmt(ja.register.outOfStock, { items }), closeDialog: true }
    }
    if (status === 422 && (body?.code === 'TOTAL_MISMATCH' || body?.code === 'ITEM_UNAVAILABLE')) {
      // AC-S02-9・07 §12.1：マスタを取り直して再計算し、次は新しい client_uuid で送る
      pendingUuid.value = null
      const removed = await load({ quiet: true })
      const text = removed.length > 0
        ? `${ja.error.stale}\n${fmt(ja.register.removedItems, { names: removed.join('、') })}`
        : ja.error.stale
      return { ok: false, message: text, closeDialog: true }
    }
    if (status === 422 && body?.errors) {
      const first = Object.values(body.errors)[0]?.[0]
      return { ok: false, message: first ?? body.message, closeDialog: false }
    }
    return { ok: false, message: body?.message ?? ja.error.unexpected, closeDialog: false }
  }

  /** 確定から 5 秒以内の［取り消す］（B 案。確認なし）。成功したら在庫表示を取り直す */
  async function undoLastSale(): Promise<boolean> {
    const sale = lastSale.value
    if (!sale) return false
    try {
      await cancelSale(sale.id)
      lastSale.value = null
      notice.value = { kind: 'info', text: ja.register.undone }
      await load({ quiet: true })
      return true
    } catch {
      notice.value = { kind: 'error', text: ja.register.undoFailed }
      return false
    }
  }

  function forgetLastSale(): void {
    lastSale.value = null
  }

  return {
    bootstrap, loading, loadError, taxTypeId, lines, discount, paymentMethodId, pendingUuid, held, lastSale, submitting,
    notice, storageOk, products, taxType, paymentMethod, itemCount, amounts, pricingError, canCheckout,
    load, add, increment, decrement, removeLine, clearOrder, setDiscount, setTaxType, setPaymentMethod,
    hold, recall, deleteHeld, startCheckout, confirm, undoLastSale, forgetLastSale,
  }
})
