// デモ用の API（axios のアダプタ）。06・12 の API をブラウザの中のデータで返す
import { AxiosError, AxiosHeaders, type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { ja } from '@/i18n/ja'
import { PricingError } from '@/lib/pricing'
import type {
  Attendance, AttendanceBreak, AttendanceStatus, AttendanceSummary, AttendanceSummaryRow, AuditLogRow, ByCategoryRow, ByPaymentRow, ByProductRow, ByTaxRow,
  Closing, LaborMember, LaborWarning, Me, Order, OrderTable, Product, ProductOption, ProductOptionGroup, PublicOrder, SalesTotals, Shift, ShiftMonth, ShiftPattern, ShiftRequest, ShiftSegment, SummaryWarning, User,
} from '@/types/api'
import {
  STORE_ID, addOrder, businessDate, isoAt, buildSaleAmounts, clearSaved, createSeed, hmToMinutes, loadDb, makeSale, nextId, saveDb, shiftYmd, tokyoIso, tokyoParts,
  type Db, type DbAttendance, type DbOrder, type DbSale, type DbStore, type DbTable, type DbUser, type DbWage,
} from './db'

type Json = Record<string, unknown>

class HttpError extends Error {
  constructor(public status: number, public body: Json) {
    super(String(body.message ?? status))
  }
}

const fail = (status: number, message: string, extra: Json = {}): never => { throw new HttpError(status, { message, ...extra }) }
const invalid = (field: string, message: string): never => fail(422, message, { code: 'VALIDATION', errors: { [field]: [message] } })
const notFound = (): never => fail(404, '見つかりません')

let db: Db = loadDb() ?? createSeed(Date.now())
const listeners = new Set<() => void>()

export function onDbChange(fn: () => void): () => void {
  listeners.add(fn)
  return () => listeners.delete(fn)
}
function changed(): void {
  saveDb(db)
  for (const fn of listeners) fn()
}

export function demoSessionUser(): DbUser | null {
  return db.users.find((u) => u.id === db.session_user_id) ?? null
}
export function setDemoSession(userId: number | null): void {
  db.session_user_id = userId
  const u = demoSessionUser()
  if (u) {
    u.last_login_at = isoAt(Date.now())
    clockInNow(u)
  }
  changed()
}
export function resetDemo(): void {
  clearSaved()
  db = createSeed(Date.now())
  saveDb(db)
}
export function demoTableToken(name: string): string | null {
  return db.tables.find((t) => t.name === name)?.token ?? null
}

// ─── 共通 ───
const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T
const nowIso = () => isoAt(Date.now())
const store = (): DbStore => db.stores.find((s) => s.id === STORE_ID) as DbStore
const today = () => businessDate(Date.now(), store().day_cutoff_time)
const str = (v: unknown): string => (typeof v === 'string' ? v : '')
const int = (v: unknown): number => (typeof v === 'number' && Number.isInteger(v) ? v : Number.NaN)
const bool = (v: unknown): boolean => v === true
const nullableStr = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v : null)

function publicUser(u: DbUser): User {
  const { password: _password, ...rest } = u
  void _password
  return rest
}

function audit(user: DbUser | null, action: string, target: [string, number] | null, before: Json | null, after: Json | null): void {
  db.logs.push({ id: nextId(db, 'log'), created_at: nowIso(), store_id: user?.store_id ?? STORE_ID, user_id: user?.id ?? null, action, target_type: target?.[0] ?? null, target_id: target?.[1] ?? null, before, after })
}

function me(u: DbUser): Me {
  const s = u.store_id === null ? null : db.stores.find((x) => x.id === u.store_id) ?? null
  return {
    user: publicUser(u),
    store: s === null ? null : storeSettings(s),
    current_business_date: s === null ? null : businessDate(Date.now(), s.day_cutoff_time),
    attendance: u.role === 'admin' ? null : meAttendance(u),
    labor_warnings: u.role === 'owner' ? laborWarnings() : [],
  }
}
function storeSettings(s: DbStore) {
  return { id: s.id, name: s.name, price_mode: s.price_mode, rounding: s.rounding, day_cutoff_time: s.day_cutoff_time, stock_enabled: s.stock_enabled }
}

function categoriesWithCount() {
  return [...db.categories].sort((a, b) => a.sort_order - b.sort_order)
    .map((c) => ({ ...c, product_count: db.products.filter((p) => p.category_id === c.id).length }))
}
const sortedProducts = () => [...db.products].sort((a, b) => a.sort_order - b.sort_order)
const findProduct = (id: unknown): Product => db.products.find((p) => p.id === id) ?? notFound()

// ─── 認証・権限 ───
interface Ctx { method: string; path: string; params: Json; body: Json; headers: Record<string, string>; user: DbUser | null }

function requireUser(ctx: Ctx, roles?: ('admin' | 'owner' | 'staff')[]): DbUser {
  const u = ctx.user
  if (!u) fail(401, 'ログインしてください')
  const user = u as DbUser
  if (!user.is_active) { db.session_user_id = null; fail(403, 'このアカウントは停止されています', { code: 'ACCOUNT_DISABLED' }) }
  if (user.store_id !== null && !(db.stores.find((s) => s.id === user.store_id)?.is_active ?? false)) {
    db.session_user_id = null
    fail(403, 'この店舗は利用を停止しています。運営者にお問い合わせください', { code: 'STORE_SUSPENDED' })
  }
  if (roles && !roles.includes(user.role)) fail(403, 'この操作はできません', { code: 'FORBIDDEN' })
  if (user.store_id !== null && user.store_id !== STORE_ID) fail(403, 'デモではこの店舗の画面は開けません', { code: 'FORBIDDEN' })
  return user
}

/** admin の閲覧系は ?store_id= で店舗を選ぶ（デモで中身があるのは店舗 1 だけ） */
function viewStoreOk(ctx: Ctx, user: DbUser): void {
  if (user.role === 'admin' && ctx.params.store_id !== undefined && Number(ctx.params.store_id) !== STORE_ID) {
    fail(422, 'デモでは「喫茶こもれび（デモ）」だけ売上を見られます')
  }
}

// ─── 集計 ───
function totalsOf(sales: DbSale[]): SalesTotals {
  const done = sales.filter((s) => s.status === 'completed')
  const total = done.reduce((n, s) => n + s.total, 0)
  return {
    total, count: done.length, customers: done.reduce((n, s) => n + (s.customer_count ?? 0), 0),
    average: done.length === 0 ? 0 : Math.floor(total / done.length), discount_total: done.reduce((n, s) => n + s.discount_amount, 0),
    cancelled_count: sales.length - done.length,
  }
}
function byTax(sales: DbSale[]): ByTaxRow[] {
  const m = new Map<string, ByTaxRow>()
  for (const s of sales.filter((x) => x.status === 'completed')) {
    const k = `${s.tax_type_name}|${s.tax_rate_permille}`
    const r = m.get(k) ?? { tax_type_name: s.tax_type_name, rate_permille: s.tax_rate_permille, total: 0, tax_amount: 0, taxable_amount: 0 }
    r.total += s.total; r.tax_amount += s.tax_amount; r.taxable_amount = r.total - r.tax_amount
    m.set(k, r)
  }
  return [...m.values()].sort((a, b) => b.rate_permille - a.rate_permille)
}
function byPayment(sales: DbSale[]): ByPaymentRow[] {
  const m = new Map<string, ByPaymentRow>()
  for (const s of sales.filter((x) => x.status === 'completed')) {
    const r = m.get(s.payment_method_name) ?? { payment_method_name: s.payment_method_name, is_cash: s.is_cash, total: 0, count: 0 }
    r.total += s.total; r.count++
    m.set(s.payment_method_name, r)
  }
  return [...m.values()].sort((a, b) => b.total - a.total)
}
function byProduct(sales: DbSale[], limit: number | null = null): ByProductRow[] {
  const m = new Map<string, ByProductRow>()
  for (const s of sales.filter((x) => x.status === 'completed')) {
    // 売れ筋の上位（limit あり）には割引の明細を入れない（docs/10「割引の商品」）
    for (const it of s.items.filter((x) => limit === null || x.unit_price >= 0)) {
      const k = `${it.product_id}|${it.product_name}`
      const r = m.get(k) ?? { product_id: it.product_id, product_name: it.product_name, product_code: it.product_code, product_memo: it.product_memo, quantity: 0, amount: 0 }
      r.quantity += it.quantity; r.amount += it.line_total
      m.set(k, r)
    }
  }
  const rows = [...m.values()].sort((a, b) => b.amount - a.amount)
  return limit === null ? rows : rows.slice(0, limit)
}
/** 07 §7.2 by_category：会計時点のカテゴリの写しでまとめる。値引き前の明細額、未分類は null */
function byCategory(sales: DbSale[]): ByCategoryRow[] {
  const m = new Map<string, ByCategoryRow>()
  for (const s of sales.filter((x) => x.status === 'completed')) {
    for (const it of s.items) {
      const k = `${it.category_id ?? ''}|${it.category_name ?? ''}`
      const r = m.get(k) ?? { category_id: it.category_id, category_name: it.category_name, quantity: 0, amount: 0 }
      r.quantity += it.quantity; r.amount += it.line_total
      m.set(k, r)
    }
  }
  return [...m.values()].sort((a, b) => b.amount - a.amount || (a.category_id ?? -1) - (b.category_id ?? -1)
    || (a.category_name ?? '').localeCompare(b.category_name ?? ''))
}
const salesBetween = (from: string, to: string) => db.sales.filter((s) => s.business_date >= from && s.business_date <= to)
const cashSales = (date: string) => db.sales.filter((s) => s.business_date === date && s.status === 'completed' && s.is_cash).reduce((n, s) => n + s.total, 0)

// ─── 注文 ───
const unpaid = (o: Order) => o.sale_id === null && (o.status === 'pending' || o.status === 'active')
function tableView(t: DbTable): OrderTable {
  const orders = db.orders.filter((o) => o.order_table_id === t.id && unpaid(o))
  const minutes = store().order.customer_session_minutes
  return {
    id: t.id, name: t.name, sort_order: t.sort_order, is_active: t.is_active, opened_at: t.opened_at,
    session_expires_at: t.opened_at === null ? null : isoAt(Date.parse(t.opened_at) + minutes * 60_000),
    unpaid_order_count: orders.length, unpaid_subtotal: orders.reduce((n, o) => n + o.subtotal, 0), token_rotated_at: t.token_rotated_at,
  }
}
const findTable = (id: unknown): DbTable => db.tables.find((t) => t.id === id) ?? notFound()
const findOrder = (id: unknown): DbOrder => db.orders.find((o) => o.id === id) ?? notFound()
function orderView(o: DbOrder): Order {
  const { store_id: _s, ...rest } = o
  void _s
  return rest
}
function stateConflict(): never {
  return fail(409, 'この注文は状態が変わっています。画面を更新してください', { code: 'ORDER_STATE_CONFLICT' })
}

function acceptState(t: DbTable): 'disabled' | 'table_closed' | 'session_expired' | null {
  const s = store()
  if (!s.order.customer_order_enabled) return 'disabled'
  if (t.opened_at === null) return 'table_closed'
  if (Date.now() >= Date.parse(t.opened_at) + s.order.customer_session_minutes * 60_000) return 'session_expired'
  return null
}

