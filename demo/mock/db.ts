// デモ用のメモリ上のデータ（架空の店舗・商品・売上）。localStorage に保存できれば保存する
import { calculateAmounts, settle } from '@/lib/pricing'
import type {
  AttendanceBreak, Category, Closing, LaborSettingsValues, OptionSelection, Order, OrderSettings, OrderTable, PaymentMethod, Product, ProductColor, Sale, SaleItem,
  Shift, ShiftPattern, ShiftRequest, StoreSettings, TaxType, User,
} from '@/types/api'

export interface DbUser extends User { password: string }
export interface DbStore extends StoreSettings { is_active: boolean; order: OrderSettings }
export interface DbTable extends Omit<OrderTable, 'unpaid_order_count' | 'unpaid_subtotal' | 'session_expires_at'> { token: string }
export interface DbLog {
  id: number; created_at: string; store_id: number | null; user_id: number | null
  action: string; target_type: string | null; target_id: number | null
  before: Record<string, unknown> | null; after: Record<string, unknown> | null
}
export interface DbSale extends Sale { store_id: number; user_id: number }
export interface DbOrder extends Order { store_id: number }
export interface DbAttendance {
  id: number; store_id: number; user_id: number; business_date: string
  clock_in_at: string; clock_out_at: string | null; breaks: AttendanceBreak[]; edited: boolean
}
export interface DbShiftMonth { month: string; request_deadline: string | null; published_at: string | null; memo: string | null }
export interface DbWage { hourly_wage: number | null; overtime_exempt: boolean }

export interface Db {
  version: number
  session_user_id: number | null
  seq: Record<string, number>
  order_rev: number
  stores: DbStore[]
  users: DbUser[]
  tax_types: TaxType[]
  payment_methods: PaymentMethod[]
  categories: Omit<Category, 'product_count'>[]
  products: Product[]
  sales: DbSale[]
  closings: Closing[]
  tables: DbTable[]
  orders: DbOrder[]
  logs: DbLog[]
  // 13 勤怠・勤務表
  attendances: DbAttendance[]
  labor: LaborSettingsValues
  wages: Record<string, DbWage>
  shift_months: DbShiftMonth[]
  shifts: Shift[]
  shift_requests: ShiftRequest[]
  shift_patterns: ShiftPattern[]
}

export const DB_VERSION = 9
const STORAGE_KEY = 'regi-demo:db'
export const STORE_ID = 1

// ─── 時刻（Asia/Tokyo） ───
const partsFmt = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
})
export function tokyoParts(ms: number): { date: string; hour: number; minute: number } {
  const p: Record<string, string> = {}
  for (const x of partsFmt.formatToParts(new Date(ms))) p[x.type] = x.value
  return { date: `${p.year}-${p.month}-${p.day}`, hour: Number(p.hour), minute: Number(p.minute) }
}
export function shiftYmd(ymd: string, days: number): string {
  const [y, m, d] = ymd.split('-').map(Number)
  const t = new Date(Date.UTC(y ?? 2000, (m ?? 1) - 1, (d ?? 1) + days))
  return t.toISOString().slice(0, 10)
}
/** 東京の日付・時刻から ISO（+09:00） */
export function tokyoIso(ymd: string, hour: number, minute: number): string {
  const [y, m, d] = ymd.split('-').map(Number)
  return isoAt(Date.UTC(y ?? 2000, (m ?? 1) - 1, d ?? 1, hour - 9, minute))
}
/** サーバーと同じ東京時刻の ISO 8601（'2026-09-29T13:05:12+09:00'）。画面はこの表記をそのまま使う */
export function isoAt(ms: number): string {
  return `${new Date(ms + 9 * 3600_000).toISOString().slice(0, 19)}+09:00`
}
export function businessDate(ms: number, cutoff: string): string {
  const { date, hour, minute } = tokyoParts(ms)
  const [ch, cm] = cutoff.split(':').map(Number)
  return hour * 60 + minute < (ch ?? 0) * 60 + (cm ?? 0) ? shiftYmd(date, -1) : date
}

