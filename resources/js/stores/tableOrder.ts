import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { createCustomerOrder, fetchCustomerOrders, fetchMenu } from '@/api/publicTable'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError, retryAfterSeconds } from '@/lib/apiError'
import { uuidV4 } from '@/lib/uuid'
import type { PublicMenu, PublicMenuProduct, PublicOrder } from '@/types/api'

/** C01 のカートの 1 行（同じ商品・オプション・メモなら 1 行にまとめる） */
export interface CustomerLine {
  key: string
  product_id: number
  option_ids: number[]
  quantity: number
  memo: string
}

export const STORAGE_KEY = 'regi:table-cart' // 12 §8.9
export const TOKEN_PREFIX_LENGTH = 8
export const TOTAL_QUANTITY_MAX = 50 // 12 §5.2 手順 3（OrderService::CUSTOMER_MAX_TOTAL_QUANTITY）
export const LINE_MEMO_MAX = 50
export const NOTE_MAX = 200

export type LoadState = 'loading' | 'ok' | 'invalid' | 'error'
export type SendOutcome = { ok: true; order: PublicOrder } | { ok: false; message: string }

const t = ja.tableOrder

export function customerLineKey(productId: number, optionIds: readonly number[], memo: string): string {
  return `${productId}:${[...optionIds].sort((a, b) => a - b).join(',')}:${memo}`
}

function chars(text: string, max: number): string {
  return [...text].slice(0, max).join('')
}

function isLines(value: unknown): value is CustomerLine[] {
  return Array.isArray(value) && value.every((v: unknown) => {
    if (typeof v !== 'object' || v === null) return false
    const l = v as Record<string, unknown>
    return typeof l.key === 'string'
      && Number.isSafeInteger(l.product_id)
      && Array.isArray(l.option_ids) && l.option_ids.every((id) => Number.isSafeInteger(id))
      && Number.isSafeInteger(l.quantity) && (l.quantity as number) >= 1
      && typeof l.memo === 'string' && [...l.memo].length <= LINE_MEMO_MAX
  })
}

/**
 * C01 お客さんの注文（12 §8.9・§8.10）。ログインの auth ストアとは独立。
 * カートは sessionStorage に、トークンの先頭 8 文字と一緒に保存し、別のテーブルの QR を開いたら捨てる
 */