function polling() {
  const o = store().order
  if (o.polling_mode !== 'schedule') return { interval_sec: 10, active: o.polling_mode === 'always', next_change_at: null }
  const within = (ms: number) => {
    const { hour, minute } = tokyoParts(ms)
    const m = hour * 60 + minute
    return o.polling_windows.some((w) => {
      const [sh = 0, sm = 0] = w.start.split(':').map(Number)
      const [eh = 0, em = 0] = w.end.split(':').map(Number)
      const s = sh * 60 + sm
      const e = eh * 60 + em
      return s < e ? m >= s && m < e : m >= s || m < e
    })
  }
  const now = Date.now()
  const active = within(now)
  const start = now - (now % 60_000)
  for (let i = 1; i <= 1440; i++) {
    const t = start + i * 60_000
    if (within(t) !== active) return { interval_sec: 10, active, next_change_at: isoAt(t) }
  }
  return { interval_sec: 10, active, next_change_at: null }
}

type OrderLineInput = { product_id: unknown; quantity: unknown; option_ids: unknown; memo?: unknown }
function orderLines(items: unknown, customer: boolean, register = false) {
  if (!Array.isArray(items) || items.length === 0) invalid('items', '商品を選んでください')
  return (items as OrderLineInput[]).map((it) => {
    // 割引の商品はレジだけで使える（注文・お客さんのメニューでは使えない）
    const product = db.products.find((p) => p.id === it.product_id && p.is_active && (register || !p.is_discount) && (!customer || p.customer_visible))
    if (!product) fail(422, '販売を終えた商品が含まれています。画面を更新してください', { code: 'ITEM_UNAVAILABLE' })
    const p = product as Product
    const quantity = int(it.quantity)
    if (!(quantity >= 1 && quantity <= 99)) invalid('items', '数量が正しくありません')
    const optionIds = Array.isArray(it.option_ids) ? (it.option_ids as number[]) : []
    if (optionIds.some((id) => !p.options.some((o) => o.id === id && o.is_active))) fail(422, '選べないオプションが含まれています', { code: 'ITEM_UNAVAILABLE' })
    for (const g of p.option_groups.filter((x) => x.selection === 'single')) {
      if (p.options.filter((o) => o.group_id === g.id && optionIds.includes(o.id)).length > 1) invalid('items', `「${g.name}」は 1 つだけ選べます`)
    }
    return { product: p, quantity, optionIds, memo: nullableStr(it.memo) }
  })
}
function assertOrderable(lines: { product: Product; quantity: number }[], customer: boolean): void {
  if (!store().stock_enabled) return
  for (const l of lines) {
    if (l.product.track_stock && l.product.stock_qty < l.quantity) {
      fail(409, customer ? `「${l.product.name}」は売り切れました` : `「${l.product.name}」の在庫が足りません（残り ${l.product.stock_qty}）`, { code: 'OUT_OF_STOCK' })
    }
  }
}

function publicOrder(o: DbOrder): PublicOrder {
  return {
    order_no: o.order_no, status: o.status, created_at: o.created_at, subtotal: o.subtotal,
    items: o.items.map((i) => ({ product_name: i.product_name, product_memo: i.product_memo, quantity: i.quantity, line_total: i.line_total, memo: i.memo, served: i.served_at !== null, options: i.options.map((x) => x.option_name) })),
  }
}
const tableByToken = (token: string): DbTable => db.tables.find((t) => t.token === token && t.is_active) ?? notFound()

/** 注文の品目がすべて提供済みになったら注文も提供済みにする */
function syncServed(o: DbOrder): void {
  const all = o.items.every((i) => i.served_at !== null)
  if (all && o.served_at === null) o.served_at = nowIso()
  if (!all) o.served_at = null
}

