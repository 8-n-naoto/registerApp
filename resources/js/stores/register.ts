import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { cancelSale, createSale, type OfflineSaleInput, type RegisterBootstrap, type SaleInput, type StockShortage } from '@/api/register'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { addOne, canAddOne, isCartLines, lineKey, MAX_LINES, MAX_QUANTITY, reconcile, removeOne, toPricingItems, type CartLine } from '@/lib/cart'
import { loadDeviceName } from '@/lib/deviceName'
import { nameWithMemo } from '@/lib/productLabel'
import { calculateAmounts, PricingError, settle, type PricingAmounts } from '@/lib/pricing'
import { uuidV4 } from '@/lib/uuid'
import { useAuthStore } from '@/stores/auth'
import { useCatalogStore } from '@/stores/catalog'
import { OUTBOX_VERSION, useOutboxStore } from '@/stores/outbox'
import type { DiscountType, Order, Product, Sale } from '@/types/api'

export interface Discount {
  type: DiscountType
  value: number
}

/**
 * カートに入れた注文（12 §8.6）。確定で order_ids として送る。
 * 外すときにカートから引けるよう、入れたときの品目（商品・オプション・数量）も持つ
 */
export interface LinkedOrder {
  id: number
  order_no: number
  place: string // テーブル名か呼び名（どちらも無ければ空）
  lines: CartLine[]
}

/** 保留中の注文（08 §5.3 A 案。端末の localStorage に最大 10 件） */
export interface HeldOrder {
  tax_type_id: number | null
  lines: CartLine[]
  discount: Discount | null
  held_at: string // ISO 8601
  orders?: LinkedOrder[] // 12 §8.6（WP 7-10 より前に保留したものには無い）
}

export const HELD_MAX = 10
export const ORDER_IDS_MAX = 20 // 12 §5.15 order_ids は 20 件まで

/** 会計の確定の結果。失敗時の closeDialog は「ダイアログを閉じて注文を直してもらう」エラーか */
export type ConfirmOutcome =
  | { ok: true; sale: Sale; offline?: boolean } // offline：端末に保存した（14 §7.4）
  | { ok: false; message: string; closeDialog: boolean; staleOrders?: StaleOrders }

export interface ConfirmExtra {
  received: number | null
  customer_count: number | null
  memo: string | null
}

/** 会計の確定の失敗のうち、カートに入れた注文が会計できなくなっていたもの（12 §8.6） */
export interface StaleOrders { ids: number[]; message: string }

type Notice = { kind: 'error' | 'info'; text: string }

function isDiscount(v: unknown): v is Discount {
  if (typeof v !== 'object' || v === null) return false
  const r = v as Record<string, unknown>
  return (r.type === 'amount' || r.type === 'percent') && Number.isSafeInteger(r.value) && (r.value as number) > 0
}

function isLinkedOrders(v: unknown): v is LinkedOrder[] {
  return Array.isArray(v) && v.length <= ORDER_IDS_MAX && v.every((o: unknown) => {
    if (typeof o !== 'object' || o === null) return false
    const r = o as Record<string, unknown>
    return Number.isSafeInteger(r.id) && Number.isSafeInteger(r.order_no) && typeof r.place === 'string' && isCartLines(r.lines)
  })
}

function isHeldOrder(v: unknown): v is HeldOrder {
  if (typeof v !== 'object' || v === null) return false
  const r = v as Record<string, unknown>
  return (r.tax_type_id === null || Number.isSafeInteger(r.tax_type_id))
    && isCartLines(r.lines)
    && (r.discount === null || isDiscount(r.discount))
    && typeof r.held_at === 'string'
    && (r.orders === undefined || isLinkedOrders(r.orders))
}

