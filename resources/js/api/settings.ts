import { http } from '@/api/client'
import type { OrderSettings, PaymentMethod, PriceMode, Rounding, StoreSettings, TaxType } from '@/types/api'

// 06 §8 店舗設定・税区分・支払方法（owner のみ）

export interface StoreSettingsInput {
  name: string
  price_mode: PriceMode
  rounding: Rounding
  day_cutoff_time: string
  stock_enabled: boolean
}

export interface TaxTypeInput {
  name: string
  rate_permille: number
  is_default: boolean
  is_active: boolean
}

export interface PaymentMethodInput {
  name: string
  is_cash: boolean
  is_active: boolean
}

export interface StoreSettingsBundle {
  store: StoreSettings
  tax_types: TaxType[]
  payment_methods: PaymentMethod[]
}

export async function fetchStoreSettings(): Promise<StoreSettingsBundle> {
  return (await http.get<StoreSettingsBundle>('/settings/store')).data
}

export async function updateStoreSettings(input: StoreSettingsInput): Promise<StoreSettings> {
  return (await http.put<StoreSettings>('/settings/store', input)).data
}

export async function createTaxType(input: Omit<TaxTypeInput, 'is_active'>): Promise<TaxType> {
  return (await http.post<TaxType>('/tax-types', input)).data
}

export async function updateTaxType(id: number, input: TaxTypeInput): Promise<TaxType> {
  return (await http.put<TaxType>(`/tax-types/${id}`, input)).data
}

export async function reorderTaxTypes(ids: number[]): Promise<void> {
  await http.put('/tax-types/order', { ids })
}

export async function createPaymentMethod(input: Omit<PaymentMethodInput, 'is_active'>): Promise<PaymentMethod> {
  return (await http.post<PaymentMethod>('/payment-methods', input)).data
}

export async function updatePaymentMethod(id: number, input: PaymentMethodInput): Promise<PaymentMethod> {
  return (await http.put<PaymentMethod>(`/payment-methods/${id}`, input)).data
}

export async function reorderPaymentMethods(ids: number[]): Promise<void> {
  await http.put('/payment-methods/order', { ids })
}

/** 12 §5.13 #64 注文の設定 */
export async function fetchOrderSettings(): Promise<OrderSettings> {
  return (await http.get<OrderSettings>('/settings/orders')).data
}

/** 12 §5.13 #65（polling_mode が schedule でなければ polling_windows は保存されない） */
export async function updateOrderSettings(input: OrderSettings): Promise<OrderSettings> {
  return (await http.put<OrderSettings>('/settings/orders', input)).data
}

/** 15 §4 #105 レシートプリンター（owner のみ）。host を空（null）にすると印刷を使わない */
export interface PrinterSettingsInput {
  host: string | null
  paper_width: 80 | 58
}

export async function updatePrinterSettings(input: PrinterSettingsInput): Promise<StoreSettings> {
  return (await http.put<StoreSettings>('/settings/printer', input)).data
}