// ─── QR の図（デモ用。読み取れる QR ではない） ───
function fakeQrSvg(seed: string): string {
  let h = 0
  for (const c of seed) h = (Math.imul(h, 31) + c.charCodeAt(0)) | 0
  const n = 25
  const cells: string[] = []
  const finder = (x: number, y: number) => x < 7 && y < 7
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      const inFinder = finder(x, y) || finder(n - 1 - x, y) || finder(x, n - 1 - y)
      let on: boolean
      if (inFinder) {
        const fx = x < 7 ? x : n - 1 - x
        const fy = y < 7 ? y : n - 1 - y
        on = fx === 0 || fx === 6 || fy === 0 || fy === 6 || (fx >= 2 && fx <= 4 && fy >= 2 && fy <= 4)
      } else {
        h = (Math.imul(h, 1103515245) + 12345) | 0
        on = ((h >>> 16) & 1) === 1
      }
      if (on) cells.push(`<rect x="${x + 2}" y="${y + 2}" width="1" height="1"/>`)
    }
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${n + 4} ${n + 4}" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">${cells.join('')}</g></svg>`
}

// ─── ルート ───
type Handler = (ctx: Ctx, m: string[]) => unknown | [number, unknown]
const routes: [string, RegExp, Handler][] = []
const on = (method: string, pattern: string, h: Handler) => routes.push([method, new RegExp(`^${pattern.replace(/\{id\}/g, '(\\d+)').replace(/\{s\}/g, '([^/]+)')}$`), h])
const created = (v: unknown): [number, unknown] => [201, v]
const noContent: [number, unknown] = [204, '']

// 認証（06 §3）
on('POST', '/login', (ctx) => {
  const u = db.users.find((x) => x.login_id === str(ctx.body.login_id))
  if (!u || str(ctx.body.password) === '' || str(ctx.body.password) !== u.password) {
    audit(null, 'login_failed', null, null, { login_id: str(ctx.body.login_id) })
    invalid('login_id', 'ログイン ID またはパスワードが違います')
  }
  const user = u as DbUser
  ctx.user = user
  db.session_user_id = user.id
  requireUser(ctx)
  user.last_login_at = nowIso()
  audit(user, 'login_succeeded', ['user', user.id], null, null)
  clockInNow(user)
  return me(user)
})
on('POST', '/logout', (ctx) => {
  if (ctx.user) clockOutNow(ctx.user)
  db.session_user_id = null
  return noContent
})
on('GET', '/me', (ctx) => me(requireUser(ctx)))
on('PUT', '/me/password', (ctx) => {
  const u = requireUser(ctx)
  if (str(ctx.body.current_password) !== u.password) invalid('current_password', '現在のパスワードが違います')
  if (str(ctx.body.password).length < 8) invalid('password', 'パスワードは 8 文字以上にしてください')
  if (ctx.body.password !== ctx.body.password_confirmation) invalid('password', '確認用のパスワードが一致しません')
  u.password = str(ctx.body.password)
  audit(u, 'password_changed', ['user', u.id], null, null)
  return noContent
})

// レジ（06 §4）
on('GET', '/register/bootstrap', (ctx) => {
  requireUser(ctx, ['owner', 'staff'])
  return {
    store: storeSettings(store()), current_business_date: today(), server_time: nowIso(),
    tax_types: db.tax_types.filter((t) => t.is_active).sort((a, b) => a.sort_order - b.sort_order),
    payment_methods: db.payment_methods.filter((p) => p.is_active).sort((a, b) => a.sort_order - b.sort_order),
    categories: categoriesWithCount(),
    products: sortedProducts().filter((p) => p.is_active).map((p) => ({ ...p, options: p.options.filter((o) => o.is_active) })),
  }
})
on('POST', '/sales', (ctx) => {
  const user = requireUser(ctx, ['owner', 'staff'])
  const uuid = str(ctx.body.client_uuid)
  const dup = db.sales.find((s) => s.client_uuid === uuid)
  if (dup) return dup
  const tax = db.tax_types.find((t) => t.id === ctx.body.tax_type_id && t.is_active) ?? invalid('tax_type_id', '税区分を選んでください')
  const pay = db.payment_methods.find((p) => p.id === ctx.body.payment_method_id && p.is_active) ?? invalid('payment_method_id', '支払方法を選んでください')
  const lines = orderLines(ctx.body.items, false, true)
  const disc = ctx.body.discount as { type: 'amount' | 'percent'; value: number } | null
  let amounts: ReturnType<typeof buildSaleAmounts>
  try {
    amounts = buildSaleAmounts(store(), tax, lines, disc)
  } catch (e) {
    if (e instanceof PricingError && e.reason === 'NEGATIVE_SUBTOTAL') invalid('items', ja.register.discountExceeds)
    throw e
  }
  if (amounts.total !== ctx.body.expected_total) {
    fail(422, '合計金額が変わりました。内容を確認してください', { code: 'TOTAL_MISMATCH', details: { server_total: amounts.total } })
  }
  // 在庫（12 §6.6：在庫管理 ON のときだけ）
  const s = store()
  if (s.stock_enabled) {
    const need = new Map<number, number>()
    for (const l of lines) if (l.product.track_stock) need.set(l.product.id, (need.get(l.product.id) ?? 0) + l.quantity)
    const shortages = [...need].map(([id, q]) => ({ p: findProduct(id), q })).filter((x) => x.p.stock_qty < x.q)
      .map((x) => ({ product_id: x.p.id, product_name: x.p.name, stock_qty: x.p.stock_qty, requested: x.q }))
    if (shortages.length > 0) fail(409, '在庫が足りない商品があります', { code: 'OUT_OF_STOCK', details: { shortages } })
  }
  // 注文から会計（12 §5.15）
  const orderIds = Array.isArray(ctx.body.order_ids) ? (ctx.body.order_ids as number[]) : []
  const orders = orderIds.map((id) => db.orders.find((o) => o.id === id))
  if (orders.some((o) => o === undefined || o.status !== 'active')) {
    fail(409, 'この注文は状態が変わっています。画面を更新してください', { code: 'ORDER_STATE_CONFLICT', details: { order_ids: orderIds } })
  }
  const paid = (orders as DbOrder[]).filter((o) => o.sale_id !== null).map((o) => o.id)
  if (paid.length > 0) fail(409, '会計済みの注文が含まれています。画面を更新してください', { code: 'ORDER_ALREADY_PAID', details: { order_ids: paid } })
  let sale: DbSale
  try {
    sale = makeSale(db, s, {
      tax, pay, lines, discount: disc, soldAt: nowIso(), businessDate: today(), user,
      received: typeof ctx.body.received === 'number' ? ctx.body.received : null,
      customerCount: typeof ctx.body.customer_count === 'number' ? ctx.body.customer_count : null,
      memo: nullableStr(ctx.body.memo), deviceName: nullableStr(ctx.body.device_name),
    }, uuid)
  } catch (e) {
    if (e instanceof PricingError) invalid('received', 'お預かりが足りません')
    throw e
  }
  if (s.stock_enabled) for (const l of lines) if (l.product.track_stock) l.product.stock_qty -= l.quantity
  db.sales.push(sale)
  for (const o of orders as DbOrder[]) o.sale_id = sale.id
  for (const tid of new Set((orders as DbOrder[]).map((o) => o.order_table_id).filter((x): x is number => x !== null))) {
    const t = findTable(tid)
    if (!db.orders.some((o) => o.order_table_id === tid && unpaid(o))) t.opened_at = null
  }
  if (orders.length > 0) db.order_rev++
  return created(sale)
})
on('GET', '/sales/{id}', (ctx, [id]) => {
  const u = requireUser(ctx)
  viewStoreOk(ctx, u)
  return db.sales.find((s) => s.id === Number(id)) ?? notFound()
})
on('POST', '/sales/{id}/cancel', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const sale = db.sales.find((s) => s.id === Number(id)) ?? notFound()
  if (sale.status === 'cancelled') fail(409, 'この会計は取り消し済みです', { code: 'ALREADY_CANCELLED' })
  if (u.role === 'staff' && sale.business_date !== today()) fail(422, 'スタッフは当日の会計のみ取り消せます', { code: 'CANCEL_NOT_ALLOWED' })
  sale.status = 'cancelled'
  sale.cancelled_at = nowIso()
  sale.cancelled_by_name = u.name
  if (store().stock_enabled) {
    for (const it of sale.items) {
      const p = db.products.find((x) => x.id === it.product_id)
      if (p?.track_stock) p.stock_qty += it.quantity
    }
  }
  const linked = db.orders.filter((o) => o.sale_id === sale.id)
  for (const o of linked) o.sale_id = null
  if (linked.length > 0) db.order_rev++
  audit(u, 'sale_cancelled', ['sale', sale.id], { status: 'completed' }, { status: 'cancelled', total: sale.total })
  return sale
})

// 売上・締め（06 §5・§6）
on('GET', '/reports/daily', (ctx) => {
  const u = requireUser(ctx)
  viewStoreOk(ctx, u)
  const date = typeof ctx.params.date === 'string' ? ctx.params.date : today()
  const sales = salesBetween(date, date)
  return {
    date, totals: totalsOf(sales), by_tax: byTax(sales), by_payment: byPayment(sales), by_product: byProduct(sales), by_category: byCategory(sales),
    sales: [...sales].sort((a, b) => b.sold_at.localeCompare(a.sold_at)).map((s) => ({
      id: s.id, sold_at: s.sold_at, total: s.total, payment_method_name: s.payment_method_name, tax_type_name: s.tax_type_name,
      user_name: s.user_name, status: s.status, item_count: s.items.reduce((n, i) => n + i.quantity, 0),
    })),
    closing: db.closings.find((c) => c.business_date === date) ?? null,
    comparison: null,
  }
})
on('GET', '/reports/summary', (ctx) => {
  const u = requireUser(ctx, ['owner', 'admin'])
  viewStoreOk(ctx, u)
  const from = str(ctx.params.from)
  const to = str(ctx.params.to)
  if (from === '' || to === '' || from > to) invalid('from', '期間を正しく選んでください')
  const sales = salesBetween(from, to)
  const byDate = []
  for (let d = from; d <= to; d = shiftYmd(d, 1)) {
    const t = totalsOf(sales.filter((s) => s.business_date === d))
    byDate.push({ date: d, total: t.total, count: t.count, customers: t.customers })
    if (byDate.length > 400) break
  }
  const byHour = Array.from({ length: 24 }, (_, hour) => {
    const t = totalsOf(sales.filter((s) => s.status === 'completed' && tokyoParts(Date.parse(s.sold_at)).hour === hour))
    return { hour, total: t.total, count: t.count }
  })
  return { from, to, totals: totalsOf(sales), by_date: byDate, by_hour: byHour, by_tax: byTax(sales), by_payment: byPayment(sales), ranking: byProduct(sales, 20), by_category: byCategory(sales) }
})
on('GET', '/closings/{s}', (ctx, [date]) => {
  const u = requireUser(ctx)
  viewStoreOk(ctx, u)
  return { business_date: date, cash_sales: cashSales(date ?? ''), closing: db.closings.find((c) => c.business_date === date) ?? null }
})
on('PUT', '/closings/{s}', (ctx, [date]) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const d = date ?? ''
  const float = int(ctx.body.float_amount)
  const counted = int(ctx.body.counted_cash)
  if (!(float >= 0)) invalid('float_amount', '釣り銭準備金を入力してください')
  if (!(counted >= 0)) invalid('counted_cash', '数えた現金を入力してください')
  const cash = cashSales(d)
  const before = db.closings.find((c) => c.business_date === d) ?? null
  const closing: Closing = {
    business_date: d, float_amount: float, cash_sales: cash, expected_cash: float + cash, counted_cash: counted, difference: counted - float - cash,
    memo: nullableStr(ctx.body.memo), changed_after_close: false, user_name: u.name, updated_at: nowIso(),
  }
  db.closings = [...db.closings.filter((c) => c.business_date !== d), closing]
  audit(u, 'closing_saved', ['closing', 1], before === null ? null : { counted_cash: before.counted_cash }, { business_date: d, counted_cash: counted })
  return closing
})

// 商品・カテゴリ・オプション（06 §7）
on('GET', '/products', (ctx) => { requireUser(ctx, ['owner']); return { categories: categoriesWithCount(), products: sortedProducts() } })
function productInput(ctx: Ctx, current: Product | null) {
  const name = str(ctx.body.name).trim()
  if (name === '') invalid('name', '商品名を入力してください')
  const price = int(ctx.body.price)
  if (!(price >= 0 && price <= 9_999_999)) invalid('price', '価格は 0〜9,999,999 の整数で入力してください')
  // 割引の商品（docs/10）：省略時は POST は通常の商品、PUT は現在の値のまま。在庫管理・お客さんのメニューは使わない
  const isDiscount = ctx.body.is_discount === undefined ? (current?.is_discount ?? false) : bool(ctx.body.is_discount)
  if (isDiscount && price < 1) invalid('price', '割引額は 1 円以上で入力してください')
  if (isDiscount && current !== null && (current.options.length > 0 || current.option_groups.length > 0)) invalid('is_discount', 'オプション（グループ）のある商品は割引にできません。先にオプションとグループを削除してください')
  let code = str(ctx.body.code).trim()
  if (code === '') code = current?.code ?? String(Math.max(1000, ...db.products.map((p) => Number(p.code) || 0)) + 1)
  if (db.products.some((p) => p.code === code && p.id !== current?.id)) invalid('code', 'この商品コードはすでに使われています')
  return {
    code, name, memo: nullableStr(ctx.body.memo), price, category_id: typeof ctx.body.category_id === 'number' ? ctx.body.category_id : null,
    color: (str(ctx.body.color) || 'gray') as Product['color'], is_active: bool(ctx.body.is_active), track_stock: !isDiscount && bool(ctx.body.track_stock),
    customer_visible: !isDiscount && bool(ctx.body.customer_visible), is_discount: isDiscount,
  }
}
on('POST', '/products', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const input = productInput(ctx, null)
  const id = nextId(db, 'product')
  const p: Product = { id, ...input, stock_qty: input.is_discount ? 0 : Math.max(0, int(ctx.body.stock_qty) || 0), sort_order: db.products.length + 1, options: [], option_groups: [] }
  db.products.push(p)
  audit(u, 'product_created', ['product', id], null, { name: p.name, price: p.price, is_discount: p.is_discount })
  return created(p)
})
on('PUT', '/products/order', (ctx) => {
  requireUser(ctx, ['owner'])
  const ids = Array.isArray(ctx.body.ids) ? (ctx.body.ids as number[]) : []
  ids.forEach((id, i) => { const p = db.products.find((x) => x.id === id); if (p) p.sort_order = i + 1 })
  return noContent
})
on('POST', '/products/import', () => fail(422, 'デモでは CSV の取り込みは使えません', { code: 'IMPORT_INVALID', errors: { file: ['デモでは CSV の取り込みは使えません'] } }))
on('PUT', '/products/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = findProduct(Number(id))
  const input = productInput(ctx, p)
  const before: Json = {}
  const after: Json = {}
  for (const [k, v] of Object.entries(input)) {
    const old = (p as unknown as Json)[k]
    if (old !== v) { before[k] = old; after[k] = v }
  }
  Object.assign(p, input)
  if (p.is_discount) p.stock_qty = 0
  if (Object.keys(after).length > 0) audit(u, 'product_updated', ['product', p.id], before, after)
  return p
})
on('DELETE', '/products/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = findProduct(Number(id))
  db.products = db.products.filter((x) => x.id !== p.id)
  audit(u, 'product_deleted', ['product', p.id], { name: p.name }, null)
  return noContent
})
on('PATCH', '/products/{id}/stock', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = findProduct(Number(id))
  const v = int(ctx.body.value)
  if (Number.isNaN(v)) invalid('value', '数を入力してください')
  const next = ctx.body.mode === 'add' ? p.stock_qty + v : v
  if (next < 0) invalid('value', '在庫数は 0 未満にできません')
  audit(u, 'product_stock_changed', ['product', p.id], { stock_qty: p.stock_qty }, { stock_qty: next })
  p.stock_qty = next
  return p
})
on('POST', '/categories', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const name = str(ctx.body.name).trim() || invalid('name', 'カテゴリ名を入力してください')
  const c = { id: nextId(db, 'category'), name, sort_order: db.categories.length + 1 }
  db.categories.push(c)
  audit(u, 'category_created', ['category', c.id], null, { name })
  return created({ ...c, product_count: 0 })
})
on('PUT', '/categories/order', (ctx) => {
  requireUser(ctx, ['owner'])
  ;(ctx.body.ids as number[]).forEach((id, i) => { const c = db.categories.find((x) => x.id === id); if (c) c.sort_order = i + 1 })
  return noContent
})
on('PUT', '/categories/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const c = db.categories.find((x) => x.id === Number(id)) ?? notFound()
  const name = str(ctx.body.name).trim() || invalid('name', 'カテゴリ名を入力してください')
  audit(u, 'category_updated', ['category', c.id], { name: c.name }, { name })
  c.name = name
  return categoriesWithCount().find((x) => x.id === c.id)
})
on('DELETE', '/categories/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const c = db.categories.find((x) => x.id === Number(id)) ?? notFound()
  db.categories = db.categories.filter((x) => x.id !== c.id)
  for (const p of db.products) if (p.category_id === c.id) p.category_id = null
  audit(u, 'category_deleted', ['category', c.id], { name: c.name }, null)
  return noContent
})
// docs/10「オプションのグループ」：group_id・is_default は省略すると今の値のまま。最初に選ぶは 1つ選ぶのグループで 1 件だけ
function optionInput(ctx: Ctx, p: Product, current: ProductOption | null) {
  const name = str(ctx.body.name).trim() || invalid('name', 'オプション名を入力してください')
  const price = int(ctx.body.price)
  if (Number.isNaN(price)) invalid('price', '価格を入力してください')
  let group_id = current?.group_id ?? null
  if (ctx.body.group_id !== undefined) {
    group_id = ctx.body.group_id === null ? null : (p.option_groups.find((g) => g.id === ctx.body.group_id)?.id ?? invalid('group_id', 'このグループは使えません'))
  }
  const group = p.option_groups.find((g) => g.id === group_id)
  const is_default = group?.selection === 'single' && (ctx.body.is_default === undefined ? (current?.is_default ?? false) : bool(ctx.body.is_default))
  return { name, price, is_active: bool(ctx.body.is_active), group_id, is_default }
}
function keepSingleDefault(p: Product, o: ProductOption): void {
  if (o.is_default) for (const x of p.options) if (x.id !== o.id && x.group_id === o.group_id) x.is_default = false
}
const findOption = (id: number) => {
  for (const p of db.products) { const o = p.options.find((x) => x.id === id); if (o) return { p, o } }
  return notFound()
}
on('POST', '/products/{id}/options', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = findProduct(Number(id))
  if (p.is_discount) invalid('name', '割引の商品にはオプションを付けられません')
  if (p.options.length >= 10) invalid('name', 'オプションは 1 商品 10 件までです')
  const o: ProductOption = { id: nextId(db, 'option'), product_id: p.id, ...optionInput(ctx, p, null), sort_order: p.options.length + 1 }
  p.options.push(o)
  keepSingleDefault(p, o)
  audit(u, 'option_created', ['option', o.id], null, { name: o.name, price: o.price })
  return created(o)
})
on('PUT', '/products/{id}/options/order', (ctx, [id]) => {
  requireUser(ctx, ['owner'])
  const p = findProduct(Number(id))
  ;(ctx.body.ids as number[]).forEach((oid, i) => { const o = p.options.find((x) => x.id === oid); if (o) o.sort_order = i + 1 })
  p.options.sort((a, b) => a.sort_order - b.sort_order)
  return noContent
})
on('PUT', '/options/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const { p, o } = findOption(Number(id))
  const input = optionInput(ctx, p, o)
  audit(u, 'option_updated', ['option', o.id], { name: o.name, price: o.price, group_id: o.group_id, is_default: o.is_default }, { name: input.name, price: input.price, group_id: input.group_id, is_default: input.is_default })
  Object.assign(o, input)
  keepSingleDefault(p, o)
  return o
})
on('DELETE', '/options/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const { p, o } = findOption(Number(id))
  p.options = p.options.filter((x) => x.id !== o.id)
  audit(u, 'option_deleted', ['option', o.id], { name: o.name }, null)
  return noContent
})

// オプションのグループ（docs/10「オプションのグループ」 #88〜#90）
function groupInput(ctx: Ctx): Pick<ProductOptionGroup, 'name' | 'selection'> {
  const name = str(ctx.body.name).trim() || invalid('name', 'グループ名を入力してください')
  const selection = ctx.body.selection === 'single' || ctx.body.selection === 'multi' ? ctx.body.selection : invalid('selection', '選び方を選んでください')
  return { name, selection }
}
const findGroup = (id: number) => {
  for (const p of db.products) { const g = p.option_groups.find((x) => x.id === id); if (g) return { p, g } }
  return notFound()
}
on('POST', '/products/{id}/option-groups', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = findProduct(Number(id))
  if (p.is_discount) invalid('name', '割引の商品にはオプションを付けられません')
  if (p.option_groups.length >= 3) invalid('name', 'グループは 1 商品 3 つまでです')
  const g: ProductOptionGroup = { id: nextId(db, 'option_group'), product_id: p.id, ...groupInput(ctx), sort_order: p.option_groups.length }
  p.option_groups.push(g)
  audit(u, 'option_group_created', ['product_option_group', g.id], null, { name: g.name, selection: g.selection })
  return created(g)
})
on('PUT', '/option-groups/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const { p, g } = findGroup(Number(id))
  const input = groupInput(ctx)
  audit(u, 'option_group_updated', ['product_option_group', g.id], { name: g.name, selection: g.selection }, input)
  Object.assign(g, input)
  if (g.selection === 'multi') for (const o of p.options) if (o.group_id === g.id) o.is_default = false
  return g
})
on('DELETE', '/option-groups/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const { p, g } = findGroup(Number(id))
  p.option_groups = p.option_groups.filter((x) => x.id !== g.id)
  p.options = p.options.filter((o) => o.group_id !== g.id)
  audit(u, 'option_group_deleted', ['product_option_group', g.id], { name: g.name, selection: g.selection }, null)
  return noContent
})

// 店舗設定（06 §8・12 §5.13）
on('GET', '/settings/store', (ctx) => {
  requireUser(ctx, ['owner'])
  return {
    store: storeSettings(store()),
    tax_types: [...db.tax_types].sort((a, b) => a.sort_order - b.sort_order),
    payment_methods: [...db.payment_methods].sort((a, b) => a.sort_order - b.sort_order),
  }
})
on('PUT', '/settings/store', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const s = store()
  const name = str(ctx.body.name).trim() || invalid('name', '店舗名を入力してください')
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(str(ctx.body.day_cutoff_time))) invalid('day_cutoff_time', '時刻を HH:MM で入力してください')
  const next = { name, price_mode: ctx.body.price_mode, rounding: ctx.body.rounding, day_cutoff_time: ctx.body.day_cutoff_time, stock_enabled: bool(ctx.body.stock_enabled) } as Partial<DbStore>
  const before = storeSettings(s)
  Object.assign(s, next)
  audit(u, 'store_settings_updated', ['store', s.id], before as unknown as Json, storeSettings(s) as unknown as Json)
  return storeSettings(s)
})
function exclusiveDefault(id: number, isDefault: boolean) {
  if (isDefault) for (const t of db.tax_types) t.is_default = t.id === id
}
on('POST', '/tax-types', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const name = str(ctx.body.name).trim() || invalid('name', '名前を入力してください')
  const rate = int(ctx.body.rate_permille)
  if (!(rate >= 0 && rate <= 1000)) invalid('rate_permille', '税率が正しくありません')
  const t = { id: nextId(db, 'tax_type'), name, rate_permille: rate, sort_order: db.tax_types.length + 1, is_default: bool(ctx.body.is_default), is_active: true }
  db.tax_types.push(t)
  exclusiveDefault(t.id, t.is_default)
  audit(u, 'tax_type_created', ['tax_type', t.id], null, { name, rate_permille: rate })
  return created(t)
})
on('PUT', '/tax-types/order', (ctx) => {
  requireUser(ctx, ['owner'])
  ;(ctx.body.ids as number[]).forEach((id, i) => { const t = db.tax_types.find((x) => x.id === id); if (t) t.sort_order = i + 1 })
  return noContent
})
on('PUT', '/tax-types/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const t = db.tax_types.find((x) => x.id === Number(id)) ?? notFound()
  const next = { name: str(ctx.body.name).trim() || t.name, rate_permille: int(ctx.body.rate_permille), is_default: bool(ctx.body.is_default), is_active: bool(ctx.body.is_active) }
  if (next.is_default && !next.is_active) invalid('is_active', '既定の税区分は無効にできません')
  audit(u, 'tax_type_updated', ['tax_type', t.id], { name: t.name, rate_permille: t.rate_permille }, { name: next.name, rate_permille: next.rate_permille })
  Object.assign(t, next)
  exclusiveDefault(t.id, t.is_default)
  return t
})
on('POST', '/payment-methods', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const name = str(ctx.body.name).trim() || invalid('name', '名前を入力してください')
  const p = { id: nextId(db, 'payment_method'), name, is_cash: bool(ctx.body.is_cash), sort_order: db.payment_methods.length + 1, is_active: true }
  db.payment_methods.push(p)
  audit(u, 'payment_method_created', ['payment_method', p.id], null, { name })
  return created(p)
})
on('PUT', '/payment-methods/order', (ctx) => {
  requireUser(ctx, ['owner'])
  ;(ctx.body.ids as number[]).forEach((id, i) => { const p = db.payment_methods.find((x) => x.id === id); if (p) p.sort_order = i + 1 })
  return noContent
})
on('PUT', '/payment-methods/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = db.payment_methods.find((x) => x.id === Number(id)) ?? notFound()
  const next = { name: str(ctx.body.name).trim() || p.name, is_cash: bool(ctx.body.is_cash), is_active: bool(ctx.body.is_active) }
  audit(u, 'payment_method_updated', ['payment_method', p.id], { name: p.name, is_active: p.is_active }, { name: next.name, is_active: next.is_active })
  Object.assign(p, next)
  return p
})
on('GET', '/settings/orders', (ctx) => { requireUser(ctx, ['owner']); return store().order })
on('PUT', '/settings/orders', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const s = store()
  const mode = ctx.body.polling_mode as DbStore['order']['polling_mode']
  const windows = mode === 'schedule' && Array.isArray(ctx.body.polling_windows) ? (ctx.body.polling_windows as { start: string; end: string }[]) : []
  if (mode === 'schedule' && windows.length === 0) invalid('polling_windows', '時間帯を 1 つ以上入れてください')
  windows.forEach((w, i) => { if (w.start === w.end) invalid(`polling_windows.${i}.end`, '開始と終了は別の時刻にしてください') })
  const before = { ...s.order }
  s.order = {
    customer_order_enabled: bool(ctx.body.customer_order_enabled), customer_order_approval: bool(ctx.body.customer_order_approval),
    customer_session_minutes: int(ctx.body.customer_session_minutes) || 180, polling_mode: mode, polling_windows: windows,
  }
  db.order_rev++
  audit(u, 'order_settings_updated', ['store', s.id], before as unknown as Json, s.order as unknown as Json)
  return s.order
})

// スタッフ（06 §9）
const staffOf = () => db.users.filter((u) => u.store_id === STORE_ID && u.role === 'staff').map(publicUser)
on('GET', '/staff', (ctx) => { requireUser(ctx, ['owner']); return staffOf() })
on('POST', '/staff', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const loginId = str(ctx.body.login_id).trim()
  if (!/^[A-Za-z0-9_.-]{3,32}$/.test(loginId)) invalid('login_id', 'ログイン ID は半角英数字 3〜32 文字で入力してください')
  if (db.users.some((x) => x.login_id === loginId)) invalid('login_id', 'このログイン ID はすでに使われています')
  const name = str(ctx.body.name).trim() || invalid('name', '名前を入力してください')
  if (str(ctx.body.password).length < 8) invalid('password', 'パスワードは 8 文字以上にしてください')
  const user: DbUser = { id: nextId(db, 'user'), login_id: loginId, name, role: 'staff', store_id: STORE_ID, is_active: true, last_login_at: null, password: str(ctx.body.password) }
  db.users.push(user)
  audit(u, 'staff_created', ['user', user.id], null, { login_id: loginId, name })
  return created(publicUser(user))
})
on('PUT', '/staff/{id}/password', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const s = db.users.find((x) => x.id === Number(id) && x.role === 'staff') ?? notFound()
  if (str(ctx.body.password).length < 8) invalid('password', 'パスワードは 8 文字以上にしてください')
  s.password = str(ctx.body.password)
  audit(u, 'staff_password_reset', ['user', s.id], null, null)
  return noContent
})
on('PUT', '/staff/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const s = db.users.find((x) => x.id === Number(id) && x.role === 'staff') ?? notFound()
  const next = { name: str(ctx.body.name).trim() || invalid('name', '名前を入力してください'), is_active: bool(ctx.body.is_active) }
  audit(u, 'staff_updated', ['user', s.id], { name: s.name, is_active: s.is_active }, next)
  Object.assign(s, next)
  return publicUser(s)
})

// 操作ログ（06 §10）
on('GET', '/logs', (ctx) => {
  const u = requireUser(ctx, ['owner', 'admin'])
  const page = Math.max(1, Number(ctx.params.page) || 1)
  const labels: Record<string, string> = ja.auditLog.actions
  let rows = [...db.logs].sort((a, b) => b.id - a.id)
  if (typeof ctx.params.action === 'string') rows = rows.filter((r) => r.action === ctx.params.action)
  if (u.role !== 'admin') rows = rows.filter((r) => r.store_id === u.store_id)
  else if (ctx.params.store_id !== undefined) rows = rows.filter((r) => r.store_id === Number(ctx.params.store_id))
  const per = 50
  const data: AuditLogRow[] = rows.slice((page - 1) * per, page * per).map((r) => ({
    id: r.id, created_at: r.created_at, store_name: db.stores.find((s) => s.id === r.store_id)?.name ?? null,
    user_name: db.users.find((x) => x.id === r.user_id)?.name ?? null, action: r.action, action_label: labels[r.action] ?? r.action,
    target_type: r.target_type, target_id: r.target_id, before: r.before, after: r.after, ip: '192.0.2.10',
  }))
  return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(rows.length / per)), total: rows.length } }
})

// admin（06 §11）
on('GET', '/admin/stores', (ctx) => {
  requireUser(ctx, ['admin'])
  return {
    stores: db.stores.map((s) => {
      const bd = businessDate(Date.now(), s.day_cutoff_time)
      const sales = s.id === STORE_ID ? db.sales.filter((x) => x.business_date === bd) : []
      const t = totalsOf(sales)
      const last = sales.filter((x) => x.status === 'completed').map((x) => x.sold_at).sort().pop() ?? null
      return {
        id: s.id, name: s.name, is_active: s.is_active,
        owner_login_ids: db.users.filter((u) => u.store_id === s.id && u.role === 'owner').map((u) => u.login_id),
        staff_count: db.users.filter((u) => u.store_id === s.id && u.role === 'staff').length,
        product_count: s.id === STORE_ID ? db.products.length : 0,
        today: { business_date: bd, total: t.total, count: t.count, last_sold_at: last },
      }
    }),
  }
})
on('PATCH', '/admin/stores/{id}/active', (ctx, [id]) => {
  const u = requireUser(ctx, ['admin'])
  const s = db.stores.find((x) => x.id === Number(id)) ?? notFound()
  s.is_active = bool(ctx.body.is_active)
  audit(u, s.is_active ? 'store_resumed' : 'store_suspended', ['store', s.id], null, { name: s.name })
  return { id: s.id, is_active: s.is_active }
})

// 注文（12 §5.4〜§5.9）
on('GET', '/orders', (ctx) => {
  requireUser(ctx, ['owner', 'staff'])
  const view = str(ctx.params.view) || 'unpaid'
  let rows = db.orders.filter((o) => (view === 'pending' ? o.status === 'pending' : view === 'today' ? o.business_date === today() : o.status === 'active' && o.sale_id === null))
  if (ctx.params.table_id !== undefined) rows = rows.filter((o) => o.order_table_id === Number(ctx.params.table_id))
  return { orders: rows.sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id - b.id).map(orderView) }
})
on('POST', '/orders', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const dup = db.orders.find((o) => o.client_uuid === str(ctx.body.client_uuid))
  if (dup) return orderView(dup)
  let table: DbTable | null = null
  if (ctx.body.order_table_id !== null && ctx.body.order_table_id !== undefined) {
    table = db.tables.find((t) => t.id === ctx.body.order_table_id && t.is_active) ?? invalid('order_table_id', 'このテーブルは使えません')
  }
  const lines = orderLines(ctx.body.items, false)
  assertOrderable(lines, false)
  const subtotal = lines.reduce((n, l) => n + (l.product.price + l.product.options.filter((o) => l.optionIds.includes(o.id)).reduce((m, o) => m + o.price, 0)) * l.quantity, 0)
  if (subtotal !== ctx.body.expected_subtotal) fail(422, '金額が変わりました。内容を確認してください', { code: 'TOTAL_MISMATCH', details: { server_subtotal: subtotal } })
  if (table && table.opened_at === null) table.opened_at = nowIso()
  const order = addOrder(db, store(), {
    table, source: 'staff', createdAt: nowIso(), items: lines, served: 'none', user: u, label: nullableStr(ctx.body.label), note: nullableStr(ctx.body.note), clientUuid: str(ctx.body.client_uuid),
  })
  return created(orderView(order))
})
on('POST', '/orders/{id}/accept', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const o = findOrder(Number(id))
  if (o.status !== 'pending') stateConflict()
  o.status = 'active'
  db.order_rev++
  audit(u, 'order_accepted', ['order', o.id], { status: 'pending' }, { status: 'active', order_no: o.order_no })
  return orderView(o)
})
on('POST', '/orders/{id}/cancel', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const o = findOrder(Number(id))
  if (o.status === 'cancelled') stateConflict()
  if (o.sale_id !== null) fail(409, '会計済みの注文は取り消せません', { code: 'ORDER_ALREADY_PAID' })
  const before = o.status
  o.status = 'cancelled'
  db.order_rev++
  audit(u, 'order_cancelled', ['order', o.id], { status: before }, { status: 'cancelled', order_no: o.order_no })
  return orderView(o)
})
on('POST', '/orders/{id}/serve-all', (ctx, [id]) => {
  requireUser(ctx, ['owner', 'staff'])
  const o = findOrder(Number(id))
  if (o.status !== 'active') stateConflict()
  const t = nowIso()
  for (const i of o.items) i.served_at ??= t
  o.served_at ??= t
  db.order_rev++
  return orderView(o)
})
on('PATCH', '/order-items/{id}/served', (ctx, [id]) => {
  requireUser(ctx, ['owner', 'staff'])
  const o = db.orders.find((x) => x.items.some((i) => i.id === Number(id))) ?? notFound()
  if (o.status !== 'active') stateConflict()
  const item = o.items.find((i) => i.id === Number(id)) ?? notFound()
  item.served_at = ctx.body.served === true ? item.served_at ?? nowIso() : null
  syncServed(o)
  db.order_rev++
  return orderView(o)
})
on('GET', '/kitchen/orders', (ctx) => {
  requireUser(ctx, ['owner', 'staff'])
  const etag = `"o-${STORE_ID}-${db.order_rev}"`
  if (ctx.headers['if-none-match'] === etag) return [304, '', { etag }]
  const active = db.orders.filter((o) => o.status === 'active')
  return [200, {
    server_time: nowIso(), polling: polling(),
    in_progress: active.filter((o) => o.served_at === null).sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id - b.id).slice(0, 200).map(orderView),
    done: active.filter((o) => o.served_at !== null && o.business_date === today()).sort((a, b) => (b.served_at ?? '').localeCompare(a.served_at ?? '') || b.id - a.id).slice(0, 30).map(orderView),
    pending_count: db.orders.filter((o) => o.status === 'pending').length,
  }, { etag }]
})

// テーブル（12 §5.11・§5.12）
on('GET', '/order-tables', (ctx) => {
  requireUser(ctx, ['owner', 'staff'])
  return { tables: [...db.tables].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id).map(tableView) }
})
on('POST', '/order-tables', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const name = str(ctx.body.name).trim() || invalid('name', 'テーブル名を入力してください')
  if (db.tables.some((t) => t.name === name)) invalid('name', 'この名前のテーブルはすでにあります')
  const id = nextId(db, 'table')
  const t: DbTable = { id, name, sort_order: db.tables.length + 1, is_active: true, opened_at: null, token: `demo-${id}-${Date.now().toString(36)}`, token_rotated_at: nowIso() }
  db.tables.push(t)
  db.order_rev++
  audit(u, 'order_table_created', ['order_table', id], null, { name })
  return created(tableView(t))
})
on('PUT', '/order-tables/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const t = findTable(Number(id))
  const name = str(ctx.body.name).trim() || invalid('name', 'テーブル名を入力してください')
  if (db.tables.some((x) => x.name === name && x.id !== t.id)) invalid('name', 'この名前のテーブルはすでにあります')
  audit(u, 'order_table_updated', ['order_table', t.id], { name: t.name, is_active: t.is_active }, { name, is_active: bool(ctx.body.is_active) })
  t.name = name
  t.sort_order = int(ctx.body.sort_order) || t.sort_order
  t.is_active = bool(ctx.body.is_active)
  db.order_rev++
  return tableView(t)
})
on('DELETE', '/order-tables/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const t = findTable(Number(id))
  if (db.orders.some((o) => o.order_table_id === t.id && unpaid(o))) fail(409, '未会計の注文があるテーブルは削除できません', { code: 'TABLE_HAS_UNPAID_ORDERS' })
  db.tables = db.tables.filter((x) => x.id !== t.id)
  db.order_rev++
  audit(u, 'order_table_deleted', ['order_table', t.id], { name: t.name }, null)
  return noContent
})
on('POST', '/order-tables/{id}/token', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const t = findTable(Number(id))
  t.token = `demo-${t.id}-${Date.now().toString(36)}`
  t.token_rotated_at = nowIso()
  t.opened_at = null
  db.order_rev++
  audit(u, 'order_table_token_regenerated', ['order_table', t.id], null, { name: t.name })
  return tableView(t)
})
on('GET', '/order-tables/{id}/qr', (ctx, [id]) => {
  requireUser(ctx, ['owner'])
  const t = findTable(Number(id))
  return { url: `https://example.invalid/t/${t.token}`, svg: fakeQrSvg(t.token) }
})
on('POST', '/order-tables/{id}/open', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const t = findTable(Number(id))
  const before = t.opened_at
  t.opened_at = nowIso()
  db.order_rev++
  audit(u, 'order_table_opened', ['order_table', t.id], { opened_at: before }, { name: t.name, opened_at: t.opened_at })
  return tableView(t)
})
on('POST', '/order-tables/{id}/close', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const t = findTable(Number(id))
  const before = t.opened_at
  t.opened_at = null
  db.order_rev++
  audit(u, 'order_table_closed', ['order_table', t.id], { opened_at: before }, { name: t.name, opened_at: null })
  return tableView(t)
})

