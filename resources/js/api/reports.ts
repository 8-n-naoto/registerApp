import { http } from '@/api/client'
import type { ByPaymentRow, ByProductRow, ByTaxRow, Closing, SaleSummaryRow, SalesTotals } from '@/types/api'

// 06 §5.1 日次売上・§6 レジ締め

export interface DailyReport {
  date: string
  totals: SalesTotals
  by_tax: ByTaxRow[]
  by_payment: ByPaymentRow[]
  by_product: ByProductRow[]
  sales: SaleSummaryRow[]
  closing: Closing | null
  comparison: null
}

export interface ClosingView {
  business_date: string
  cash_sales: number // 現時点で再計算した現金売上
  closing: Closing | null
}

export interface ClosingInput {
  float_amount: number
  counted_cash: number
  memo: string | null
}

/** admin は閲覧する店舗を storeId で指定する（06 §1.4） */
function storeParams(storeId: number | null): Record<string, number> {
  return storeId === null ? {} : { store_id: storeId }
}

/** #9 date を省略すると現在の営業日 */
export async function fetchDailyReport(date: string | null, storeId: number | null = null): Promise<DailyReport> {
  const params = { ...storeParams(storeId), ...(date === null ? {} : { date }) }
  return (await http.get<DailyReport>('/reports/daily', { params })).data
}

/** #12 */
export async function fetchClosing(date: string, storeId: number | null = null): Promise<ClosingView> {
  return (await http.get<ClosingView>(`/closings/${date}`, { params: storeParams(storeId) })).data
}

/** #13 */
export async function saveClosing(date: string, input: ClosingInput): Promise<Closing> {
  return (await http.put<Closing>(`/closings/${date}`, input)).data
}
