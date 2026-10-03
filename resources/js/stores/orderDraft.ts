import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { createOrder } from '@/api/orders'
import { fetchOrderTables } from '@/api/orderTables'
import type { RegisterBootstrap, StockShortage } from '@/api/register'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { isCartLines, lineKey, orderedQty, reconcile, toPricingItems, type CartLine } from '@/lib/cart'
import { loadDeviceName } from '@/lib/deviceName'
import { nameWithMemo } from '@/lib/productLabel'
import { uuidV4 } from '@/lib/uuid'
import { useCatalogStore } from '@/stores/catalog'
import type { Order, OrderTable, Product } from '@/types/api'

/** S13 の品目（register と同じ行の形 + 品目のメモ。12 §8.10） */
export interface DraftLine extends CartLine {
  memo: string
}

export const DRAFT_MAX_LINES = 100 // 12 §5.5 品目は 100 件まで
export const DRAFT_MAX_QUANTITY = 99 // 12 §5.5 数量は 99 まで
export const LINE_MEMO_MAX = 50
export const NOTE_MAX = 200
export const LABEL_MAX = 20

export type SendOutcome = { ok: true; order: Order } | { ok: false; message: string }

function isDraftLines(value: unknown): value is DraftLine[] {
  return isCartLines(value) && value.every((l) => {
    const memo = (l as unknown as Record<string, unknown>).memo
    return typeof memo === 'string' && [...memo].length <= LINE_MEMO_MAX && l.quantity <= DRAFT_MAX_QUANTITY
  })
}

/** 文字数（サーバーの max と同じくコードポイントで数える） */
export function charCount(text: string): number {
  return [...text].length
}