// お客さんの公開 API（12 §5.1〜§5.3）
on('GET', '/public/tables/{s}/menu', (_ctx, [token]) => {
  const t = tableByToken(decodeURIComponent(token ?? ''))
  const s = store()
  const reason = acceptState(t)
  const products = sortedProducts().filter((p) => p.is_active && p.customer_visible && !p.is_discount)
  return {
    store_name: s.name, table_name: t.name, price_mode: s.price_mode, accepting: reason === null, not_accepting_reason: reason,
    categories: categoriesWithCount().filter((c) => products.some((p) => p.category_id === c.id)).map((c) => ({ id: c.id, name: c.name })),
    products: products.map((p) => ({
      id: p.id, category_id: p.category_id, name: p.name, memo: p.memo, price: p.price, color: p.color,
      sold_out: s.stock_enabled && p.track_stock && p.stock_qty <= 0,
      options: p.options.filter((o) => o.is_active).map((o) => ({ id: o.id, name: o.name, price: o.price, group_id: o.group_id, is_default: o.is_default })),
      option_groups: p.option_groups.map((g) => ({ id: g.id, name: g.name, selection: g.selection })),
    })),
    limits: { max_items: 30, max_quantity: 20, max_orders_per_session: 20 },
  }
})
function sessionOrders(t: DbTable): DbOrder[] {
  if (t.opened_at === null) return []
  const opened = t.opened_at
  return db.orders.filter((o) => o.order_table_id === t.id && o.created_at >= opened).sort((a, b) => a.created_at.localeCompare(b.created_at))
}
on('GET', '/public/tables/{s}/orders', (_ctx, [token]) => ({ orders: sessionOrders(tableByToken(decodeURIComponent(token ?? ''))).map(publicOrder) }))
on('POST', '/public/tables/{s}/orders', (ctx, [token]) => createCustomerOrder(tableByToken(decodeURIComponent(token ?? '')), ctx.body))

