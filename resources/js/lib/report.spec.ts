import { describe, expect, it } from 'vitest'
import type { DailyReport } from '@/api/reports'
import { makeSale } from '@/test/register'
import { applyCancel, closingState } from './report'

/** 07 §7.4 A01〜A05 の営業日 09-29 の応答（S1〜S7） */
function makeReport(): DailyReport {
  return {
    date: '2026-09-29',
    totals: { total: 4150, count: 5, customers: 6, average: 830, discount_total: 100, cancelled_count: 1 },
    by_tax: [
      { tax_type_name: '店内', rate_permille: 100, total: 3250, tax_amount: 294, taxable_amount: 2956 },
      { tax_type_name: 'テイクアウト', rate_permille: 80, total: 900, tax_amount: 66, taxable_amount: 834 },
    ],
    by_payment: [
      { payment_method_name: '現金', is_cash: true, total: 1700, count: 2 },
      { payment_method_name: 'カード', is_cash: false, total: 1450, count: 2 },
      { payment_method_name: 'QR', is_cash: false, total: 1000, count: 1 },
    ],
    by_product: [
      { product_id: 2, product_name: 'ケーキ', product_code: 'P0002', product_memo: null, quantity: 5, amount: 2500 },
      { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, quantity: 3, amount: 1300 },
      { product_id: 1, product_name: 'ブレンド', product_code: 'P0001', product_memo: null, quantity: 1, amount: 400 },
    ],
    by_category: [
      { category_id: 20, category_name: 'フード', quantity: 5, amount: 2500 },
      { category_id: 10, category_name: 'ドリンク', quantity: 4, amount: 1700 },
    ],
    sales: [
      { id: 4, sold_at: '2026-09-30T01:30:00+09:00', total: 1000, payment_method_name: 'QR', tax_type_name: '店内', user_name: '店長', status: 'completed', item_count: 2 },
      { id: 1, sold_at: '2026-09-29T10:15:00+09:00', total: 1300, payment_method_name: '現金', tax_type_name: '店内', user_name: '店長', status: 'completed', item_count: 3 },
    ],
    closing: null,
    comparison: null,
  }
}

/** S1：現金 1300（コーヒー 400×2 = 800、ケーキ 500×1）、客 2 名、店内 10% */
const s1 = makeSale({
  id: 1, tax_type_name: '店内', tax_rate_permille: 100, total: 1300, tax_amount: 118, customer_count: 2, status: 'cancelled',
  items: [
    { id: 11, product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, category_id: 10, category_name: 'ドリンク', unit_price: 400, options_price: 0, quantity: 2, line_total: 800, options: [] },
    { id: 12, product_id: 2, product_name: 'ケーキ', product_code: 'P0002', product_memo: null, category_id: 20, category_name: 'フード', unit_price: 500, options_price: 0, quantity: 1, line_total: 500, options: [] },
  ],
})