/** S13 注文入力（店員）の状態（12 §8.4・§8.10）。localStorage（regi:<store_id>:order-draft）に保存して再読み込みで戻す */
export const useOrderDraftStore = defineStore('orderDraft', () => {
  const bootstrap = ref<RegisterBootstrap | null>(null)
  const tables = ref<OrderTable[]>([])
  const loading = ref(false)
  const loadError = ref<string | null>(null)

  const lines = ref<DraftLine[]>([])
  /** null は「テーブルなし」 */
  const tableId = ref<number | null>(null)
  const label = ref('')
  const note = ref('')
  /** 送信を始めたときに作った client_uuid。送信に成功するか内容を変えたら破棄する（通信断の再送は同じ UUID） */
  const pendingUuid = ref<string | null>(null)
  const submitting = ref(false)
  const storageOk = ref(true)
  let restoredFor: number | null = null

  const products = computed(() => new Map((bootstrap.value?.products ?? []).map((p) => [p.id, p])))
  const activeTables = computed(() => tables.value.filter((t) => t.is_active))
  const itemCount = computed(() => lines.value.reduce((sum, l) => sum + l.quantity, 0))
  /** 小計の目安（税区分・値引きは無い。サーバーの expected_subtotal の照合と同じ式） */
  const subtotal = computed(() =>
    toPricingItems(lines.value, products.value)
      .reduce((sum, i) => sum + (i.unit_price + i.option_prices.reduce((a, b) => a + b, 0)) * i.quantity, 0),
  )
  const canSend = computed(() => lines.value.length > 0 && subtotal.value >= 0 && !submitting.value)

  // ─── 端末への保存 ───

  function storageKey(): string | null {
    const id = bootstrap.value?.store.id
    return id === undefined ? null : `regi:${id}:order-draft`
  }

  function persist(): void {
    const key = storageKey()
    if (key === null || restoredFor !== bootstrap.value?.store.id) return
    try {
      localStorage.setItem(key, JSON.stringify({ lines: lines.value, table_id: tableId.value, label: label.value, note: note.value }))
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
      storageOk.value = false
    }
    if (typeof saved !== 'object' || saved === null) return
    const r = saved as Record<string, unknown>
    if (isDraftLines(r.lines)) lines.value = r.lines
    if (r.table_id === null || Number.isSafeInteger(r.table_id)) tableId.value = r.table_id as number | null
    if (typeof r.label === 'string') label.value = [...r.label].slice(0, LABEL_MAX).join('')
    if (typeof r.note === 'string') note.value = [...r.note].slice(0, NOTE_MAX).join('')
  }

  watch([lines, tableId, label, note], persist, { deep: true })

  // ─── マスタ ───

  /** マスタに合わせて品目を直す（販売を終えた商品・オプションを外す）。外した商品名を返す */
  function applyMasters(previous: ReadonlyMap<number, Product>, checkTable = true): string[] {
    const result = reconcile(lines.value, products.value, previous)
    if (result.lines.length !== lines.value.length) {
      const keep = new Set(result.lines.map((l) => l.key))
      lines.value = lines.value.filter((l) => keep.has(l.key))
      pendingUuid.value = null
    }
    if (checkTable && tableId.value !== null && !activeTables.value.some((t) => t.id === tableId.value)) tableId.value = null
    return result.removed
  }

  /** 商品とテーブルを画面に反映する。初回（または別の店舗）は端末に保存した注文を戻す */
  function apply(source: RegisterBootstrap, tableList: OrderTable[] | null): string[] {
    const previous = products.value
    const data = structuredClone(source) // 共有の応答（catalog）を書き換えない
    // 割引の商品はレジだけで使う（docs/10「割引の商品」）。割引の商品だけのカテゴリはタブを出さない
    const discounts = data.products.filter((p) => p.is_discount)
    const orderable = data.products.filter((p) => !p.is_discount)
    const categories = data.categories.map((c) => ({
      ...c,
      product_count: c.product_count - discounts.filter((p) => p.category_id === c.id).length,
    }))
    // 店舗の在庫管理が OFF（12 §6.6）：売切・残数・在庫による数量の制限を出さない
    bootstrap.value = {
      ...data,
      categories,
      products: data.store.stock_enabled ? orderable : orderable.map((p) => ({ ...p, track_stock: false })),
    }
    if (tableList !== null) tables.value = tableList
    if (restoredFor !== data.store.id) {
      lines.value = []
      tableId.value = null
      label.value = ''
      note.value = ''
      pendingUuid.value = null
      restore()
      restoredFor = data.store.id
    }
    // テーブルを読む前（先に描いたとき）は、選んであるテーブルを外さない
    const removed = applyMasters(previous, tableList !== null)
    persist()
    return removed
  }

  /**
   * 商品（GET /register/bootstrap）とテーブル（#56）を読む。quiet は画面を「読み込み中」にしない。
   * まだ商品を持っていなくても、レジ（S02）や先読みで受け取った応答があれば先にそれで描き、裏で最新を取り直す
   */
  async function load(options: { quiet?: boolean } = {}): Promise<string[]> {
    const catalog = useCatalogStore()
    let quiet = options.quiet === true
    if (bootstrap.value === null && catalog.data !== null) {
      apply(catalog.data, null)
      quiet = true
    }
    if (!quiet) loading.value = true
    loadError.value = null
    try {
      const [data, tableList] = await Promise.all([catalog.fetch(), fetchOrderTables()])
      return apply(data, tableList)
    } catch (err) {
      if (!quiet) loadError.value = isNetworkError(err) ? ja.orderNew.loadFailed : (errorBody(err)?.message ?? ja.orderNew.loadFailed)
      return []
    } finally {
      loading.value = false
    }
  }

  // ─── 品目の操作（通信しない） ───

  function changed(): void {
    pendingUuid.value = null
  }

  /** もう 1 個足せるか（在庫管理 ON は注文中の数が在庫に達したら不可。未会計の注文の分はサーバーが 409 で止める） */
  function canAddOne(product: Product, key?: string): boolean {
    if (key !== undefined && (lines.value.find((l) => l.key === key)?.quantity ?? 0) >= DRAFT_MAX_QUANTITY) return false
    if (!product.track_stock) return true
    return orderedQty(lines.value, product.id) < product.stock_qty
  }

  /** 1 個追加する。追加できなければ理由を返す */
  function add(product: Product, optionIds: readonly number[] = []): string | null {
    const key = lineKey(product.id, optionIds)
    const existing = lines.value.find((l) => l.key === key)
    if (!existing && lines.value.length >= DRAFT_MAX_LINES) return ja.orderNew.lineTooLong
    if (!canAddOne(product, key)) return null
    lines.value = existing
      ? lines.value.map((l) => (l.key === key ? { ...l, quantity: l.quantity + 1 } : l))
      : [...lines.value, { key, product_id: product.id, option_ids: [...optionIds].sort((a, b) => a - b), quantity: 1, memo: '' }]
    changed()
    return null
  }

  function increment(key: string): void {
    const line = lines.value.find((l) => l.key === key)
    const product = line ? products.value.get(line.product_id) : undefined
    if (line && product) add(product, line.option_ids)
  }

  function decrement(key: string): void {
    lines.value = lines.value
      .map((l) => (l.key === key ? { ...l, quantity: l.quantity - 1 } : l))
      .filter((l) => l.quantity > 0)
    changed()
  }

  function removeLine(key: string): void {
    lines.value = lines.value.filter((l) => l.key !== key)
    changed()
  }

  function setMemo(key: string, memo: string): void {
    const value = [...memo].slice(0, LINE_MEMO_MAX).join('')
    lines.value = lines.value.map((l) => (l.key === key ? { ...l, memo: value } : l))
    changed()
  }

  function setTable(id: number | null): void {
    if (tableId.value === id) return
    tableId.value = id
    changed()
  }

  function setLabel(value: string): void {
    label.value = [...value].slice(0, LABEL_MAX).join('')
    changed()
  }

  function setNote(value: string): void {
    note.value = [...value].slice(0, NOTE_MAX).join('')
    changed()
  }

  function clear(): void {
    lines.value = []
    label.value = ''
    note.value = ''
    changed()
  }

  // ─── 送信（#50） ───

  async function send(): Promise<SendOutcome> {
    if (!canSend.value) return { ok: false, message: ja.error.unexpected }
    pendingUuid.value ??= uuidV4()
    const uuid = pendingUuid.value
    const sent = lines.value
    const table = tableId.value

    submitting.value = true
    try {
      const order = await createOrder({
        client_uuid: uuid,
        order_table_id: table,
        label: table === null && label.value.trim() !== '' ? label.value.trim() : null,
        device_name: loadDeviceName() || null,
        items: sent.map((l) => ({ product_id: l.product_id, quantity: l.quantity, option_ids: l.option_ids, memo: l.memo.trim() === '' ? null : l.memo.trim() })),
        note: note.value.trim() === '' ? null : note.value.trim(),
        expected_subtotal: subtotal.value,
      })
      // テーブルは選んだまま残す（同じテーブルの追加の注文を続けて受けるため）。空席だったテーブルは利用中になる
      lines.value = []
      label.value = ''
      note.value = ''
      pendingUuid.value = null
      if (order.order_table_id !== null) {
        tables.value = tables.value.map((t) => (t.id === order.order_table_id && t.opened_at === null ? { ...t, opened_at: order.created_at } : t))
      }
      return { ok: true, order }
    } catch (err) {
      return await sendFailed(err)
    } finally {
      submitting.value = false
    }
  }

  async function sendFailed(err: unknown): Promise<SendOutcome> {
    // 通信できない：内容と UUID を残し、同じ UUID で送り直してもらう（AC-S13-2）
    if (isNetworkError(err)) return { ok: false, message: ja.orderNew.network }

    const body = errorBody(err)
    const status = errorStatus(err)
    if (status === 409 && body?.code === 'OUT_OF_STOCK') {
      const shortages = (body.details?.shortages ?? []) as StockShortage[]
      const items = shortages.map((s) => fmt(ja.register.outOfStockItem, { name: nameWithMemo(s.product_name, products.value.get(s.product_id)?.memo), n: s.stock_qty })).join('、')
      await load({ quiet: true })
      return { ok: false, message: fmt(ja.orderNew.outOfStock, { items }) }
    }
    if (status === 422 && (body?.code === 'TOTAL_MISMATCH' || body?.code === 'ITEM_UNAVAILABLE')) {
      pendingUuid.value = null
      const removed = await load({ quiet: true })
      return {
        ok: false,
        message: removed.length > 0 ? `${ja.error.stale}\n${fmt(ja.register.removedItems, { names: removed.join('、') })}` : ja.error.stale,
      }
    }
    if (status === 422) {
      const errors = fieldErrors(err)
      if ('order_table_id' in errors) {
        // 無効・削除されたテーブル：一覧を取り直して「テーブルなし」に戻す
        pendingUuid.value = null
        await load({ quiet: true })
        return { ok: false, message: errors.order_table_id ?? body?.message ?? ja.error.unexpected }
      }
      const first = Object.values(errors)[0]
      return { ok: false, message: first ?? body?.message ?? ja.error.unexpected }
    }
    return { ok: false, message: body?.message ?? ja.error.unexpected }
  }

  return {
    bootstrap, tables, loading, loadError, lines, tableId, label, note, pendingUuid, submitting, storageOk,
    products, activeTables, itemCount, subtotal, canSend,
    load, canAddOne, add, increment, decrement, removeLine, setMemo, setTable, setLabel, setNote, clear, send,
  }
})
