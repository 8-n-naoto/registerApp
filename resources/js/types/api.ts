// 06 §2 の共有型（名前・キーを変えない）

export type Role = 'admin' | 'owner' | 'staff'
export type PriceMode = 'tax_included' | 'tax_excluded'
export type Rounding = 'floor' | 'round' | 'ceil'
export type ProductColor = 'gray'|'red'|'orange'|'yellow'|'green'|'teal'|'blue'|'indigo'|'purple'|'pink'

export interface User {
  id: number
  login_id: string
  name: string
  role: Role
  store_id: number | null
  is_active: boolean
  last_login_at: string | null
}

export interface StoreSettings {
  id: number
  name: string
  price_mode: PriceMode
  rounding: Rounding
  day_cutoff_time: string      // 'HH:MM'
  stock_enabled: boolean       // 12 §6.6：false なら会計で在庫を減らさず、売切・残数を出さない
}

export interface Me {
  user: User
  store: StoreSettings | null  // admin は null
  current_business_date: string | null  // owner/staff のみ。07 §3 で計算
  attendance: MeAttendance | null  // 13 §4：勤務中なら出勤の行（勤務中でなければ null）
  labor_warnings: LaborWarning[]  // owner だけ。未入力の労働条件（13 §6.6）
}

export interface TaxType { id: number; name: string; rate_permille: number; sort_order: number; is_default: boolean; is_active: boolean }
export interface PaymentMethod { id: number; name: string; is_cash: boolean; sort_order: number; is_active: boolean }
export interface Category { id: number; name: string; sort_order: number; product_count: number }
export interface ProductOption { id: number; product_id: number; name: string; price: number; sort_order: number; is_active: boolean }
export interface Product {
  id: number
  category_id: number | null
  code: string
  name: string
  memo: string | null
  price: number
  color: ProductColor
  sort_order: number
  is_active: boolean
  track_stock: boolean
  stock_qty: number
  customer_visible: boolean    // 12 §3.7：お客さんのメニュー（C01）に出すか
  is_discount: boolean         // 割引の商品（price は正の数。計算では −price。docs/10）
  options: ProductOption[]
}

export type DiscountType = 'amount' | 'percent'
export type SaleStatus = 'completed' | 'cancelled'

export interface SaleItemOption { product_option_id: number; option_name: string; price: number }
export interface SaleItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  product_memo: string | null
  category_id: number | null     // 会計時点のカテゴリの写し（未分類は null）
  category_name: string | null
  unit_price: number
  options_price: number
  quantity: number
  line_total: number
  options: SaleItemOption[]
}
export interface Sale {
  id: number
  client_uuid: string
  business_date: string
  sold_at: string
  tax_type_name: string
  tax_rate_permille: number
  price_mode: PriceMode
  subtotal: number
  discount_type: DiscountType | null
  discount_value: number
  discount_amount: number
  total: number
  tax_amount: number
  payment_method_name: string
  is_cash: boolean
  received: number
  change_amount: number
  customer_count: number | null
  memo: string | null
  status: SaleStatus
  cancelled_at: string | null
  cancelled_by_name: string | null
  user_name: string
  device_name: string | null
  store_name: string
  items: SaleItem[]
}
export interface SaleSummaryRow {       // 一覧用（明細なし）
  id: number; sold_at: string; total: number; payment_method_name: string
  tax_type_name: string; user_name: string; status: SaleStatus; item_count: number
}

export interface SalesTotals { total: number; count: number; customers: number; average: number; discount_total: number; cancelled_count: number }
export interface ByTaxRow { tax_type_name: string; rate_permille: number; total: number; tax_amount: number; taxable_amount: number }
export interface ByPaymentRow { payment_method_name: string; is_cash: boolean; total: number; count: number }
export interface ByProductRow { product_id: number; product_name: string; product_code: string; product_memo: string | null; quantity: number; amount: number }
export interface ByCategoryRow { category_id: number | null; category_name: string | null; quantity: number; amount: number }
export interface ByHourRow { hour: number; total: number; count: number }   // hour: 0〜23
export interface ByDateRow { date: string; total: number; count: number; customers: number }
export interface Closing {
  business_date: string; float_amount: number; cash_sales: number; expected_cash: number
  counted_cash: number; difference: number; memo: string | null; changed_after_close: boolean
  user_name: string; updated_at: string
}

export interface AuditLogRow {
  id: number; created_at: string; store_name: string | null; user_name: string | null
  action: string; action_label: string; target_type: string | null; target_id: number | null
  before: Record<string, unknown> | null; after: Record<string, unknown> | null; ip: string | null
}

/** 12 §4 注文のテーブル。トークンは含まない（QR は GET /order-tables/{id}/qr だけ） */
export interface OrderTable {
  id: number
  name: string
  sort_order: number
  is_active: boolean
  opened_at: string | null
  session_expires_at: string | null
  unpaid_order_count: number
  unpaid_subtotal: number
  token_rotated_at: string
}

