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

/** エラー応答の本文（06 §1.3） */
export interface ApiErrorBody {
  message: string
  code?: string
  errors?: Record<string, string[]>
  details?: Record<string, unknown>
}