describe('applyCancel（08 AC-S05-1）', () => {
  it('合計・件数・客数・平均・取消件数と内訳から差し引き、一覧の状態を変える', () => {
    const r = applyCancel(makeReport(), s1)
    expect(r.totals).toEqual({ total: 2850, count: 4, customers: 4, average: 712, discount_total: 100, cancelled_count: 2 })
    expect(r.by_tax[0]).toEqual({ tax_type_name: '店内', rate_permille: 100, total: 1950, tax_amount: 176, taxable_amount: 1774 })
    expect(r.by_payment).toEqual([
      { payment_method_name: 'カード', is_cash: false, total: 1450, count: 2 },
      { payment_method_name: 'QR', is_cash: false, total: 1000, count: 1 },
      { payment_method_name: '現金', is_cash: true, total: 400, count: 1 },
    ])
    expect(r.by_product).toEqual([
      { product_id: 2, product_name: 'ケーキ', product_code: 'P0002', product_memo: null, quantity: 4, amount: 2000 },
      { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, quantity: 1, amount: 500 },
      { product_id: 1, product_name: 'ブレンド', product_code: 'P0001', product_memo: null, quantity: 1, amount: 400 },
    ])
    expect(r.by_category).toEqual([
      { category_id: 20, category_name: 'フード', quantity: 4, amount: 2000 },
      { category_id: 10, category_name: 'ドリンク', quantity: 2, amount: 900 },
    ])
    expect(r.sales.map((s) => s.status)).toEqual(['completed', 'cancelled'])
  })

  it('0 件になった行は外し、件数 0 の平均は 0。締め済みなら締め後に変更ありにする', () => {
    const report: DailyReport = {
      ...makeReport(),
      totals: { total: 1300, count: 1, customers: 2, average: 1300, discount_total: 0, cancelled_count: 0 },
      by_tax: [{ tax_type_name: '店内', rate_permille: 100, total: 1300, tax_amount: 118, taxable_amount: 1182 }],
      by_payment: [{ payment_method_name: '現金', is_cash: true, total: 1300, count: 1 }],
      by_product: [
        { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, quantity: 2, amount: 800 },
        { product_id: 2, product_name: 'ケーキ', product_code: 'P0002', product_memo: null, quantity: 1, amount: 500 },
      ],
      by_category: [
        { category_id: 10, category_name: 'ドリンク', quantity: 2, amount: 800 },
        { category_id: 20, category_name: 'フード', quantity: 1, amount: 500 },
      ],
      closing: {
        business_date: '2026-09-29', float_amount: 10000, cash_sales: 1300, expected_cash: 11300, counted_cash: 11300,
        difference: 0, memo: null, changed_after_close: false, user_name: '店長', updated_at: '2026-09-29T20:00:00+09:00',
      },
    }
    const r = applyCancel(report, s1)
    expect(r.totals).toEqual({ total: 0, count: 0, customers: 0, average: 0, discount_total: 0, cancelled_count: 1 })
    expect(r.by_tax).toEqual([])
    expect(r.by_payment).toEqual([])
    expect(r.by_product).toEqual([])
    expect(r.by_category).toEqual([])
    expect(r.closing?.changed_after_close).toBe(true)
    expect(closingState(r)).toBe('changed')
  })

  it('同じ商品でもメモ・商品コードの写しが違う行は別に扱う', () => {
    const report: DailyReport = {
      ...makeReport(),
      by_product: [
        { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: 'アイス', quantity: 2, amount: 800 },
        { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, quantity: 3, amount: 1300 },
      ],
    }
    const sale = { ...s1, items: [{ ...s1.items[0]!, product_memo: 'アイス' }] }
    expect(applyCancel(report, sale).by_product).toEqual([
      { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, quantity: 3, amount: 1300 },
    ])
  })

  it('カテゴリは会計時点の写し（ID と名前）ごとに差し引き、未分類は null の行から引く', () => {
    const report: DailyReport = {
      ...makeReport(),
      by_category: [
        { category_id: 10, category_name: 'ドリンク', quantity: 4, amount: 1700 },
        { category_id: 10, category_name: '飲み物', quantity: 2, amount: 800 },
        { category_id: null, category_name: null, quantity: 3, amount: 900 },
      ],
    }
    const sale = {
      ...s1,
      items: [{ ...s1.items[0]!, category_id: 10, category_name: '飲み物' }, { ...s1.items[1]!, category_id: null, category_name: null }],
    }
    expect(applyCancel(report, sale).by_category).toEqual([
      { category_id: 10, category_name: 'ドリンク', quantity: 4, amount: 1700 },
      { category_id: null, category_name: null, quantity: 2, amount: 400 },
    ])
  })

  it('一覧に無い会計・取消済みの会計では変えない', () => {
    const report = makeReport()
    expect(applyCancel(report, { ...s1, id: 999 })).toBe(report)
    const cancelled = applyCancel(report, s1)
    expect(applyCancel(cancelled, s1)).toBe(cancelled)
  })
})

describe('closingState', () => {
  const closing = {
    business_date: '2026-09-29', float_amount: 10000, cash_sales: 0, expected_cash: 10000, counted_cash: 10000,
    difference: 0, memo: null, changed_after_close: false, user_name: '店長', updated_at: '2026-09-29T20:00:00+09:00',
  }

  it('未・済・締め後に変更あり', () => {
    expect(closingState({ closing: null })).toBe('none')
    expect(closingState({ closing })).toBe('done')
    expect(closingState({ closing: { ...closing, changed_after_close: true } })).toBe('changed')
  })
})