function createCustomerOrder(t: DbTable, body: Json): [number, unknown] {
  const dup = db.orders.find((o) => o.client_uuid === str(body.client_uuid))
  if (dup) return [200, publicOrder(dup)]
  const reason = acceptState(t)
  if (reason !== null) fail(409, '注文を受け付けていません。店員にお声がけください', { code: 'ORDER_NOT_ACCEPTING', details: { reason } })
  const lines = orderLines(body.items, true)
  if (lines.reduce((n, l) => n + l.quantity, 0) > 50) fail(422, '一度に注文できる数量は合計 50 までです', { code: 'ORDER_LIMIT_EXCEEDED', details: { limit: 'quantity' } })
  if (sessionOrders(t).filter((o) => o.source === 'customer').length >= 20) {
    fail(422, 'このテーブルから注文できる回数の上限に達しました。店員にお声がけください', { code: 'ORDER_LIMIT_EXCEEDED', details: { limit: 'orders_per_session' } })
  }
  assertOrderable(lines, true)
  const s = store()
  const order = addOrder(db, s, {
    table: t, source: 'customer', createdAt: nowIso(), items: lines, served: 'none', user: null, label: null, note: nullableStr(body.note),
    status: s.order.customer_order_approval ? 'pending' : 'active', clientUuid: str(body.client_uuid),
  })
  if (order.subtotal !== body.expected_subtotal && body.expected_subtotal !== undefined) {
    db.orders = db.orders.filter((o) => o.id !== order.id)
    fail(422, '金額が変わりました。メニューを読み直してください', { code: 'TOTAL_MISMATCH' })
  }
  audit(null, 'order_created', ['order', order.id], null, { order_no: order.order_no, table_name: t.name, item_count: lines.reduce((n, l) => n + l.quantity, 0), subtotal: order.subtotal })
  return [201, publicOrder(order)]
}

