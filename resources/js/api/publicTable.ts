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

/** main.ts が先に取りに行ったメニュー（最初の 1 回だけ使う） */
let early: { token: string; promise: Promise<PublicMenu> } | null = null

/** QR から開いたとき、画面の JS を読み込むあいだにメニューを取りに行く（main.ts から 1 回だけ呼ぶ） */
export function prefetchMenu(token: string): void {
  const promise = http.get<PublicMenu>(`${base(token)}/menu`).then((res) => res.data)
  promise.catch(() => undefined) // 失敗は fetchMenu を呼んだ画面が受け取る
  early = { token, promise }
}

/** #46 */
export async function fetchMenu(token: string): Promise<PublicMenu> {
  if (early?.token === token) {
    const { promise } = early
    early = null
    return promise
  }
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