/** 注文の品目をカートの行にする（同じ商品・オプションは 1 行。品目のメモは会計に持ち込まない） */
function orderLines(order: Order): CartLine[] {
  const result: CartLine[] = []
  for (const item of order.items) {
    const optionIds = item.options.map((o) => o.product_option_id).sort((a, b) => a - b)
    const key = lineKey(item.product_id, optionIds)
    const existing = result.find((l) => l.key === key)
    if (existing) existing.quantity += item.quantity
    else result.push({ key, product_id: item.product_id, option_ids: optionIds, quantity: item.quantity })
  }
  return result
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
  /** カートに入れた注文（12 §8.6）。注文を変えても残す（金額が変わるので pendingUuid は破棄する） */
  const linkedOrders = ref<LinkedOrder[]>([])
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
  const orderIds = computed(() => linkedOrders.value.map((o) => o.id))

  const pricing = computed<{ amounts: PricingAmounts | null; error: string | null }>(() => {
    const b = bootstrap.value
    const t = taxType.value
    if (!b || !t) return { amounts: null, error: null }
    try {
      return {
        amounts: calculateAmounts({
          price_mode: b.store.price_mode,
          rounding: b.store.rounding,
          tax_rate_permille: t.rate_permille,
          items: toPricingItems(lines.value, products.value),
          discount: discount.value,
        }),
        error: null,
      }
    } catch (err) {
      if (err instanceof PricingError) {
        const message = err.reason === 'NEGATIVE_SUBTOTAL' ? ja.register.discountExceeds : ja.register.pricingError
        return { amounts: null, error: message }
      }
      throw err
    }
  })
  const amounts = computed(() => pricing.value.amounts)
  /** 計算できないときの表示文（割引が商品の合計を超えた・単価が不正）。計算できれば null */
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
        orders: linkedOrders.value,
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
    if (isLinkedOrders(r.orders)) linkedOrders.value = r.orders
  }

  watch([taxTypeId, lines, discount, held, linkedOrders], persist, { deep: true })

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

  /** マスタを画面に反映する。初回（または別の店舗）は端末に保存した注文を復元する */
  function apply(source: RegisterBootstrap): string[] {
    const previous = products.value
    // 共有の応答（catalog）を書き換えないよう複製する（deductStock が手元の在庫を書き換えるため）
    const data = structuredClone(source)
    // 店舗の在庫管理が OFF（12 §6.6）：どの商品も在庫管理 OFF として扱い、売切・残数・在庫による数量の制限を出さない
    bootstrap.value = data.store.stock_enabled ? data : { ...data, products: data.products.map((p) => ({ ...p, track_stock: false })) }
    if (restoredFor !== data.store.id) {
      // 別の店舗でログインし直した：前の店舗の注文を持ち越さない
      taxTypeId.value = null
      lines.value = []
      discount.value = null
      held.value = []
      linkedOrders.value = []
      pendingUuid.value = null
      lastSale.value = null
      restore()
      restoredFor = data.store.id
    }
    const removed = applyMasters(previous)
    if (removed.length > 0) notice.value = { kind: 'error', text: fmt(ja.register.removedItems, { names: removed.join('、') }) }
    persist()
    return removed
  }

  /**
   * GET /register/bootstrap。quiet は画面を「読み込み中」にしない。
   * まだ持っていなくても、注文の入力（S13）や先読みで受け取った応答があれば先にそれで描き、裏で最新を取り直す
   */
  async function load(options: { quiet?: boolean } = {}): Promise<string[]> {
    const catalog = useCatalogStore()
    let quiet = options.quiet === true
    if (bootstrap.value === null && catalog.data !== null) {
      apply(catalog.data)
      quiet = true
    }
    if (!quiet) loading.value = true
    loadError.value = null
    try {
      return apply(await catalog.fetch())
    } catch (err) {
      if (!quiet) loadError.value = isNetworkError(err) ? ja.register.loadFailed : (errorBody(err)?.message ?? ja.register.loadFailed)
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
    linkedOrders.value = []
    orderChanged()
  }

  // ─── 注文から会計（12 §8.6） ───

  /**
   * 注文の品目をカートに足し、注文を会計の対象にする。入っている注文は飛ばす。
   * 販売を終えた商品・オプションの品目は足さず（会計の明細は注文と一致しなくてよい、12 §5.15）、名前を知らせる
   */
  function addOrders(orders: readonly Order[]): boolean {
    const fresh = orders.filter((o) => !orderIds.value.includes(o.id))
    if (fresh.length === 0) return false
    if (linkedOrders.value.length + fresh.length > ORDER_IDS_MAX) {
      notice.value = { kind: 'error', text: fmt(ja.register.ordersTooMany, { n: ORDER_IDS_MAX }) }
      return false
    }
    let next = [...lines.value]
    const skipped: string[] = []
    const linked: LinkedOrder[] = []
    for (const order of fresh) {
      const added: CartLine[] = []
      for (const line of orderLines(order)) {
        const product = products.value.get(line.product_id)
        const usable = product !== undefined && line.option_ids.every((id) => product.options.some((o) => o.id === id))
        const existing = next.find((l) => l.key === line.key)
        if (!usable || (!existing && next.length >= MAX_LINES)) {
          skipped.push(order.items.find((i) => i.product_id === line.product_id)?.product_name ?? '')
          continue
        }
        next = existing
          ? next.map((l) => (l.key === line.key ? { ...l, quantity: Math.min(l.quantity + line.quantity, MAX_QUANTITY) } : l))
          : [...next, { ...line }]
        added.push(line)
      }
      linked.push({ id: order.id, order_no: order.order_no, place: order.table_name ?? order.label ?? '', lines: added })
    }
    lines.value = next
    linkedOrders.value = [...linkedOrders.value, ...linked]
    orderChanged()
    const names = [...new Set(skipped.filter((n) => n !== ''))]
    notice.value = names.length > 0
      ? { kind: 'error', text: fmt(ja.register.orderItemsSkipped, { names: names.join('、') }) }
      : { kind: 'info', text: fmt(ja.register.ordersAdded, { n: linked.length }) }
    return true
  }

  /** 注文を会計の対象から外し、その注文で足した数量をカートから引く */
  function removeOrders(ids: readonly number[]): void {
    const target = linkedOrders.value.filter((o) => ids.includes(o.id))
    if (target.length === 0) return
    let next = [...lines.value]
    for (const order of target) {
      for (const line of order.lines) {
        next = next.map((l) => (l.key === line.key ? { ...l, quantity: l.quantity - line.quantity } : l)).filter((l) => l.quantity > 0)
      }
    }
    lines.value = next
    linkedOrders.value = linkedOrders.value.filter((o) => !ids.includes(o.id))
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
      orders: linkedOrders.value,
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
    linkedOrders.value = order.orders ?? []
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
      return { ok: false, message: pricingError.value ?? ja.register.pricingError, closeDialog: false }
    }
    startCheckout()
    const uuid = pendingUuid.value ?? uuidV4()
    const sold = lines.value

    const input: SaleInput = {
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
      order_ids: orderIds.value,
    }

    submitting.value = true
    try {
      // 14 §7.4 端末が通信できないと分かっているときは送らずに端末へ保存する
      if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        const saved = await saveOffline(input, sold)
        if (saved) return finishConfirm(saved, sold, true)
      }
      const sale = await createSale(input)
      return finishConfirm(sale, sold, false)
    } catch (err) {
      // 通信できない（応答が無い・中継が落ちている）：同じ client_uuid で端末へ保存し、後で送る
      if (isOfflineFailure(err)) {
        const saved = await saveOffline(input, sold)
        if (saved) return finishConfirm(saved, sold, true)
      }
      return await confirmFailed(err)
    } finally {
      submitting.value = false
    }
  }

  function finishConfirm(sale: Sale, sold: readonly CartLine[], offline: boolean): ConfirmOutcome {
    deductStock(sold)
    lastSale.value = sale
    lines.value = []
    discount.value = null
    linkedOrders.value = []
    pendingUuid.value = null
    paymentMethodId.value = defaultPaymentMethodId()
    notice.value = null
    return offline ? { ok: true, sale, offline: true } : { ok: true, sale }
  }

  /** 応答が無いか、サーバーの手前（中継・ロードバランサ）が落ちている */
  function isOfflineFailure(err: unknown): boolean {
    const status = errorStatus(err)
    return isNetworkError(err) || status === 502 || status === 503 || status === 504
  }

  /**
   * 14 §7.4 会計を送信待ち（outbox）に保存し、完了の表示に使う会計を返す。
   * 価格・税率・端数処理は、いま画面に出しているマスタの値を記録する（サーバーはこの値で計算し直し、違いを記録する）。
   * 保存できなければ null（会計を確定扱いにしない）
   */
  async function saveOffline(input: SaleInput, sold: readonly CartLine[]): Promise<Sale | null> {
    const b = bootstrap.value
    const tax = taxType.value
    const pay = paymentMethod.value
    const me = useAuthStore().me
    if (!b || !tax || !pay || !me || me.store?.id !== b.store.id) return null
    let offline: OfflineSaleInput
    let sale: Sale
    try {
      const pricingItems = toPricingItems(sold, products.value)
      const amountsNow = calculateAmounts({
        price_mode: b.store.price_mode,
        rounding: b.store.rounding,
        tax_rate_permille: tax.rate_permille,
        items: pricingItems,
        discount: input.discount,
      })
      const settlement = settle(amountsNow.total, pay.is_cash, input.received)
      const soldAt = new Date().toISOString()
      offline = {
        ...input,
        items: input.items.map((item, i) => ({
          ...item,
          unit_price: pricingItems[i]?.unit_price ?? 0,
          option_prices: pricingItems[i]?.option_prices ?? [],
        })),
        sold_at: soldAt,
        operator_id: me.user.id,
        tax_rate_permille: tax.rate_permille,
        price_mode: b.store.price_mode,
        rounding: b.store.rounding,
      }
      sale = {
        id: 0,
        client_uuid: input.client_uuid,
        business_date: b.current_business_date,
        sold_at: soldAt,
        tax_type_name: tax.name,
        tax_rate_permille: tax.rate_permille,
        price_mode: b.store.price_mode,
        subtotal: amountsNow.subtotal,
        discount_type: input.discount?.type ?? null,
        discount_value: input.discount?.value ?? 0,
        discount_amount: amountsNow.discount_amount,
        total: amountsNow.total,
        tax_amount: amountsNow.tax_amount,
        payment_method_name: pay.name,
        is_cash: pay.is_cash,
        received: settlement.received,
        change_amount: settlement.change_amount,
        customer_count: input.customer_count,
        memo: input.memo,
        status: 'completed',
        cancelled_at: null,
        cancelled_by_name: null,
        user_name: me.user.name,
        device_name: input.device_name,
        store_name: b.store.name,
        items: sold.map((line, i) => {
          const product = products.value.get(line.product_id)
          const options = line.option_ids.map((id) => product?.options.find((o) => o.id === id))
          const optionsPrice = pricingItems[i]?.option_prices.reduce((s, p) => s + p, 0) ?? 0
          return {
            id: 0,
            product_id: line.product_id,
            product_name: product?.name ?? '',
            product_code: product?.code ?? '',
            product_memo: product?.memo ?? null,
            category_id: product?.category_id ?? null,
            category_name: b.categories.find((c) => c.id === product?.category_id)?.name ?? null,
            unit_price: pricingItems[i]?.unit_price ?? 0,
            options_price: optionsPrice,
            quantity: line.quantity,
            line_total: amountsNow.line_totals[i] ?? 0,
            options: options.flatMap((o) => (o ? [{ product_option_id: o.id, option_name: o.name, price: o.price }] : [])),
          }
        }),
        is_offline: true,
        client_sold_at: soldAt,
        synced_at: null,
        sync_issues: null,
        issues_reviewed_at: null,
      }
    } catch {
      return null // 計算できない（預り金の不足など）：通常の失敗として扱う
    }
    try {
      await useOutboxStore().add({
        version: OUTBOX_VERSION,
        store_id: b.store.id,
        client_uuid: input.client_uuid,
        created_at: sale.sold_at,
        input: offline,
        sale,
        status: 'pending',
        attempts: 0,
        last_error: null,
      })
    } catch {
      return null
    }
    return sale
  }

  async function confirmFailed(err: unknown): Promise<ConfirmOutcome> {
    // 通信できない：注文とダイアログを残し、同じ client_uuid で押し直してもらう（AC-S02-7）
    if (isNetworkError(err)) return { ok: false, message: ja.error.network, closeDialog: false }

    const body = errorBody(err)
    const status = errorStatus(err)
    // 12 §8.6：カートに入れた注文が会計済み・取消・見つからない。どの注文かを返し、外すかを画面で選んでもらう
    const staleIds = body?.details?.order_ids
    if ((status === 409 || status === 422) && Array.isArray(staleIds)) {
      const ids = staleIds.filter((id): id is number => typeof id === 'number' && orderIds.value.includes(id))
      if (ids.length > 0) {
        pendingUuid.value = null
        const message = body?.code === 'ORDER_ALREADY_PAID' ? ja.register.orderAlreadyPaid : (body?.message ?? ja.error.unexpected)
        return { ok: false, message, closeDialog: true, staleOrders: { ids, message } }
      }
    }
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

  /**
   * 確定から 5 秒以内の［取り消す］（B 案。確認なし）。結果は完了のポップアップの中で知らせるので、上の帯は出さない。
   * 成功したら在庫表示を裏で取り直す（取り直しを待たずに「取り消しました」を出す）
   */
  async function undoLastSale(): Promise<boolean> {
    const sale = lastSale.value
    if (!sale) return false
    let saleId = sale.id
    if (sale.is_offline && sale.id === 0) {
      // 14 §7.4 まだ送っていなければ端末から消すだけ。送信中・送信済みならサーバーの会計を取り消す
      const storeId = bootstrap.value?.store.id
      if (storeId === undefined) return false
      const result = await useOutboxStore().discard(storeId, sale.client_uuid)
      if (result === null) return false
      if (result === 'removed') {
        restoreStock(sale)
        lastSale.value = null
        return true
      }
      saleId = result
    }
    try {
      await cancelSale(saleId)
    } catch {
      return false
    }
    lastSale.value = null
    void load({ quiet: true })
    return true
  }

  /** 送らずに消した会計の分を、手元の在庫表示に戻す */
  function restoreStock(sale: Sale): void {
    for (const item of sale.items) {
      const product = bootstrap.value?.products.find((p) => p.id === item.product_id)
      if (product?.track_stock) product.stock_qty += item.quantity
    }
  }

  function forgetLastSale(): void {
    lastSale.value = null
  }

  return {
    bootstrap, loading, loadError, taxTypeId, lines, discount, paymentMethodId, pendingUuid, held, linkedOrders, lastSale, submitting,
    notice, storageOk, products, taxType, paymentMethod, itemCount, orderIds, amounts, pricingError, canCheckout,
    load, add, increment, decrement, removeLine, clearOrder, addOrders, removeOrders, setDiscount, setTaxType, setPaymentMethod,
    hold, recall, deleteHeld, startCheckout, confirm, undoLastSale, forgetLastSale,
  }
})