/** デモの操作：お客さんの注文を 1 件届ける（厨房の自動更新を見るため） */
export function demoCustomerOrder(): string {
  const t = db.tables.find((x) => x.is_active && x.opened_at !== null) ?? db.tables.find((x) => x.is_active)
  if (!t) return 'テーブルがありません'
  if (t.opened_at === null) t.opened_at = nowIso()
  const menu = db.products.filter((p) => p.is_active && p.customer_visible && !p.is_discount && !(store().stock_enabled && p.track_stock && p.stock_qty <= 0))
  const a = menu[Math.floor(Math.random() * menu.length)]
  const b = menu[Math.floor(Math.random() * menu.length)]
  if (!a || !b) return '注文できる商品がありません'
  const items = a.id === b.id ? [{ product_id: a.id, quantity: 2, option_ids: [] }] : [{ product_id: a.id, quantity: 1, option_ids: [] }, { product_id: b.id, quantity: 1, option_ids: [] }]
  const subtotal = items.reduce((n, i) => n + (findProduct(i.product_id).price * i.quantity), 0)
  try {
    createCustomerOrder(t, { client_uuid: `demo-${Date.now()}`, items, note: null, expected_subtotal: subtotal })
    changed()
    return `${t.name} からお客さんの注文が届きました`
  } catch (e) {
    return e instanceof HttpError ? String(e.body.message) : '注文を作れませんでした'
  }
}

// 勤怠（13 §5 #66〜#80）。集計の割増はデモ用の簡略版（1 日 8 時間を超えた分・22〜5 時・法定休日）
const openAttendance = (userId: number) => db.attendances.find((a) => a.user_id === userId && a.clock_out_at === null) ?? null
const isWorkingUser = (u: DbUser) => u.store_id === STORE_ID && u.is_active && u.role !== 'admin'
const wageOf = (userId: number): DbWage => db.wages[String(userId)] ?? { hourly_wage: null, overtime_exempt: false }
const msOf = (iso: string) => Date.parse(iso)
const minutesBetween = (a: string, b: string) => Math.max(0, Math.floor((msOf(b) - msOf(a)) / 60_000))

function clockInNow(u: DbUser): void {
  if (!isWorkingUser(u) || openAttendance(u.id)) return
  const a: DbAttendance = { id: nextId(db, 'attendance'), store_id: STORE_ID, user_id: u.id, business_date: today(), clock_in_at: nowIso(), clock_out_at: null, breaks: [], edited: false }
  db.attendances.push(a)
  audit(u, 'attendance_clocked_in', ['attendance', a.id], null, { clock_in_at: a.clock_in_at })
}
function clockOutNow(u: DbUser): void {
  const a = openAttendance(u.id)
  if (!a) return
  const now = nowIso()
  for (const b of a.breaks) if (b.ended_at === null) b.ended_at = now
  a.clock_out_at = now
  audit(u, 'attendance_clocked_out', ['attendance', a.id], null, { clock_out_at: now })
}
function meAttendance(u: DbUser): Me['attendance'] {
  const a = openAttendance(u.id)
  return a ? { id: a.id, clock_in_at: a.clock_in_at, on_break: a.breaks.some((b) => b.ended_at === null) } : null
}
function laborWarnings(): LaborWarning[] {
  const w: LaborWarning[] = []
  if (db.labor.weekly_hours_limit === null) w.push('weekly_hours_limit')
  if (db.labor.week_start_day === null) w.push('week_start_day')
  if (db.labor.legal_holiday_day === null) w.push('legal_holiday_day')
  if (db.labor.minimum_wage === null) w.push('minimum_wage')
  if (db.users.some((u) => isWorkingUser(u) && wageOf(u.id).hourly_wage === null)) w.push('hourly_wage')
  return w
}

function breakMinutes(a: DbAttendance): number {
  return a.breaks.reduce((n, b) => n + (b.ended_at === null ? 0 : minutesBetween(b.started_at, b.ended_at)), 0)
}
function attendanceRow(a: DbAttendance, owner: boolean): Attendance {
  const u = db.users.find((x) => x.id === a.user_id)
  const brk = breakMinutes(a)
  const status: AttendanceStatus = a.clock_out_at !== null ? 'closed' : a.business_date < today() ? 'stale' : a.breaks.some((b) => b.ended_at === null) ? 'on_break' : 'working'
  return {
    id: a.id, user_id: a.user_id, user_name: u?.name ?? '', business_date: a.business_date, clock_in_at: a.clock_in_at, clock_out_at: a.clock_out_at,
    breaks: a.breaks, break_minutes: brk, work_minutes: a.clock_out_at === null ? null : Math.max(0, minutesBetween(a.clock_in_at, a.clock_out_at) - brk),
    status, edited: a.edited, ...(owner ? { hourly_wage: wageOf(a.user_id).hourly_wage } : {}),
  }
}
const monthParam = (v: unknown): string => {
  const m = str(v)
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(m)) invalid('month', '月を YYYY-MM で指定してください')
  return m
}

/** 'YYYY-MM-DDTHH:MM'（日本時間）→ ISO */
function localToIso(field: string, v: unknown, required: boolean): string | null {
  const s = str(v)
  if (s === '') return required ? invalid(field, '日時を入力してください') : null
  const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/.exec(s)
  if (!m) return invalid(field, '日時の形式が正しくありません')
  return tokyoIso(m[1] as string, Number(m[2]), Number(m[3]))
}
function attendanceFromBody(ctx: Ctx, userId: number, ignoreId: number | null): Omit<DbAttendance, 'id' | 'store_id' | 'user_id' | 'edited'> {
  const inAt = localToIso('clock_in_at', ctx.body.clock_in_at, true) as string
  const outAt = localToIso('clock_out_at', ctx.body.clock_out_at, false)
  if (outAt !== null && msOf(outAt) <= msOf(inAt)) invalid('clock_out_at', '退勤は出勤より後にしてください')
  const rawBreaks = Array.isArray(ctx.body.breaks) ? (ctx.body.breaks as Json[]) : []
  const end = outAt ?? nowIso()
  const breaks: AttendanceBreak[] = rawBreaks.map((b, i) => {
    const s = localToIso(`breaks.${i}.started_at`, b.started_at, true) as string
    const e = localToIso(`breaks.${i}.ended_at`, b.ended_at, outAt !== null)
    if (msOf(s) < msOf(inAt) || msOf(s) > msOf(end) || (e !== null && (msOf(e) <= msOf(s) || msOf(e) > msOf(end)))) invalid(`breaks.${i}.started_at`, '休憩は出勤から退勤の間にしてください')
    return { id: nextId(db, 'attendance_break'), started_at: s, ended_at: e }
  })
  const endMs = outAt === null ? Number.POSITIVE_INFINITY : msOf(outAt)
  const overlap = db.attendances.some((a) => a.user_id === userId && a.id !== ignoreId
    && msOf(a.clock_in_at) < endMs && (a.clock_out_at === null ? Number.POSITIVE_INFINITY : msOf(a.clock_out_at)) > msOf(inAt))
  if (overlap) fail(422, 'ほかの打刻と時間が重なっています', { code: 'ATTENDANCE_OVERLAP', errors: { clock_in_at: ['ほかの打刻と時間が重なっています'] } })
  return { business_date: businessDate(msOf(inAt), store().day_cutoff_time), clock_in_at: inAt, clock_out_at: outAt, breaks }
}

/** 22:00〜翌 5:00 と重なる分（休憩は差し引かない簡略版） */
function nightMinutes(a: DbAttendance): number {
  if (a.clock_out_at === null) return 0
  const s = msOf(a.clock_in_at)
  const e = msOf(a.clock_out_at)
  let n = 0
  for (let d = -1; d <= 1; d++) {
    const ns = Date.parse(tokyoIso(shiftYmd(a.business_date, d), 22, 0))
    n += Math.max(0, Math.min(e, ns + 7 * 3_600_000) - Math.max(s, ns)) / 60_000
  }
  return Math.floor(n)
}