export const useTableOrderStore = defineStore('tableOrder', () => {
  const token = ref('')
  const menu = ref<PublicMenu | null>(null)
  const loadState = ref<LoadState>('loading')
  const loadError = ref<string | null>(null)

  const lines = ref<CustomerLine[]>([])
  const note = ref('')
  /** 送信を始めたときに作った client_uuid。成功するか内容を変えたら捨てる（通信断の再送・二度押しは同じ UUID） */
  const pendingUuid = ref<string | null>(null)
  const submitting = ref(false)
  const lastOrder = ref<PublicOrder | null>(null)

  const orders = ref<PublicOrder[]>([])
  const ordersLoading = ref(false)
  const ordersError = ref<string | null>(null)

  const products = computed(() => new Map((menu.value?.products ?? []).map((p) => [p.id, p])))
  const itemCount = computed(() => lines.value.reduce((sum, l) => sum + l.quantity, 0))
  /** 目安の小計（サーバーの expected_subtotal と同じ式：(価格 + オプション) × 数量 の合計） */
  const subtotal = computed(() => lines.value.reduce((sum, l) => sum + linePrice(l) * l.quantity, 0))
  const maxLines = computed(() => menu.value?.limits.max_items ?? 30)
  const maxQuantity = computed(() => menu.value?.limits.max_quantity ?? 20)

  function linePrice(line: Pick<CustomerLine, 'product_id' | 'option_ids'>): number {
    const p = products.value.get(line.product_id)
    if (!p) return 0
    return p.price + line.option_ids.reduce((sum, id) => sum + (p.options.find((o) => o.id === id)?.price ?? 0), 0)
  }

  // ─── 端末への保存（sessionStorage） ───

  function persist(): void {
    if (token.value === '') return
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        t: token.value.slice(0, TOKEN_PREFIX_LENGTH),
        lines: lines.value,
        note: note.value,
        uuid: pendingUuid.value,
      }))
    } catch {
      // 保存できなくても、この画面を開いている間は使える
    }
  }

  function restore(): void {
    let saved: unknown = null
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY)
      saved = raw === null ? null : JSON.parse(raw)
    } catch {
      saved = null
    }
    if (typeof saved !== 'object' || saved === null) return
    const r = saved as Record<string, unknown>
    if (r.t !== token.value.slice(0, TOKEN_PREFIX_LENGTH)) return // 別のテーブルの QR
    if (isLines(r.lines)) lines.value = r.lines
    if (typeof r.note === 'string') note.value = chars(r.note, NOTE_MAX)
    if (typeof r.uuid === 'string') pendingUuid.value = r.uuid
  }

  watch([lines, note, pendingUuid], persist, { deep: true })

  // ─── 読み込み ───

  function loadMessage(err: unknown): string {
    if (errorStatus(err) === 429) return fmt(t.tooMany, { n: retryAfterSeconds(err) })
    return isNetworkError(err) ? t.network : t.loadFailed
  }

  /** メニューに無い・売切の商品、無いオプションの行を外す。外した商品名を返す */
  function reconcile(extraRemoved: readonly number[] = []): string[] {
    const removed: string[] = []
    const keep = lines.value.filter((l) => {
      const p = products.value.get(l.product_id)
      const ok = p !== undefined && !p.sold_out && !extraRemoved.includes(l.product_id)
        && l.option_ids.every((id) => p.options.some((o) => o.id === id))
      if (!ok) removed.push(p?.name ?? '')
      return ok
    })
    if (keep.length !== lines.value.length) {
      lines.value = keep
      pendingUuid.value = null
    }
    return [...new Set(removed.filter((n) => n !== ''))]
  }

  /** 画面を開いたとき。別のテーブルの QR ならカートを捨てる */
  async function open(value: string): Promise<void> {
    if (token.value !== value) {
      token.value = value
      menu.value = null
      lines.value = []
      note.value = ''
      pendingUuid.value = null
      lastOrder.value = null
      orders.value = []
      restore()
      persist()
    }
    await loadMenu()
  }

  async function loadMenu(options: { quiet?: boolean } = {}): Promise<string[]> {
    if (!options.quiet) loadState.value = 'loading'
    loadError.value = null
    try {
      menu.value = await fetchMenu(token.value)
      loadState.value = 'ok'
      return reconcile()
    } catch (err) {
      if (errorStatus(err) === 404) {
        loadState.value = 'invalid'
        menu.value = null
      } else if (!options.quiet || menu.value === null) {
        loadState.value = 'error'
        loadError.value = loadMessage(err)
      }
      return []
    }
  }

  async function loadOrders(): Promise<void> {
    ordersLoading.value = true
    ordersError.value = null
    try {
      orders.value = await fetchCustomerOrders(token.value)
    } catch (err) {
      if (errorStatus(err) === 404) loadState.value = 'invalid'
      ordersError.value = loadMessage(err)
    } finally {
      ordersLoading.value = false
    }
  }

  // ─── カート ───

  /** カートに入れる。上限を超えるなら理由を返す */
  function add(product: PublicMenuProduct, optionIds: number[], quantity: number, memo: string): string | null {
    if (product.sold_out) return null
    const text = chars(memo.trim(), LINE_MEMO_MAX)
    const key = customerLineKey(product.id, optionIds, text)
    const existing = lines.value.find((l) => l.key === key)
    if (!existing && lines.value.length >= maxLines.value) return fmt(t.limitLines, { n: maxLines.value })
    if (itemCount.value + quantity > TOTAL_QUANTITY_MAX) return fmt(t.limitQuantity, { n: TOTAL_QUANTITY_MAX })
    if (existing) {
      existing.quantity = Math.min(existing.quantity + quantity, maxQuantity.value)
    } else {
      lines.value.push({ key, product_id: product.id, option_ids: [...optionIds].sort((a, b) => a - b), quantity: Math.min(quantity, maxQuantity.value), memo: text })
    }
    pendingUuid.value = null
    lastOrder.value = null
    return null
  }

  function setQuantity(key: string, quantity: number): void {
    const line = lines.value.find((l) => l.key === key)
    if (!line) return
    if (quantity <= 0) {
      remove(key)
      return
    }
    const others = itemCount.value - line.quantity
    line.quantity = Math.min(quantity, maxQuantity.value, TOTAL_QUANTITY_MAX - others)
    pendingUuid.value = null
  }

  function remove(key: string): void {
    lines.value = lines.value.filter((l) => l.key !== key)
    pendingUuid.value = null
  }

  function setNote(value: string): void {
    const next = chars(value, NOTE_MAX)
    if (next === note.value) return
    note.value = next
    pendingUuid.value = null
  }

  // ─── 送信 ───

  async function send(): Promise<SendOutcome> {
    if (submitting.value || lines.value.length === 0) return { ok: false, message: '' }
    submitting.value = true
    pendingUuid.value ??= uuidV4()
    try {
      const order = await createCustomerOrder(token.value, {
        client_uuid: pendingUuid.value,
        items: lines.value.map((l) => ({ product_id: l.product_id, quantity: l.quantity, option_ids: l.option_ids, memo: l.memo === '' ? null : l.memo })),
        note: note.value.trim() === '' ? null : note.value.trim(),
        expected_subtotal: subtotal.value,
      })
      lines.value = []
      note.value = ''
      pendingUuid.value = null
      lastOrder.value = order
      void loadMenu({ quiet: true }) // 売切の表示を取り直す
      return { ok: true, order }
    } catch (err) {
      return { ok: false, message: await failure(err) }
    } finally {
      submitting.value = false
    }
  }

  /** 失敗の扱い（12 §5.2）。通信断・429 は同じ UUID で送り直せるよう残す。内容に関わる失敗は UUID を捨てる */
  async function failure(err: unknown): Promise<string> {
    if (isNetworkError(err)) return t.network
    const status = errorStatus(err)
    if (status === 429) return fmt(t.tooMany, { n: retryAfterSeconds(err) })
    if (status === 404) {
      loadState.value = 'invalid'
      return t.invalid
    }
    const body = errorBody(err)
    const message = body?.message ?? ja.error.unexpected
    pendingUuid.value = null
    const code = body?.code
    if (code === 'OUT_OF_STOCK' || code === 'ITEM_UNAVAILABLE' || code === 'TOTAL_MISMATCH' || code === 'ORDER_NOT_ACCEPTING') {
      const ids = body?.details?.product_ids
      const extra = Array.isArray(ids) ? ids.filter((id): id is number => typeof id === 'number') : []
      const names = extra.map((id) => products.value.get(id)?.name).filter((n): n is string => n !== undefined)
      const removed = await loadMenu({ quiet: true })
      const all = [...new Set([...names, ...removed, ...reconcile(extra)])]
      return all.length > 0 ? `${message}\n${fmt(t.removed, { names: all.join('、') })}` : message
    }
    return message
  }

  return {
    token, menu, loadState, loadError, lines, note, pendingUuid, submitting, lastOrder, orders, ordersLoading, ordersError,
    products, itemCount, subtotal, maxQuantity,
    linePrice, open, loadMenu, loadOrders, add, setQuantity, remove, setNote, send,
  }
})
