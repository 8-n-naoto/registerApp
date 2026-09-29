import { http } from '@/api/client'
import type { PaymentMethod, PriceMode, Rounding, StoreSettings, TaxType } from '@/types/api'

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
