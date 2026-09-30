import type { Order, OrderItem, OrderTable } from '@/types/api'

/** テスト用：テーブル（#56 の 1 件） */
export function makeTable(extra: Partial<OrderTable> = {}): OrderTable {
  return {
    id: 1,
    name: 'T1',
    sort_order: 1,
    is_active: true,
    opened_at: null,
    session_expires_at: null,
    unpaid_order_count: 0,
    unpaid_subtotal: 0,
    token_rotated_at: '2026-09-28T18:30:00+09:00',
    ...extra,
  }
}

/** テスト用：注文の品目 */
export function makeOrderItem(extra: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 1001,
    product_id: 1,
    product_code: 'P0001',
    product_name: 'コーヒー',
    product_memo: null,
    unit_price: 400,
    options_price: 0,
    quantity: 1,
    line_total: 400,
    memo: null,
    served_at: null,
    options: [],
    ...extra,
  }
}

/** テスト用：注文（#49・#50 の 1 件） */
export function makeOrder(extra: Partial<Order> = {}): Order {
  return {
    id: 101,
    client_uuid: '00000000-0000-4000-8000-000000000101',
    business_date: '2026-09-29',
    order_no: 1,
    source: 'staff',
    order_table_id: 1,
    table_name: 'T1',
    label: null,
    takeout_no: null,
    status: 'active',
    note: null,
    subtotal: 400,
    served_at: null,
    sale_id: null,
    user_name: '山田',
    created_at: '2026-09-29T12:00:00+09:00',
    items: [makeOrderItem()],
    ...extra,
  }
}
