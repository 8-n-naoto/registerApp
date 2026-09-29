import { http } from '@/api/client'
import type { OrderTable } from '@/types/api'

// 12 §5.11・§5.12 テーブル（管理は owner、利用開始・終了は owner / staff）

export interface OrderTableUpdateInput {
  name: string
  sort_order: number
  is_active: boolean
}

export interface OrderTableQr {
  url: string
  svg: string
}

/** #56 */
export async function fetchOrderTables(): Promise<OrderTable[]> {
  return (await http.get<{ tables: OrderTable[] }>('/order-tables')).data.tables
}

/** #57 */
export async function createOrderTable(name: string): Promise<OrderTable> {
  return (await http.post<OrderTable>('/order-tables', { name })).data
}

/** #58 */
export async function updateOrderTable(id: number, input: OrderTableUpdateInput): Promise<OrderTable> {
  return (await http.put<OrderTable>(`/order-tables/${id}`, input)).data
}

/** #59 */
export async function deleteOrderTable(id: number): Promise<void> {
  await http.delete(`/order-tables/${id}`)
}

/** #60 */
export async function regenerateOrderTableToken(id: number): Promise<OrderTable> {
  return (await http.post<OrderTable>(`/order-tables/${id}/token`)).data
}

/** #61 URL は画面に出さない（svg だけを使う） */
export async function fetchOrderTableQr(id: number): Promise<OrderTableQr> {
  return (await http.get<OrderTableQr>(`/order-tables/${id}/qr`)).data
}

/** #62 */
export async function openOrderTable(id: number): Promise<OrderTable> {
  return (await http.post<OrderTable>(`/order-tables/${id}/open`)).data
}

/** #63 */
export async function closeOrderTable(id: number): Promise<OrderTable> {
  return (await http.post<OrderTable>(`/order-tables/${id}/close`)).data
}
