import type { RegisterBootstrap } from '@/api/register'
import type { Product, Sale } from '@/types/api'

/** テスト用：レジの商品 */
export function makeProduct(id: number, name: string, extra: Partial<Product> = {}): Product {
  return {
    id, category_id: null, code: `P${String(id).padStart(4, '0')}`, name, memo: null, price: 400, color: 'gray', sort_order: id, is_active: true,
    track_stock: false, stock_qty: 0, customer_visible: true, options: [], ...extra,
  }
}

/**
 * テスト用：GET /register/bootstrap（税込・切り捨て。標準 10%（既定）と軽減 8%、現金（既定）とカード）。
 * 商品：1 コーヒー ¥400、2 ケーキ ¥380（在庫 2）、3 ラテ ¥500（オプション ショット ¥50）
 */
export function makeBootstrap(extra: Partial<RegisterBootstrap> = {}): RegisterBootstrap {
  return {
    store: { id: 1, name: 'テスト店 A', price_mode: 'tax_included', rounding: 'floor', day_cutoff_time: '00:00', stock_enabled: true },
    current_business_date: '2026-09-29',
    server_time: '2026-09-29T13:00:00+09:00',
    tax_types: [
      { id: 1, name: '標準', rate_permille: 100, sort_order: 1, is_default: true, is_active: true },
      { id: 2, name: '軽減', rate_permille: 80, sort_order: 2, is_default: false, is_active: true },
    ],
    payment_methods: [
      { id: 1, name: '現金', is_cash: true, sort_order: 1, is_active: true },
      { id: 2, name: 'カード', is_cash: false, sort_order: 2, is_active: true },
    ],
    categories: [{ id: 10, name: 'ドリンク', sort_order: 1, product_count: 2 }],
    products: [
      makeProduct(1, 'コーヒー', { category_id: 10 }),
      makeProduct(2, 'ケーキ', { price: 380, track_stock: true, stock_qty: 2 }),
      makeProduct(3, 'ラテ', {
        category_id: 10,
        price: 500,
        options: [{ id: 31, product_id: 3, name: 'ショット', price: 50, sort_order: 1, is_active: true }],
      }),
    ],
    ...extra,
  }
}

/** テスト用：会計（GET /sales/{id} の応答） */
export function makeSale(extra: Partial<Sale> = {}): Sale {
  return {
    id: 501,
    client_uuid: '00000000-0000-4000-8000-000000000000',
    business_date: '2026-09-29',
    sold_at: '2026-09-29T13:05:12+09:00',
    tax_type_name: '標準',
    tax_rate_permille: 100,
    price_mode: 'tax_included',
    subtotal: 780,
    discount_type: null,
    discount_value: 0,
    discount_amount: 0,
    total: 780,
    tax_amount: 70,
    payment_method_name: '現金',
    is_cash: true,
    received: 1000,
    change_amount: 220,
    customer_count: null,
    memo: null,
    status: 'completed',
    cancelled_at: null,
    cancelled_by_name: null,
    user_name: '山田',
    device_name: null,
    store_name: 'テスト店 A',
    items: [
      { id: 1, product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, unit_price: 400, options_price: 0, quantity: 1, line_total: 400, options: [] },
      { id: 2, product_id: 2, product_name: 'ケーキ', product_code: 'P0002', product_memo: null, unit_price: 380, options_price: 0, quantity: 1, line_total: 380, options: [] },
    ],
    ...extra,
  }
}
