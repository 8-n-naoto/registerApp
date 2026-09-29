import { http } from '@/api/client'
import type { Category, DiscountType, PaymentMethod, Product, Sale, StoreSettings, TaxType } from '@/types/api'

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

/** #7 admin は閲覧する店舗を storeId で指定する（06 §1.4） */
export async function fetchSale(id: number, storeId: number | null = null): Promise<Sale> {
  return (await http.get<Sale>(`/sales/${id}`, { params: storeId === null ? {} : { store_id: storeId } })).data
}

export async function cancelSale(id: number): Promise<Sale> {
  return (await http.post<Sale>(`/sales/${id}/cancel`)).data
}