function summary(month: string): AttendanceSummary {
  const members = db.users.filter((u) => u.store_id === STORE_ID && u.role !== 'admin')
  const rows: AttendanceSummaryRow[] = []
  for (const u of members) {
    const list = db.attendances.filter((a) => a.user_id === u.id && a.business_date.startsWith(month))
    const scheduled = db.shifts.filter((s) => s.user_id === u.id && s.date.startsWith(month)).reduce((n, s) => n + s.planned_minutes, 0)
    if (list.length === 0 && !u.is_active && scheduled === 0) continue
    const wage = wageOf(u.id)
    let work = 0
    let overtime = 0
    let night = 0
    let holiday = 0
    const shortage: string[] = []
    for (const a of list) {
      const r = attendanceRow(a, true)
      if (r.work_minutes === null) continue
      work += r.work_minutes
      const isHoliday = db.labor.legal_holiday_day !== null && new Date(`${a.business_date}T00:00:00Z`).getUTCDay() === db.labor.legal_holiday_day
      if (isHoliday) holiday += r.work_minutes
      else if (!wage.overtime_exempt) overtime += Math.max(0, r.work_minutes - 480)
      night += nightMinutes(a)
      if ((r.work_minutes > 480 && r.break_minutes < 60) || (r.work_minutes > 360 && r.break_minutes < 45)) shortage.push(a.business_date)
    }
    const over60 = Math.max(0, overtime - 3600)
    const w = wage.hourly_wage
    const base = w === null ? null : Math.floor((w * work) / 60)
    const premium = w === null ? null : Math.floor((w * (overtime * 25 + over60 * 25 + night * 25 + holiday * 35)) / 6000)
    const openCount = list.filter((a) => a.clock_out_at === null && a.business_date < today()).length
    const warnings: SummaryWarning[] = []
    if (w === null) warnings.push('wage_missing')
    else if (db.labor.minimum_wage !== null && w < db.labor.minimum_wage) warnings.push('below_minimum_wage')
    if (overtime > 45 * 60) warnings.push('overtime_45h')
    if (shortage.length > 0) warnings.push('break_shortage')
    if (openCount > 0) warnings.push('open_attendance')
    rows.push({
      user_id: u.id, name: u.name, role: u.role, is_active: u.is_active, hourly_wage: w, overtime_exempt: wage.overtime_exempt,
      days: new Set(list.map((a) => a.business_date)).size, work_minutes: work, overtime_minutes: overtime, overtime_over60_minutes: over60,
      night_minutes: night, holiday_minutes: holiday, scheduled_minutes: scheduled, open_count: openCount,
      base_pay: base, premium_pay: premium, total_pay: base === null || premium === null ? null : base + premium,
      break_shortage_dates: shortage, warnings,
    })
  }
  const sum = (f: (r: AttendanceSummaryRow) => number) => rows.reduce((n, r) => n + f(r), 0)
  const sumPay = (f: (r: AttendanceSummaryRow) => number | null) => (rows.some((r) => f(r) === null) ? null : sum((r) => f(r) ?? 0))
  return {
    month, settings: { ...db.labor }, warnings: laborWarnings(), rows,
    totals: {
      days: sum((r) => r.days), work_minutes: sum((r) => r.work_minutes), overtime_minutes: sum((r) => r.overtime_minutes),
      night_minutes: sum((r) => r.night_minutes), holiday_minutes: sum((r) => r.holiday_minutes), scheduled_minutes: sum((r) => r.scheduled_minutes),
      base_pay: sumPay((r) => r.base_pay), premium_pay: sumPay((r) => r.premium_pay), total_pay: sumPay((r) => r.total_pay),
    },
  }
}

on('GET', '/operators', () => ({
  operators: db.users.filter((u) => isWorkingUser(u) && openAttendance(u.id) !== null)
    .map((u) => ({ id: u.id, name: u.name, role: u.role, on_break: meAttendance(u)?.on_break ?? false })),
}))
on('POST', '/operators/switch', (ctx) => {
  const target = db.users.find((u) => u.id === ctx.body.user_id && isWorkingUser(u) && openAttendance(u.id) !== null)
  if (!target) return fail(404, 'この人は今は勤務中ではありません', { code: 'OPERATOR_UNAVAILABLE' })
  if (target.role === 'owner' && str(ctx.body.password) !== target.password) invalid('password', 'パスワードが違います')
  const before = ctx.user
  db.session_user_id = target.id
  audit(target, 'operator_switched', ['user', target.id], { user_id: before?.id ?? null }, { user_id: target.id })
  return me(target)
})
on('POST', '/attendance/clock-in', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  if (openAttendance(u.id)) fail(409, 'すでに出勤しています', { code: 'ATTENDANCE_STATE' })
  if (str(ctx.body.password) !== u.password) invalid('password', 'パスワードが違います')
  clockInNow(u)
  return me(u)
})
on('POST', '/attendance/break-start', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const a = openAttendance(u.id)
  if (!a || a.breaks.some((b) => b.ended_at === null)) return fail(409, '勤務中ではありません', { code: 'ATTENDANCE_STATE' })
  a.breaks.push({ id: nextId(db, 'attendance_break'), started_at: nowIso(), ended_at: null })
  audit(u, 'attendance_break_started', ['attendance', a.id], null, null)
  return me(u)
})
on('POST', '/attendance/break-end', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const a = openAttendance(u.id)
  const b = a?.breaks.find((x) => x.ended_at === null)
  if (!a || !b) return fail(409, '休憩中ではありません', { code: 'ATTENDANCE_STATE' })
  b.ended_at = nowIso()
  audit(u, 'attendance_break_ended', ['attendance', a.id], null, null)
  return me(u)
})
on('GET', '/attendances', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const month = monthParam(ctx.params.month)
  const owner = u.role === 'owner'
  const filter = ctx.params.user_id
  const userId = owner ? (typeof filter === 'number' || (typeof filter === 'string' && filter !== '') ? Number(filter) : null) : u.id
  const attendances = db.attendances
    .filter((a) => a.business_date.startsWith(month) && (userId === null || a.user_id === userId))
    .sort((a, b) => (a.clock_in_at < b.clock_in_at ? -1 : 1))
    .map((a) => attendanceRow(a, owner))
  return { month, attendances }
})
on('POST', '/attendances', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const target = db.users.find((x) => x.id === ctx.body.user_id && x.store_id === STORE_ID && x.role !== 'admin')
  if (!target) return invalid('user_id', '従業員を選んでください')
  const a: DbAttendance = { id: nextId(db, 'attendance'), store_id: STORE_ID, user_id: target.id, edited: true, ...attendanceFromBody(ctx, target.id, null) }
  db.attendances.push(a)
  audit(u, 'attendance_created', ['attendance', a.id], null, { user_id: a.user_id, clock_in_at: a.clock_in_at, clock_out_at: a.clock_out_at })
  return created(attendanceRow(a, true))
})
on('PUT', '/attendances/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const a = db.attendances.find((x) => x.id === Number(id)) ?? notFound()
  const before = { clock_in_at: a.clock_in_at, clock_out_at: a.clock_out_at }
  Object.assign(a, attendanceFromBody(ctx, a.user_id, a.id), { edited: true })
  audit(u, 'attendance_updated', ['attendance', a.id], before, { clock_in_at: a.clock_in_at, clock_out_at: a.clock_out_at })
  return attendanceRow(a, true)
})
on('DELETE', '/attendances/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const a = db.attendances.find((x) => x.id === Number(id)) ?? notFound()
  db.attendances = db.attendances.filter((x) => x !== a)
  audit(u, 'attendance_deleted', ['attendance', a.id], { user_id: a.user_id, clock_in_at: a.clock_in_at }, null)
  return noContent
})
on('GET', '/attendances/summary', (ctx) => {
  requireUser(ctx, ['owner'])
  return summary(monthParam(ctx.params.month))
})
on('GET', '/settings/labor', (ctx) => {
  requireUser(ctx, ['owner'])
  return { ...db.labor, warnings: laborWarnings() }
})
on('PUT', '/settings/labor', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const before = { ...db.labor }
  const intOrNull = (v: unknown) => (typeof v === 'number' && Number.isInteger(v) ? v : null)
  const limit = intOrNull(ctx.body.weekly_hours_limit)
  if (limit !== null && limit !== 40 && limit !== 44) invalid('weekly_hours_limit', '40 か 44 を選んでください')
  const wage = intOrNull(ctx.body.minimum_wage)
  if (wage !== null && (wage < 1 || wage > 9999)) invalid('minimum_wage', '1〜9999 円で入力してください')
  db.labor = { weekly_hours_limit: limit, week_start_day: intOrNull(ctx.body.week_start_day), legal_holiday_day: intOrNull(ctx.body.legal_holiday_day), minimum_wage: wage }
  audit(u, 'labor_settings_updated', ['store', STORE_ID], before, { ...db.labor })
  return { ...db.labor, warnings: laborWarnings() }
})
const laborMember = (u: DbUser): LaborMember => ({ id: u.id, name: u.name, role: u.role, is_active: u.is_active, ...wageOf(u.id) })
on('GET', '/labor-members', (ctx) => {
  requireUser(ctx, ['owner'])
  return { members: db.users.filter((u) => u.store_id === STORE_ID && u.role !== 'admin').map(laborMember) }
})
on('PUT', '/labor-members/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const target = db.users.find((x) => x.id === Number(id) && x.store_id === STORE_ID && x.role !== 'admin') ?? notFound()
  const w = ctx.body.hourly_wage
  if (w !== null && (typeof w !== 'number' || !Number.isInteger(w) || w < 1 || w > 99999)) invalid('hourly_wage', '1〜99999 円で入力してください')
  const before = wageOf(target.id)
  db.wages[String(target.id)] = { hourly_wage: typeof w === 'number' ? w : null, overtime_exempt: bool(ctx.body.overtime_exempt) }
  audit(u, 'labor_member_updated', ['user', target.id], { ...before }, { ...wageOf(target.id) })
  return laborMember(target)
})

