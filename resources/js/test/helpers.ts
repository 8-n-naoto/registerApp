import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'
import type { Me, Role } from '@/types/api'

/** テスト用：API のエラー応答 */
export function apiError(status: number, data: unknown, headers: Record<string, string> = {}): AxiosError {
  const config = { headers: new AxiosHeaders() }
  const response: AxiosResponse = { status, statusText: '', data, headers, config }
  return new AxiosError('error', 'ERR_BAD_RESPONSE', config, null, response)
}

/** テスト用：GET /me の応答 */
export function makeMe(role: Role): Me {
  return {
    user: { id: 1, login_id: `${role}-a`, name: '山田', role, store_id: role === 'admin' ? null : 1, is_active: true, last_login_at: null },
    store: role === 'admin' ? null : { id: 1, name: 'テスト店 A', price_mode: 'tax_included', rounding: 'floor', day_cutoff_time: '00:00', stock_enabled: true, invoice_number: null, printer: null },
    current_business_date: role === 'admin' ? null : '2026-09-29',
    attendance: role === 'admin' ? null : { id: 1, clock_in_at: '2026-09-29T09:02:00+09:00', on_break: false },
    labor_warnings: [],
  }
}