// ─── 乱数（毎回同じ売上履歴になるよう固定の種） ───
function mulberry32(seed: number): () => number {
  let a = seed
  return () => {
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

export function nextId(db: Db, key: string): number {
  db.seq[key] = (db.seq[key] ?? 0) + 1
  return db.seq[key]
}

/** 会計の明細・金額を作る（POST /sales と履歴の生成で共用） */
/** 割引の商品は価格を正の数で持ち、会計では −価格 の明細にする（app/Models/Product::signedPrice） */
export const signedPrice = (p: Product): number => (p.is_discount ? -p.price : p.price)

export function buildSaleAmounts(
  store: StoreSettings, tax: TaxType, lines: { product: Product; quantity: number; optionIds: number[] }[],
  discount: { type: 'amount' | 'percent'; value: number } | null,
) {
  const amounts = calculateAmounts({
    price_mode: store.price_mode,
    rounding: store.rounding,
    tax_rate_permille: tax.rate_permille,
    items: lines.map((l) => ({
      unit_price: signedPrice(l.product),
      option_prices: l.product.options.filter((o) => l.optionIds.includes(o.id)).map((o) => o.price),
      quantity: l.quantity,
    })),
    discount,
  })
  return amounts
}

const DRINK = 1
const FOOD = 2
const SWEET = 3

function seedProducts(): Product[] {
  const rows: [number | null, string, number, ProductColor, number, boolean, string | null][] = [
    // category, name, price, color, stock, track_stock, memo
    [DRINK, 'ブレンドコーヒー', 450, 'orange', 0, false, null],
    [DRINK, 'カフェラテ', 520, 'yellow', 0, false, null],
    [DRINK, 'アイスティー', 480, 'teal', 0, false, null],
    [DRINK, 'オレンジジュース', 500, 'orange', 0, false, '果汁 100%'],
    [DRINK, 'クラフトビール', 750, 'yellow', 24, true, '瓶'],
    [FOOD, 'ナポリタン', 950, 'red', 20, true, null],
    [FOOD, 'ハヤシライス', 1050, 'red', 15, true, null],
    [FOOD, 'ミックスサンド', 780, 'green', 12, true, null],
    [FOOD, 'ポテトフライ', 450, 'yellow', 0, false, null],
    [SWEET, 'チーズケーキ', 520, 'pink', 8, true, null],
    [SWEET, 'プリン', 480, 'purple', 3, true, '固め'],
    [SWEET, 'ホットケーキ', 680, 'orange', 0, false, null],
    [null, '持ち帰り袋', 10, 'gray', 0, false, null],
    [null, 'クーポン', 100, 'gray', 0, false, '100 円引き'],
  ]
  let optionId = 0
  let groupId = 0
  return rows.map(([category_id, name, price, color, stock, track, memo], i) => {
    const id = i + 1
    const options: Product['options'] = []
    const option_groups: Product['option_groups'] = []
    const group = (n: string, selection: OptionSelection): number => {
      option_groups.push({ id: ++groupId, product_id: id, name: n, selection, sort_order: option_groups.length })
      return groupId
    }
    const add = (n: string, p: number, group_id: number | null = null, is_default = false) => options.push({ id: ++optionId, product_id: id, name: n, price: p, sort_order: options.length + 1, is_active: true, group_id, is_default })
    // docs/10「オプションのグループ」：1つ選ぶ（最初に選ぶ付き）・いくつでも・グループなし（ハヤシライス・甘味）を見せる
    if (category_id === DRINK && id <= 3) {
      const size = group('サイズ', 'single')
      add('レギュラー', 0, size, true); add('ラージ', 100, size)
      add('オーツミルクに変更', 50)
    }
    if (name === 'ナポリタン') {
      const hardness = group('麺の固さ', 'single')
      add('固め', 0, hardness); add('普通', 0, hardness, true); add('柔らかめ', 0, hardness)
      const extra = group('追加', 'multi')
      add('大盛り', 150, extra); add('サラダセット', 250, extra); add('粉チーズ多め', 0, extra)
    }
    if (name === 'ハヤシライス') { add('大盛り', 150); add('サラダセット', 250) }
    if (category_id === SWEET) add('ホイップ追加', 80)
    return {
      id, category_id, code: String(1001 + i), name, memo, price, color, sort_order: id, is_active: true,
      track_stock: track, stock_qty: stock, customer_visible: name !== 'クラフトビール' && name !== 'クーポン', is_discount: name === 'クーポン', options, option_groups,
    }
  })
}

export function createSeed(now: number): Db {
  const store: DbStore = {
    id: STORE_ID, name: '喫茶こもれび（デモ）', price_mode: 'tax_included', rounding: 'floor', day_cutoff_time: '05:00',
    stock_enabled: true, is_active: true,
    order: { customer_order_enabled: true, customer_order_approval: false, customer_session_minutes: 180, polling_mode: 'always', polling_windows: [] },
  }
  const store2: DbStore = {
    id: 2, name: '駅前スタンド（デモ）', price_mode: 'tax_excluded', rounding: 'round', day_cutoff_time: '04:00',
    stock_enabled: false, is_active: true,
    order: { customer_order_enabled: false, customer_order_approval: false, customer_session_minutes: 120, polling_mode: 'off', polling_windows: [] },
  }
  const db: Db = {
    version: DB_VERSION,
    session_user_id: 2,
    seq: {},
    order_rev: 1,
    stores: [store, store2],
    users: [
      { id: 1, login_id: 'admin', name: '運営管理者', role: 'admin', store_id: null, is_active: true, last_login_at: null, password: 'demo' },
      { id: 2, login_id: 'owner', name: '店長 山田', role: 'owner', store_id: STORE_ID, is_active: true, last_login_at: null, password: 'demo' },
      { id: 3, login_id: 'staff1', name: 'スタッフ 佐藤', role: 'staff', store_id: STORE_ID, is_active: true, last_login_at: null, password: 'demo' },
      { id: 4, login_id: 'staff2', name: 'スタッフ 鈴木', role: 'staff', store_id: STORE_ID, is_active: true, last_login_at: null, password: 'demo' },
      { id: 5, login_id: 'staff3', name: 'スタッフ 高橋', role: 'staff', store_id: STORE_ID, is_active: false, last_login_at: null, password: 'demo' },
      { id: 6, login_id: 'ekimae', name: '駅前 店長', role: 'owner', store_id: 2, is_active: true, last_login_at: null, password: 'demo' },
    ],
    tax_types: [
      { id: 1, name: '店内 10%', rate_permille: 100, sort_order: 1, is_default: true, is_active: true },
      { id: 2, name: '持ち帰り 8%', rate_permille: 80, sort_order: 2, is_default: false, is_active: true },
    ],
    payment_methods: [
      { id: 1, name: '現金', is_cash: true, sort_order: 1, is_active: true },
      { id: 2, name: 'クレジットカード', is_cash: false, sort_order: 2, is_active: true },
      { id: 3, name: 'QR 決済', is_cash: false, sort_order: 3, is_active: true },
      { id: 4, name: '交通系 IC', is_cash: false, sort_order: 4, is_active: true },
    ],
    categories: [
      { id: DRINK, name: 'ドリンク', sort_order: 1 },
      { id: FOOD, name: 'フード', sort_order: 2 },
      { id: SWEET, name: 'デザート', sort_order: 3 },
    ],
    products: seedProducts(),
    sales: [],
    closings: [],
    tables: [],
    orders: [],
    logs: [],
    attendances: [],
    // 法定休日は未入力のまま（owner に警告が出る見本）
    labor: { weekly_hours_limit: 40, week_start_day: 0, legal_holiday_day: null, minimum_wage: 1163 },
    wages: { 2: { hourly_wage: 1600, overtime_exempt: true }, 3: { hourly_wage: 1250, overtime_exempt: false }, 4: { hourly_wage: null, overtime_exempt: false }, 5: { hourly_wage: 1200, overtime_exempt: false } },
    shift_months: [],
    shifts: [],
    shift_requests: [],
    shift_patterns: [],
  }
  // 採番はシードの最大 ID から（件数を直書きすると新しい商品が既存の ID と重なる）
  const maxId = (ids: number[]): number => ids.reduce((m, x) => Math.max(m, x), 0)
  db.seq = {
    product: maxId(db.products.map((p) => p.id)),
    option: maxId(db.products.flatMap((p) => p.options.map((o) => o.id))),
    option_group: maxId(db.products.flatMap((p) => p.option_groups.map((g) => g.id))),
    category: 3, tax_type: 2, payment_method: 4, user: 6, table: 0, order: 0, order_item: 0, sale: 0, sale_item: 0, log: 0, attendance: 0, attendance_break: 0, shift: 0, shift_pattern: 0,
  }

  const rand = mulberry32(20260930)
  const pick = <T>(xs: T[]): T => xs[Math.floor(rand() * xs.length)] as T
  const today = businessDate(now, store.day_cutoff_time)
  const users = db.users.filter((u) => u.store_id === STORE_ID && u.is_active)

  // ─── 過去 45 日＋今日の売上 ───
  for (let back = 45; back >= 0; back--) {
    const date = shiftYmd(today, -back)
    const weekday = new Date(`${date}T00:00:00Z`).getUTCDay()
    const base = weekday === 0 || weekday === 6 ? 30 : 18
    // 10:00〜20:00。今日（暦日も同じ）の分は、今の時刻より前に収まるよう時間帯をずらす
    let from = 10 * 60
    let span = 10 * 60
    const nowParts = tokyoParts(now)
    const nowMin = nowParts.hour * 60 + nowParts.minute
    if (back === 0 && nowParts.date === date && nowMin < 20 * 60 + 5) {
      from = Math.max(5 * 60 + 5, nowMin - 10 * 60)
      span = Math.max(0, nowMin - 5 - from)
    }
    const count = Math.round((base + Math.floor(rand() * 10)) * (span / 600))
    const times: number[] = []
    for (let i = 0; i < count; i++) times.push(Math.floor(from + rand() * span))
    times.sort((a, b) => a - b)
    for (const minuteOfDay of times) {
      const soldAt = tokyoIso(date, Math.floor(minuteOfDay / 60), minuteOfDay % 60)
      if (Date.parse(soldAt) > now - 5 * 60_000) continue
      const lineCount = 1 + Math.floor(rand() * 3)
      const lines: { product: Product; quantity: number; optionIds: number[] }[] = []
      const sellable = db.products.filter((p) => !p.is_discount)
      for (let l = 0; l < lineCount; l++) {
        const product = pick(sellable)
        if (lines.some((x) => x.product.id === product.id)) continue
        const optionIds = seedOptionIds(product, rand, pick)
        lines.push({ product, quantity: 1 + (rand() < 0.25 ? 1 : 0), optionIds })
      }
      const coupon = db.products.find((p) => p.is_discount)
      // 割引が商品の合計を超える会計は作れない（小計が負になる）ので、合計 500 円以上の会計にだけ付ける
      const linesTotal = lines.reduce((n, l) => n + l.product.price * l.quantity, 0)
      if (coupon !== undefined && linesTotal >= 500 && rand() < 0.06) lines.push({ product: coupon, quantity: 1, optionIds: [] })
      const tax = rand() < 0.8 ? db.tax_types[0] as TaxType : db.tax_types[1] as TaxType
      const pay = rand() < 0.5 ? db.payment_methods[0] as PaymentMethod : pick(db.payment_methods.slice(1))
      const discount = rand() < 0.05 ? { type: 'amount' as const, value: 100 } : null
      const user = pick(users)
      const sale = makeSale(db, store, {
        tax, pay, lines, discount, soldAt, businessDate: date, user,
        received: null, customerCount: 1 + Math.floor(rand() * 3), memo: null, deviceName: rand() < 0.7 ? 'レジ 1' : 'タブレット 2',
      })
      if (rand() < 0.015) {
        sale.status = 'cancelled'
        sale.cancelled_at = isoAt(Date.parse(soldAt) + 3 * 60_000)
        sale.cancelled_by_name = '店長 山田'
      }
      db.sales.push(sale)
    }
    // 前日までのレジ締め
    if (back > 0 && back <= 30) {
      const cash = db.sales.filter((s) => s.business_date === date && s.status === 'completed' && s.is_cash).reduce((n, s) => n + s.total, 0)
      const diff = rand() < 0.8 ? 0 : pick([-100, 50, -10])
      db.closings.push({
        business_date: date, float_amount: 30000, cash_sales: cash, expected_cash: 30000 + cash, counted_cash: 30000 + cash + diff,
        difference: diff, memo: diff === 0 ? null : '原因不明', changed_after_close: false, user_name: '店長 山田', updated_at: tokyoIso(date, 21, 10),
      })
    }
  }

  // ─── テーブルと今日の注文 ───
  for (let i = 1; i <= 6; i++) {
    db.tables.push({
      id: nextId(db, 'table'), name: `T${i}`, sort_order: i, is_active: i <= 5, opened_at: null,
      token: `demo-t${i}`, token_rotated_at: tokyoIso(shiftYmd(today, -40), 9, 0),
    })
  }
  const minutesAgo = (m: number) => isoAt(now - m * 60_000)
  const t1 = db.tables[0] as DbTable
  const t2 = db.tables[1] as DbTable
  const t4 = db.tables[3] as DbTable
  t1.opened_at = minutesAgo(35)
  t2.opened_at = minutesAgo(50)
  t4.opened_at = minutesAgo(8)
  const p = (id: number) => db.products.find((x) => x.id === id) as Product
  addOrder(db, store, { table: t2, source: 'customer', createdAt: minutesAgo(48), items: [{ product: p(2), quantity: 2, optionIds: [], memo: null }, { product: p(8), quantity: 1, optionIds: [], memo: null }], served: 'all', user: null, label: null, note: null })
  addOrder(db, store, { table: t1, source: 'customer', createdAt: minutesAgo(30), items: [{ product: p(6), quantity: 1, optionIds: [p(6).options[0]?.id ?? 0], memo: null }, { product: p(1), quantity: 2, optionIds: [], memo: '食後に' }], served: 'part', user: null, label: null, note: null })
  addOrder(db, store, { table: t1, source: 'staff', createdAt: minutesAgo(12), items: [{ product: p(10), quantity: 2, optionIds: [], memo: null }], served: 'none', user: users[1] ?? null, label: null, note: null })
  addOrder(db, store, { table: null, source: 'staff', createdAt: minutesAgo(6), items: [{ product: p(7), quantity: 1, optionIds: [], memo: null }, { product: p(9), quantity: 1, optionIds: [], memo: null }], served: 'none', user: users[0] ?? null, label: null, note: null })
  addOrder(db, store, { table: null, source: 'staff', createdAt: minutesAgo(4), items: [{ product: p(11), quantity: 2, optionIds: [], memo: null }], served: 'none', user: users[0] ?? null, label: '田中様', note: null })
  addOrder(db, store, { table: t4, source: 'customer', createdAt: minutesAgo(3), items: [{ product: p(3), quantity: 1, optionIds: [], memo: '氷少なめ' }, { product: p(12), quantity: 1, optionIds: [], memo: null }], served: 'none', user: null, label: null, note: 'アレルギー：卵' })

  // ─── 操作ログ ───
  const log = (minAgo: number, userId: number | null, action: string, target: [string, number] | null, before: Record<string, unknown> | null, after: Record<string, unknown> | null) =>
    db.logs.push({ id: nextId(db, 'log'), created_at: minutesAgo(minAgo), store_id: STORE_ID, user_id: userId, action, target_type: target?.[0] ?? null, target_id: target?.[1] ?? null, before, after })
  log(60 * 24 * 3, 2, 'product_updated', ['product', 2], { price: 500 }, { price: 520 })
  log(60 * 24 * 2, 2, 'staff_updated', ['user', 5], { is_active: true }, { is_active: false })
  log(60 * 24, 2, 'closing_saved', ['closing', 1], null, { business_date: shiftYmd(today, -1), counted_cash: 30000 })
  log(60 * 5, 3, 'login_succeeded', ['user', 3], null, null)
  log(60 * 2, 2, 'product_stock_changed', ['product', 11], { stock_qty: 10 }, { stock_qty: 3 })
  log(50, 3, 'order_table_opened', ['order_table', t2.id], { opened_at: null }, { name: 'T2', opened_at: t2.opened_at })
  log(48, null, 'order_created', ['order', 1], null, { order_no: 1, table_name: 'T2', item_count: 3, subtotal: 1820 })
  log(30, null, 'order_created', ['order', 2], null, { order_no: 2, table_name: 'T1', item_count: 3, subtotal: 2000 })
  log(3, null, 'order_created', ['order', 5], null, { order_no: 5, table_name: 'T4', item_count: 2, subtotal: 1160 })
  seedLabor(db, now, today, rand)
  return db
}

/** 'YYYY-MM' の日付の一覧 */
export function daysOfMonth(month: string): string[] {
  const [y, m] = month.split('-').map(Number)
  const last = new Date(Date.UTC(y ?? 2000, m ?? 1, 0)).getUTCDate()
  return Array.from({ length: last }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`)
}
export function addMonths(month: string, n: number): string {
  const [y, m] = month.split('-').map(Number)
  const d = new Date(Date.UTC(y ?? 2000, (m ?? 1) - 1 + n, 1))
  return d.toISOString().slice(0, 7)
}
/** 'HH:MM'（24 時を超えてよい）→ 分 */
export const hmToMinutes = (hm: string): number => {
  const [h, m] = hm.split(':').map(Number)
  return (h ?? 0) * 60 + (m ?? 0)
}

/** 13 勤怠の見本：この 3 週間の打刻、今月の勤務表（公開済み）、来月の希望（受付中） */
function seedLabor(db: Db, now: number, today: string, rand: () => number): void {
  const patterns: [number, number, number, number][] = [[10, 0, 15, 30], [11, 0, 17, 0], [17, 0, 22, 30], [9, 30, 18, 30]]
  const staffIds = [2, 3, 4]
  for (let back = 21; back >= 1; back--) {
    const date = shiftYmd(today, -back)
    for (const uid of staffIds) {
      if (rand() < (uid === 2 ? 0.25 : 0.45)) continue
      const [sh, sm, eh, em] = uid === 2 ? [9, 0, 18, 0] : patterns[Math.floor(rand() * patterns.length)] as [number, number, number, number]
      const inAt = Date.parse(tokyoIso(date, sh, sm)) + Math.floor(rand() * 6) * 60_000
      const outAt = Date.parse(tokyoIso(date, eh, em)) + Math.floor(rand() * 8) * 60_000
      const work = (outAt - inAt) / 60_000
      const breaks: AttendanceBreak[] = []
      if (work > 360) {
        const bStart = inAt + Math.floor(work / 2) * 60_000
        breaks.push({ id: nextId(db, 'attendance_break'), started_at: isoAt(bStart), ended_at: isoAt(bStart + (work > 480 ? 60 : 45) * 60_000) })
      }
      db.attendances.push({ id: nextId(db, 'attendance'), store_id: STORE_ID, user_id: uid, business_date: date, clock_in_at: isoAt(inAt), clock_out_at: isoAt(outAt), breaks, edited: false })
    }
  }
  // 今いる人：店長（デモの最初のログイン）と佐藤さん
  const minutesAgo = (m: number) => isoAt(now - m * 60_000)
  db.attendances.push({ id: nextId(db, 'attendance'), store_id: STORE_ID, user_id: 2, business_date: today, clock_in_at: minutesAgo(150), clock_out_at: null, breaks: [], edited: false })
  db.attendances.push({ id: nextId(db, 'attendance'), store_id: STORE_ID, user_id: 3, business_date: today, clock_in_at: minutesAgo(95), clock_out_at: null, breaks: [], edited: false })

  const month = today.slice(0, 7)
  const next = addMonths(month, 1)
  db.shift_months.push({ month, request_deadline: null, published_at: isoAt(now - 20 * 86_400_000), memo: '土日は 2 人体制です' })
  db.shift_months.push({ month: next, request_deadline: `${month}-25` < today ? shiftYmd(today, 3) : `${month}-25`, published_at: null, memo: null })
  const slots: [number, string, string, number][] = [[3, '10:00', '15:30', 30], [4, '17:00', '22:30', 30], [4, '11:00', '17:00', 45], [2, '09:00', '18:00', 60]]
  for (const [mi, m] of [month, next].entries()) {
    for (const date of daysOfMonth(m)) {
      const weekday = new Date(`${date}T00:00:00Z`).getUTCDay()
      for (const [uid, start, end, brk] of slots) {
        if (mi === 1 && uid !== 2) continue
        if (uid === 2 && (weekday === 3)) continue
        if (uid !== 2 && rand() < (weekday === 0 || weekday === 6 ? 0.2 : 0.55)) continue
        db.shifts.push({ id: nextId(db, 'shift'), user_id: uid, date, start_time: start, end_time: end, break_minutes: brk, note: null, planned_minutes: hmToMinutes(end) - hmToMinutes(start) - brk, pattern_id: null, pattern_name: null, segments: null })
      }
    }
  }
  // 13 §3.6 区分（時間帯の間は休憩）
  const patternSeed: [string, [string, string][], boolean][] = [
    ['A', [['09:00', '12:00'], ['13:00', '15:00']], true],
    ['B', [['09:00', '12:00']], true],
    ['夜', [['17:00', '22:00']], true],
    ['旧ランチ', [['11:00', '14:00']], false],
  ]
  for (const [name, segs, active] of patternSeed) {
    const segments = segs.map(([start, end]) => ({ start, end }))
    const first = segments[0]?.start ?? '00:00'
    const last = segments[segments.length - 1]?.end ?? '00:00'
    const work = segments.reduce((n, x) => n + hmToMinutes(x.end) - hmToMinutes(x.start), 0)
    const span = hmToMinutes(last) - hmToMinutes(first)
    db.shift_patterns.push({ id: nextId(db, 'shift_pattern'), name, segments, is_active: active, start_time: first, end_time: last, break_minutes: span - work })
  }
  const night = db.shift_patterns[2]
  for (const date of daysOfMonth(next).slice(0, 12)) {
    if (rand() < 0.4) continue
    const ok = rand() < 0.75
    db.shift_requests.push(ok && night
      ? { user_id: 4, date, kind: 'available', start_time: night.start_time, end_time: night.end_time, note: null, pattern_id: night.id, pattern_name: night.name, segments: night.segments }
      : { user_id: 4, date, kind: 'unavailable', start_time: null, end_time: null, note: '授業', pattern_id: null, pattern_name: null, segments: null })
  }
}

/** 見本の会計・注文の選択（docs/10「オプションのグループ」）：1つ選ぶのグループは 1 つ、ほかは 3 割で 1 つ */
export function seedOptionIds(product: Product, rand: () => number, pick: <T>(xs: T[]) => T): number[] {
  const singles = new Set(product.option_groups.filter((g) => g.selection === 'single').map((g) => g.id))
  const ids: number[] = []
  for (const gid of singles) {
    const opts = product.options.filter((o) => o.group_id === gid)
    const chosen = rand() < 0.7 ? opts.find((o) => o.is_default) : pick(opts)
    if (chosen) ids.push(chosen.id)
  }
  const others = product.options.filter((o) => o.group_id === null || !singles.has(o.group_id))
  if (others.length > 0 && rand() < 0.3) ids.push(pick(others).id)
  return ids
}

/** 注文の明細のオプションの写し（app/Services/OrderService：is_default・is_choice） */
export function orderOptionSnapshot(product: Product, optionIds: number[]): Order['items'][number]['options'] {
  const singles = new Set(product.option_groups.filter((g) => g.selection === 'single').map((g) => g.id))
  return product.options.filter((o) => optionIds.includes(o.id)).map((o) => {
    const choice = o.group_id !== null && singles.has(o.group_id)
    return { product_option_id: o.id, option_name: o.name, price: o.price, is_default: choice && o.is_default, is_choice: choice }
  })
}

export function makeSale(db: Db, store: StoreSettings, a: {
  tax: TaxType; pay: PaymentMethod; lines: { product: Product; quantity: number; optionIds: number[] }[]
  discount: { type: 'amount' | 'percent'; value: number } | null; soldAt: string; businessDate: string; user: User
  received: number | null; customerCount: number | null; memo: string | null; deviceName: string | null
}, clientUuid?: string): DbSale {
  const amounts = buildSaleAmounts(store, a.tax, a.lines, a.discount)
  const s = settle(amounts.total, a.pay.is_cash, a.pay.is_cash ? (a.received ?? amounts.total) : null)
  const id = nextId(db, 'sale')
  const items: SaleItem[] = a.lines.map((l, i) => {
    const opts = l.product.options.filter((o) => l.optionIds.includes(o.id))
    const category = db.categories.find((c) => c.id === l.product.category_id)
    return {
      id: nextId(db, 'sale_item'), product_id: l.product.id, product_name: l.product.name, product_code: l.product.code, product_memo: l.product.memo,
      category_id: category?.id ?? null, category_name: category?.name ?? null,
      unit_price: signedPrice(l.product), options_price: opts.reduce((n, o) => n + o.price, 0), quantity: l.quantity, line_total: amounts.line_totals[i] ?? 0,
      options: opts.map((o) => ({ product_option_id: o.id, option_name: o.name, price: o.price })),
    }
  })
  return {
    id, store_id: STORE_ID, user_id: a.user.id, client_uuid: clientUuid ?? `seed-${id}`, business_date: a.businessDate, sold_at: a.soldAt,
    tax_type_name: a.tax.name, tax_rate_permille: a.tax.rate_permille, price_mode: store.price_mode,
    subtotal: amounts.subtotal, discount_type: a.discount?.type ?? null, discount_value: a.discount?.value ?? 0, discount_amount: amounts.discount_amount,
    total: amounts.total, tax_amount: amounts.tax_amount, payment_method_name: a.pay.name, is_cash: a.pay.is_cash,
    received: s.received, change_amount: s.change_amount, customer_count: a.customerCount, memo: a.memo, status: 'completed',
    cancelled_at: null, cancelled_by_name: null, user_name: a.user.name, device_name: a.deviceName, store_name: store.name, items,
  }
}

export function addOrder(db: Db, store: StoreSettings, a: {
  table: DbTable | null; source: 'customer' | 'staff'; createdAt: string
  items: { product: Product; quantity: number; optionIds: number[]; memo: string | null }[]
  served: 'all' | 'part' | 'none'; user: User | null; label: string | null; note: string | null
  status?: 'pending' | 'active'; clientUuid?: string
}): DbOrder {
  const bd = businessDate(Date.parse(a.createdAt), store.day_cutoff_time)
  const orderNo = db.orders.filter((o) => o.business_date === bd).reduce((n, o) => Math.max(n, o.order_no), 0) + 1
  // テーブルなしの注文の営業日ごとの連番（app/Models/Order::takeoutNo）
  const takeoutNo = a.table === null ? db.orders.filter((o) => o.business_date === bd && o.order_table_id === null).length + 1 : null
  const id = nextId(db, 'order')
  const servedAt = a.served === 'none' ? null : isoAt(Date.parse(a.createdAt) + 8 * 60_000)
  const items = a.items.map((l, i) => {
    const opts = l.product.options.filter((o) => l.optionIds.includes(o.id))
    const optionsPrice = opts.reduce((n, o) => n + o.price, 0)
    return {
      id: nextId(db, 'order_item'), product_id: l.product.id, product_code: l.product.code, product_name: l.product.name, product_memo: l.product.memo,
      unit_price: l.product.price, options_price: optionsPrice, quantity: l.quantity, line_total: (l.product.price + optionsPrice) * l.quantity,
      memo: l.memo, served_at: a.served === 'all' || (a.served === 'part' && i === 0) ? servedAt : null,
      options: orderOptionSnapshot(l.product, opts.map((o) => o.id)),
    }
  })
  const order: DbOrder = {
    id, store_id: STORE_ID, client_uuid: a.clientUuid ?? `seed-order-${id}`, business_date: bd, order_no: orderNo, source: a.source,
    order_table_id: a.table?.id ?? null, table_name: a.table?.name ?? null, label: a.label, takeout_no: takeoutNo, status: a.status ?? 'active', note: a.note,
    subtotal: items.reduce((n, x) => n + x.line_total, 0), served_at: a.served === 'all' ? servedAt : null, sale_id: null,
    user_name: a.user?.name ?? null, created_at: a.createdAt, items,
  }
  db.orders.push(order)
  db.order_rev++
  return order
}

export function loadDb(): Db | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw === null) return null
    const parsed = JSON.parse(raw) as Db
    return parsed.version === DB_VERSION ? parsed : null
  } catch {
    return null
  }
}

export function saveDb(db: Db): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(db))
  } catch {
    // 保存できなければメモリだけで動く
  }
}

export function clearSaved(): void {
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i)
      if (key !== null && (key.startsWith('regi-demo:') || key.startsWith('regi:'))) localStorage.removeItem(key)
    }
  } catch {
    // 何もしない
  }
}
