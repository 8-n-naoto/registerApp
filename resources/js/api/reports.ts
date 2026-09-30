import { apiBaseUrl, http } from '@/api/client'
import type { ByCategoryRow, ByDateRow, ByHourRow, ByPaymentRow, ByProductRow, ByTaxRow, Closing, SaleSummaryRow, SalesTotals } from '@/types/api'

// 06 §5 売上・集計・§6 レジ締め

export interface DailyReport {
  date: string
  totals: SalesTotals
  by_tax: ByTaxRow[]
  by_payment: ByPaymentRow[]
  by_product: ByProductRow[]
  by_category: ByCategoryRow[]
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

export interface SummaryReport {
  from: string
  to: string
  totals: SalesTotals
  by_date: ByDateRow[]
  by_hour: ByHourRow[]
  by_tax: ByTaxRow[]
  by_payment: ByPaymentRow[]
  ranking: ByProductRow[]
  by_category: ByCategoryRow[]
}

export type ExportType = 'daily' | 'sales' | 'items' | 'tax'

/** #10 */
export async function fetchSummary(from: string, to: string, storeId: number | null = null): Promise<SummaryReport> {
  return (await http.get<SummaryReport>('/reports/summary', { params: { ...storeParams(storeId), from, to } })).data
}

/** #11 は CSV のダウンロード。Cookie のセッションで通るため、リンクで開く */
export function exportUrl(type: ExportType, from: string, to: string, storeId: number | null = null): string {
  const query = new URLSearchParams({ type, from, to })
  if (storeId !== null) query.set('store_id', String(storeId))
  return `${apiBaseUrl}/reports/export?${query.toString()}`
}

/** #12 */
export async function fetchClosing(date: string, storeId: number | null = null): Promise<ClosingView> {
  return (await http.get<ClosingView>(`/closings/${date}`, { params: storeParams(storeId) })).data
}

/** #13 */
export async function saveClosing(date: string, input: ClosingInput): Promise<Closing> {
  return (await http.put<Closing>(`/closings/${date}`, input)).data
}
