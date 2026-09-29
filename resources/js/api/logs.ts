import { http } from '@/api/client'
import type { AuditLogRow } from '@/types/api'

// 06 §10 操作ログ（ページング §1.7）

export interface AuditLogPage {
  data: AuditLogRow[]
  meta: { current_page: number; last_page: number; total: number }
}

/** #42 admin は storeId を省略すると全店舗 */
export async function fetchLogs(page: number, action: string | null, storeId: number | null = null): Promise<AuditLogPage> {
  const params: Record<string, string | number> = { page }
  if (action !== null) params.action = action
  if (storeId !== null) params.store_id = storeId
  return (await http.get<AuditLogPage>('/logs', { params })).data
}