/** 12 §4 注文 */
export type OrderSource = 'customer' | 'staff'
export type OrderStatus = 'pending' | 'active' | 'cancelled'
export type PollingMode = 'always' | 'off' | 'schedule'
export interface PollingWindow { start: string; end: string }   // HH:MM。start > end は日付をまたぐ
export interface OrderSettings {
  customer_order_enabled: boolean
  customer_order_approval: boolean
  customer_session_minutes: number
  polling_mode: PollingMode
  polling_windows: PollingWindow[]
}
export interface PollingState { interval_sec: number; active: boolean; next_change_at: string | null }
export interface OrderItemOption { product_option_id: number; option_name: string; price: number }
export interface OrderItem {
  id: number
  product_id: number
  product_code: string
  product_name: string
  product_memo: string | null
  unit_price: number
  options_price: number
  quantity: number
  line_total: number
  memo: string | null
  served_at: string | null
  options: OrderItemOption[]
}
export interface Order {
  id: number
  client_uuid: string
  business_date: string
  order_no: number
  source: OrderSource
  order_table_id: number | null
  table_name: string | null
  label: string | null
  /** テーブルなしの注文の営業日ごとの連番（テーブルの注文は null） */
  takeout_no: number | null
  status: OrderStatus
  note: string | null
  subtotal: number
  served_at: string | null
  sale_id: number | null
  user_name: string | null
  created_at: string
  items: OrderItem[]
}
/** GET /kitchen/orders（#55） */
export interface KitchenOrders {
  server_time: string
  polling: PollingState
  in_progress: Order[]
  done: Order[]
  pending_count: number
}

/** 12 §4 お客さんの画面（C01）。在庫数・店員・内部 ID を含まない */
export type NotAcceptingReason = 'disabled' | 'table_closed' | 'session_expired'
export interface PublicMenuProduct {
  id: number
  category_id: number | null
  name: string
  memo: string | null
  price: number
  color: ProductColor
  sold_out: boolean
  options: { id: number; name: string; price: number }[]
}
export interface PublicMenu {
  store_name: string
  table_name: string
  price_mode: PriceMode
  accepting: boolean
  not_accepting_reason: NotAcceptingReason | null
  categories: { id: number; name: string }[]
  products: PublicMenuProduct[]
  limits: { max_items: number; max_quantity: number; max_orders_per_session: number }
}
export interface PublicOrderItem {
  product_name: string
  product_memo: string | null
  quantity: number
  line_total: number
  memo: string | null
  served: boolean
  options: string[]
}
export interface PublicOrder {
  order_no: number
  status: OrderStatus
  created_at: string
  subtotal: number
  items: PublicOrderItem[]
}

/** エラー応答の本文（06 §1.3） */
export interface ApiErrorBody {
  message: string
  code?: string
  errors?: Record<string, string[]>
  details?: Record<string, unknown>
}

// 13 §4 勤怠・勤務表

export interface MeAttendance { id: number; clock_in_at: string; on_break: boolean }
export type LaborWarning = 'weekly_hours_limit' | 'week_start_day' | 'legal_holiday_day' | 'minimum_wage' | 'hourly_wage'
export type AttendanceStatus = 'closed' | 'stale' | 'on_break' | 'working'

export interface AttendanceBreak { id: number; started_at: string; ended_at: string | null }

export interface Attendance {
  id: number
  user_id: number
  user_name: string
  business_date: string
  clock_in_at: string
  clock_out_at: string | null
  breaks: AttendanceBreak[]
  break_minutes: number
  work_minutes: number | null  // 勤務中・退勤未打刻は null
  status: AttendanceStatus
  edited: boolean
  hourly_wage?: number | null  // owner にだけ返る
}

export interface AttendanceList { month: string; attendances: Attendance[] }

export type SummaryWarning = 'wage_missing' | 'below_minimum_wage' | 'overtime_45h' | 'break_shortage' | 'open_attendance'

export interface AttendanceSummaryRow {
  user_id: number
  name: string
  role: Role
  is_active: boolean
  hourly_wage: number | null
  overtime_exempt: boolean
  days: number
  work_minutes: number
  overtime_minutes: number
  overtime_over60_minutes: number
  night_minutes: number
  holiday_minutes: number
  scheduled_minutes: number
  open_count: number
  base_pay: number | null
  premium_pay: number | null
  total_pay: number | null
  break_shortage_dates: string[]
  warnings: SummaryWarning[]
}

export interface AttendanceSummaryTotals {
  days: number
  work_minutes: number
  overtime_minutes: number
  night_minutes: number
  holiday_minutes: number
  scheduled_minutes: number
  base_pay: number | null
  premium_pay: number | null
  total_pay: number | null
}

export interface LaborSettingsValues {
  weekly_hours_limit: number | null
  week_start_day: number | null
  legal_holiday_day: number | null
  minimum_wage: number | null
}

export interface AttendanceSummary {
  month: string
  settings: LaborSettingsValues
  warnings: LaborWarning[]
  rows: AttendanceSummaryRow[]
  totals: AttendanceSummaryTotals
}

export interface LaborSettings extends LaborSettingsValues { warnings: LaborWarning[] }

export interface LaborMember { id: number; name: string; role: Role; is_active: boolean; hourly_wage: number | null; overtime_exempt: boolean }

export interface Operator { id: number; name: string; role: Role; on_break: boolean }

export interface ShiftMonth {
  month: string
  request_deadline: string | null
  published_at: string | null
  memo: string | null
  accepting_requests: boolean
}

/** 時刻は 'HH:MM'。日をまたぐ予定は 24 時を超えて書く（例 '26:00'） */
export interface Shift {
  id: number
  user_id: number
  date: string
  start_time: string
  end_time: string
  break_minutes: number
  note: string | null
  planned_minutes: number
}

export type ShiftRequestKind = 'available' | 'unavailable'

export interface ShiftRequest {
  user_id: number
  date: string
  kind: ShiftRequestKind
  start_time: string | null
  end_time: string | null
  note: string | null
}

export interface ShiftMember { id: number; name: string; role: Role; is_active: boolean }

export interface ShiftBoard { month: ShiftMonth; shifts: Shift[]; requests: ShiftRequest[]; members: ShiftMember[] }