// 勤務表（13 §5 #81〜#87）
function shiftMonthOf(month: string): ShiftMonth {
  const m = db.shift_months.find((x) => x.month === month) ?? { month, request_deadline: null, published_at: null, memo: null }
  return { ...m, accepting_requests: m.request_deadline === null || today() <= m.request_deadline }
}
const shiftMembers = () => db.users.filter((u) => u.store_id === STORE_ID && u.role !== 'admin').map((u) => ({ id: u.id, name: u.name, role: u.role, is_active: u.is_active }))
const timeOk = (v: unknown) => /^([01]?\d|2\d|3[0-5]):[0-5]\d$/.test(str(v))
const activePatterns = () => db.shift_patterns.filter((p) => p.is_active).sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : a.id - b.id))
function shiftFromBody(ctx: Ctx, current: Shift | null): Omit<Shift, 'id'> {
  const ignoreId = current?.id ?? null
  const user = db.users.find((u) => u.id === ctx.body.user_id && u.store_id === STORE_ID && u.role !== 'admin')
  if (!user) return invalid('user_id', '従業員を選んでください')
  const date = str(ctx.body.date)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) invalid('date', '日付を選んでください')
  if (ctx.body.pattern_id !== null && ctx.body.pattern_id !== undefined) {
    const p = db.shift_patterns.find((x) => x.id === ctx.body.pattern_id)
    if (!p || (!p.is_active && current?.pattern_id !== p.id)) return invalid('pattern_id', '選んだ区分は使えません。選び直してください')
    ctx.body.start_time = p.start_time
    ctx.body.end_time = p.end_time
    ctx.body.break_minutes = p.break_minutes
    const s = shiftFromBody({ ...ctx, body: { ...ctx.body, pattern_id: null } }, current)
    return { ...s, pattern_id: p.id, pattern_name: p.name, segments: p.segments.map((x) => ({ ...x })) }
  }
  if (!timeOk(ctx.body.start_time)) invalid('start_time', '開始を HH:MM で入力してください')
  if (!timeOk(ctx.body.end_time)) invalid('end_time', '終了を HH:MM で入力してください')
  const start = hmToMinutes(str(ctx.body.start_time))
  const end = hmToMinutes(str(ctx.body.end_time))
  if (end <= start) invalid('end_time', '終了は開始より後にしてください')
  const brk = typeof ctx.body.break_minutes === 'number' ? ctx.body.break_minutes : 0
  if (brk < 0 || brk >= end - start) invalid('break_minutes', '休憩が長すぎます')
  const overlap = db.shifts.some((s) => s.id !== ignoreId && s.user_id === user.id && s.date === date && hmToMinutes(s.start_time) < end && hmToMinutes(s.end_time) > start)
  if (overlap) fail(422, 'ほかの予定と重なっています', { code: 'SHIFT_OVERLAP', errors: { start_time: ['ほかの予定と重なっています'] } })
  const pad = (v: unknown) => str(v).padStart(5, '0')
  return { user_id: user.id, date, start_time: pad(ctx.body.start_time), end_time: pad(ctx.body.end_time), break_minutes: brk, note: nullableStr(ctx.body.note), planned_minutes: end - start - brk, pattern_id: null, pattern_name: null, segments: null }
}
on('GET', '/shifts', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const month = monthParam(ctx.params.month)
  const info = shiftMonthOf(month)
  const owner = u.role === 'owner'
  const visible = owner || info.published_at !== null
  return {
    month: info,
    shifts: visible ? db.shifts.filter((s) => s.date.startsWith(month)).sort((a, b) => (a.date + a.start_time < b.date + b.start_time ? -1 : 1)) : [],
    requests: owner ? db.shift_requests.filter((r) => r.date.startsWith(month)) : [],
    members: shiftMembers(),
    patterns: activePatterns(),
  }
})
on('PUT', '/shift-months', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const month = monthParam(ctx.body.month)
  const deadline = nullableStr(ctx.body.request_deadline)
  if (deadline !== null && !/^\d{4}-\d{2}-\d{2}$/.test(deadline)) invalid('request_deadline', '日付を選んでください')
  let m = db.shift_months.find((x) => x.month === month)
  if (!m) {
    m = { month, request_deadline: null, published_at: null, memo: null }
    db.shift_months.push(m)
  }
  const before = { ...m }
  m.request_deadline = deadline
  m.memo = nullableStr(ctx.body.memo)
  m.published_at = bool(ctx.body.published) ? (m.published_at ?? nowIso()) : null
  audit(u, 'shift_month_updated', ['shift_month', 0], before, { ...m })
  return shiftMonthOf(month)
})
on('POST', '/shifts', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const s: Shift = { id: nextId(db, 'shift'), ...shiftFromBody(ctx, null) }
  db.shifts.push(s)
  audit(u, 'shift_created', ['shift', s.id], null, { ...s })
  return created(s)
})
on('PUT', '/shifts/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const s = db.shifts.find((x) => x.id === Number(id)) ?? notFound()
  const before = { ...s }
  Object.assign(s, shiftFromBody(ctx, s))
  audit(u, 'shift_updated', ['shift', s.id], before, { ...s })
  return s
})
on('DELETE', '/shifts/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const s = db.shifts.find((x) => x.id === Number(id)) ?? notFound()
  db.shifts = db.shifts.filter((x) => x !== s)
  audit(u, 'shift_deleted', ['shift', s.id], { ...s }, null)
  return noContent
})
on('GET', '/shift-requests/mine', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const month = monthParam(ctx.params.month)
  return { month: shiftMonthOf(month), requests: db.shift_requests.filter((r) => r.user_id === u.id && r.date.startsWith(month)), patterns: activePatterns() }
})
on('PUT', '/shift-requests/mine', (ctx) => {
  const u = requireUser(ctx, ['owner', 'staff'])
  const month = monthParam(ctx.body.month)
  if (!shiftMonthOf(month).accepting_requests) fail(422, '希望の受付は終わりました', { code: 'SHIFT_REQUEST_CLOSED' })
  const raw = Array.isArray(ctx.body.requests) ? (ctx.body.requests as Json[]) : []
  const list: ShiftRequest[] = raw.map((r, i) => {
    const date = str(r.date)
    if (!date.startsWith(`${month}-`)) invalid(`requests.${i}.date`, 'この月の日付にしてください')
    const kind = r.kind === 'unavailable' ? 'unavailable' : 'available'
    const note = nullableStr(r.note)?.trim() || null
    let p: ShiftPattern | undefined
    if (kind === 'available') {
      if (typeof r.pattern_id === 'number') {
        const prev = db.shift_requests.find((x) => x.user_id === u.id && x.date === date)?.pattern_id
        p = db.shift_patterns.find((x) => x.id === r.pattern_id)
        if (!p || (!p.is_active && prev !== p.id)) invalid(`requests.${i}.pattern_id`, '選んだ区分は使えません。選び直してください')
      } else if (note === null) {
        invalid(`requests.${i}.pattern_id`, '区分を選ぶか、メモを書いてください')
      }
    }
    return {
      user_id: u.id, date, kind, start_time: p?.start_time ?? null, end_time: p?.end_time ?? null, note,
      pattern_id: p?.id ?? null, pattern_name: p?.name ?? null, segments: p ? p.segments.map((x) => ({ ...x })) : null,
    }
  })
  db.shift_requests = [...db.shift_requests.filter((r) => !(r.user_id === u.id && r.date.startsWith(month))), ...list]
  audit(u, 'shift_requests_submitted', ['shift_month', 0], null, { month, count: list.length })
  return { month: shiftMonthOf(month), requests: list, patterns: activePatterns() }
})

// 勤務の区分（13 §5 #91〜#93）
function patternFromBody(ctx: Ctx, ignoreId: number | null): Omit<ShiftPattern, 'id'> {
  const name = str(ctx.body.name).trim()
  if (name === '') invalid('name', '区分の名前を入力してください')
  if (name.length > 20) invalid('name', '区分の名前は 20 文字以内にしてください')
  if (db.shift_patterns.some((p) => p.id !== ignoreId && p.name === name)) invalid('name', 'この区分の名前はすでに使われています')
  const raw = Array.isArray(ctx.body.segments) ? (ctx.body.segments as Json[]) : []
  if (raw.length === 0) invalid('segments', '時間帯を 1 つ以上入れてください')
  if (raw.length > 3) invalid('segments', '時間帯は 3 つまでです')
  const segments: ShiftSegment[] = []
  for (const [i, x] of raw.entries()) {
    if (!timeOk(x.start) || !timeOk(x.end)) invalid('segments', `時間帯 ${i + 1} の時刻を HH:MM で入力してください`)
    const seg = { start: str(x.start).padStart(5, '0'), end: str(x.end).padStart(5, '0') }
    if (hmToMinutes(seg.end) <= hmToMinutes(seg.start)) invalid('segments', `時間帯 ${i + 1} の終了は開始より後にしてください`)
    const prev = segments[segments.length - 1]
    if (prev && hmToMinutes(seg.start) <= hmToMinutes(prev.end)) invalid('segments', `時間帯 ${i + 1} は前の時間帯より後にしてください`)
    segments.push(seg)
  }
  const first = segments[0]?.start ?? '00:00'
  const last = segments[segments.length - 1]?.end ?? '00:00'
  const work = segments.reduce((n, x) => n + hmToMinutes(x.end) - hmToMinutes(x.start), 0)
  return { name, segments, is_active: bool(ctx.body.is_active), start_time: first, end_time: last, break_minutes: hmToMinutes(last) - hmToMinutes(first) - work }
}
const patternAudit = (p: ShiftPattern) => ({ name: p.name, segments: p.segments.map((x) => `${x.start}〜${x.end}`).join(' / '), is_active: p.is_active })
on('GET', '/shift-patterns', (ctx) => {
  requireUser(ctx, ['owner'])
  return { patterns: [...db.shift_patterns].sort((a, b) => Number(b.is_active) - Number(a.is_active) || (a.name < b.name ? -1 : a.name > b.name ? 1 : a.id - b.id)) }
})
on('POST', '/shift-patterns', (ctx) => {
  const u = requireUser(ctx, ['owner'])
  const p: ShiftPattern = { id: nextId(db, 'shift_pattern'), ...patternFromBody(ctx, null) }
  db.shift_patterns.push(p)
  audit(u, 'shift_pattern_created', ['shift_pattern', p.id], null, patternAudit(p))
  return created(p)
})
on('PUT', '/shift-patterns/{id}', (ctx, [id]) => {
  const u = requireUser(ctx, ['owner'])
  const p = db.shift_patterns.find((x) => x.id === Number(id)) ?? notFound()
  const before = patternAudit(p)
  Object.assign(p, patternFromBody(ctx, p.id))
  audit(u, 'shift_pattern_updated', ['shift_pattern', p.id], before, patternAudit(p))
  return p
})

// ─── axios のアダプタ ───
function parseBody(data: unknown): Json {
  if (typeof data === 'string' && data !== '') {
    try { return JSON.parse(data) as Json } catch { return {} }
  }
  return data !== null && typeof data === 'object' && !(data instanceof FormData) ? (data as Json) : {}
}

export const mockAdapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
  await new Promise((r) => setTimeout(r, 90 + Math.random() * 120))
  const method = (config.method ?? 'get').toUpperCase()
  const path = (config.url ?? '').split('?')[0] ?? ''
  const headers: Record<string, string> = {}
  const raw = AxiosHeaders.from(config.headers as AxiosHeaders).toJSON() as Record<string, unknown>
  for (const [k, v] of Object.entries(raw)) if (typeof v === 'string') headers[k.toLowerCase()] = v
  const ctx: Ctx = { method, path, params: (config.params ?? {}) as Json, body: parseBody(config.data), headers, user: demoSessionUser() }

  let status = 200
  let data: unknown = ''
  let resHeaders: Record<string, string> = { 'content-type': 'application/json' }
  const route = routes.find(([m, re]) => m === method && re.test(path))
  try {
    if (!route) notFound()
    const [, re, handler] = route as [string, RegExp, Handler]
    const out = handler(ctx, (path.match(re) ?? []).slice(1))
    if (Array.isArray(out) && typeof out[0] === 'number' && out.length >= 2) {
      status = out[0]
      data = out[1]
      if (out[2]) resHeaders = { ...resHeaders, ...(out[2] as Record<string, string>) }
    } else {
      data = out
    }
    if (method !== 'GET' || path === '/login') changed()
  } catch (e) {
    if (!(e instanceof HttpError)) throw e
    status = e.status
    data = e.body
    if (status === 403) changed()
  }
  const response: AxiosResponse = {
    data: data === '' ? '' : clone(data), status, statusText: String(status), headers: new AxiosHeaders(resHeaders), config, request: {},
  }
  const validate = config.validateStatus ?? ((s: number) => s >= 200 && s < 300)
  if (validate(status)) return response
  throw new AxiosError(`Request failed with status code ${status}`, status >= 500 ? AxiosError.ERR_BAD_RESPONSE : AxiosError.ERR_BAD_REQUEST, config, {}, response)
}
