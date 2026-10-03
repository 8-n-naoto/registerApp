import { http } from '@/api/client'
import type { Category, DiscountType, PaymentMethod, PriceMode, Product, Rounding, Sale, StoreSettings, TaxType } from '@/types/api'

// 06 §4 レジ（bootstrap・会計の確定・閲覧・取消）

export interface RegisterBootstrap {
  store: StoreSettings
  current_business_date: string
  server_time: string
  tax_types: TaxType[]
  payment_methods: PaymentMethod[]
  categories: Category[]
  products: Product[]
}

export interface SaleInput {
  client_uuid: string
  tax_type_id: number
  payment_method_id: number
  items: { product_id: number; quantity: number; option_ids: number[] }[]
  discount: { type: DiscountType; value: number } | null
  received: number | null
  customer_count: number | null
  memo: string | null
  device_name: string | null
  expected_total: number
  order_ids: number[] // 12 §5.15（注文から会計。無ければ空）
}

/** 409 OUT_OF_STOCK の details.shortages の 1 件 */
export interface StockShortage {
  product_id: number
  product_name: string
  stock_qty: number
  requested: number
}

export async function fetchBootstrap(): Promise<RegisterBootstrap> {
  return (await http.get<RegisterBootstrap>('/register/bootstrap')).data
}

/** #6 新規は 201、同じ client_uuid の再送は 200（どちらも Sale） */
export async function createSale(input: SaleInput): Promise<Sale> {
  return (await http.post<Sale>('/sales', input)).data
}

/**
 * 14 §5.1 オフライン会計の入力。通常の会計の項目に、端末で記録した時刻・担当者と、記録した時点の価格を加える
 */
export interface OfflineSaleInput extends Omit<SaleInput, 'items'> {
  items: { product_id: number; quantity: number; option_ids: number[]; unit_price: number; option_prices: number[] }[]
  sold_at: string // ISO 8601（端末の時刻）
  operator_id: number | null
  tax_rate_permille: number
  price_mode: PriceMode
  rounding: Rounding
}

/** #102 新規は 201、同じ client_uuid の再送（通常の会計で作られていた場合も）は 200 */
export async function createOfflineSale(input: OfflineSaleInput): Promise<Sale> {
  return (await http.post<Sale>('/sales/offline', input)).data
}

/** #103 owner：確認していない問題のあるオフライン会計 */
export async function fetchOfflineIssues(): Promise<Sale[]> {
  return (await http.get<Sale[]>('/sales/offline-issues')).data
}

/** #104 owner：問題を確認済みにする */
export async function reviewOfflineSale(id: number): Promise<Sale> {
  return (await http.post<Sale>(`/sales/${id}/offline-review`)).data
}

/** #7 admin は閲覧する店舗を storeId で指定する（06 §1.4） */
export async function fetchSale(id: number, storeId: number | null = null): Promise<Sale> {
  return (await http.get<Sale>(`/sales/${id}`, { params: storeId === null ? {} : { store_id: storeId } })).data
}

export async function cancelSale(id: number): Promise<Sale> {
  return (await http.post<Sale>(`/sales/${id}/cancel`)).data
}
