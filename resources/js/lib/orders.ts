// 注文の表示用の判定（12 §2.2）。S14 厨房・S15 注文確認・S02 の注文の選択で使う
import { fmt, ja } from '@/i18n/ja'
import type { Order } from '@/types/api'

export type OrderDisplayStatus = 'pending' | 'active' | 'served' | 'paid' | 'cancelled'

/** 取消 → 会計済み → 確認待ち → 提供済み（完了）→ 提供中 の順に判定する */
export function orderDisplayStatus(order: Pick<Order, 'status' | 'sale_id' | 'served_at'>): OrderDisplayStatus {
  if (order.status === 'cancelled') return 'cancelled'
  if (order.sale_id !== null) return 'paid'
  if (order.status === 'pending') return 'pending'
  return order.served_at !== null ? 'served' : 'active'
}

/** 取り消せるか（会計済み・取消済みは不可。12 §5.7） */
export function canCancelOrder(order: Pick<Order, 'status' | 'sale_id'>): boolean {
  return order.status !== 'cancelled' && order.sale_id === null
}

/** 注文の置き場所の表示：テーブル名 →「テーブル未設定#連番」（呼び名があれば括弧で添える）→ 呼び名 →「テーブルなし」 */
export function orderPlace(order: Pick<Order, 'table_name' | 'label' | 'takeout_no'>): string {
  if (order.table_name !== null) return order.table_name
  if (order.takeout_no !== null) {
    const place = fmt(ja.orders.takeoutPlace, { n: order.takeout_no })
    return order.label === null ? place : `${place}（${order.label}）`
  }
  return order.label ?? ja.orders.noTable
}
