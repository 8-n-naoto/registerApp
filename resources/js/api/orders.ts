import { http } from '@/api/client'
import type { Order } from '@/types/api'

// 12 §5.4〜§5.9 注文（owner / staff）

export type OrderView = 'unpaid' | 'pending' | 'today'

export interface StaffOrderInput {
  client_uuid: string
  order_table_id: number | null
  label: string | null
  device_name: string | null
  items: { product_id: number; quantity: number; option_ids: number[]; memo: string | null }[]
  note: string | null
  expected_subtotal: number
}

/** #49 並びは受付の古い順 */
export async function fetchOrders(view: OrderView, tableId: number | null = null): Promise<Order[]> {
  const params = tableId === null ? { view } : { view, table_id: tableId }
  return (await http.get<{ orders: Order[] }>('/orders', { params })).data.orders
}

/** #50 新規は 201、同じ client_uuid の再送は 200（どちらも Order） */
export async function createOrder(input: StaffOrderInput): Promise<Order> {
  return (await http.post<Order>('/orders', input)).data
}

/** #51 */
export async function acceptOrder(id: number): Promise<Order> {
  return (await http.post<Order>(`/orders/${id}/accept`)).data
}

/** #52 */
export async function cancelOrder(id: number): Promise<Order> {
  return (await http.post<Order>(`/orders/${id}/cancel`)).data
}

/** #53 */
export async function serveAllOrder(id: number): Promise<Order> {
  return (await http.post<Order>(`/orders/${id}/serve-all`)).data
}

/** #54 注文全体を返す */
export async function setItemServed(itemId: number, served: boolean): Promise<Order> {
  return (await http.patch<Order>(`/order-items/${itemId}/served`, { served })).data
}
