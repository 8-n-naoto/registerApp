import { http } from '@/api/client'
import type { PublicMenu, PublicOrder } from '@/types/api'

// 12 §5.1〜§5.3 お客さんの公開 API（#46〜#48）。ログインを使わず、QR のトークンだけでテーブルを決める

export interface CustomerOrderInput {
  client_uuid: string
  items: { product_id: number; quantity: number; option_ids: number[]; memo: string | null }[]
  note: string | null
  expected_subtotal: number
}

function base(token: string): string {
  return `/public/tables/${encodeURIComponent(token)}`
}

/** #46 */
export async function fetchMenu(token: string): Promise<PublicMenu> {
  return (await http.get<PublicMenu>(`${base(token)}/menu`)).data
}

/** #47 新規は 201、同じ client_uuid の再送は 200（どちらも注文を返す） */
export async function createCustomerOrder(token: string, input: CustomerOrderInput): Promise<PublicOrder> {
  return (await http.post<PublicOrder>(`${base(token)}/orders`, input)).data
}

/** #48 このテーブルの今回の利用中の注文 */
export async function fetchCustomerOrders(token: string): Promise<PublicOrder[]> {
  return (await http.get<{ orders: PublicOrder[] }>(`${base(token)}/orders`)).data.orders
}
