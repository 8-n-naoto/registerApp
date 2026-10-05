import { describe, expect, it } from 'vitest'
import { describeChanges, describeTarget, isAuditAction } from '@/lib/auditLog'

describe('auditLog', () => {
  it('変更は「項目 変更前 → 変更後」を 1 行にする（AC-S12-1）', () => {
    expect(describeChanges({ before: { price: 400 }, after: { price: 450 } })).toBe('価格 400 → 450')
    expect(describeChanges({ before: { is_active: true, rate_permille: 80 }, after: { is_active: false, rate_permille: 100 } }))
      .toBe('有効 はい → いいえ、税率 8% → 10%')
  })

  it('追加は変更後だけ、削除は変更前だけを出す', () => {
    expect(describeChanges({ before: null, after: { name: 'コーヒー', price_mode: 'tax_excluded' } })).toBe('名前 コーヒー、価格の扱い 税抜')
    expect(describeChanges({ before: { name: '軽食' }, after: null })).toBe('名前 軽食')
    expect(describeChanges({ before: null, after: null })).toBe('')
  })

  it('パスワードやトークンの項目は表示しない（AC-S12-2）', () => {
    const text = describeChanges({ before: null, after: { name: '佐藤', password: 'secret-value', token: 'abc', password_hash: 'x' } })
    expect(text).toBe('名前 佐藤')
    expect(text).not.toContain('secret-value')
  })

  it('配列・空の値・未知の項目', () => {
    expect(describeChanges({ before: null, after: { new_categories: ['飲み物', '軽食'], memo: null, unknown_key: 'v' } }))
      .toBe('新しいカテゴリ 飲み物、軽食、メモ —、unknown_key v')
    expect(describeChanges({ before: null, after: { tax_types: [{ id: 1 }, { id: 2 }] } })).toBe('税区分 2 件')
  })

  it('注文・打刻の項目は日本語、日時は「年/月/日 時:分」', () => {
    expect(describeChanges({ before: null, after: { order_no: 1, table_name: 'T1', item_count: 2, subtotal: 480 } }))
      .toBe('注文番号 1、テーブル T1、点数 2、小計 480')
    expect(describeChanges({ before: null, after: { clock_out_at: '2026-10-05T03:19:47+09:00' } })).toBe('退勤 2026/10/5 03:19')
    expect(describeChanges({ before: { status: 'pending' }, after: { status: 'active' } })).toBe('状態 確認待ち → 受付済み')
  })

  it('勤怠の操作も絞り込みに出す', () => {
    expect(isAuditAction('attendance_clocked_out')).toBe(true)
  })

  it('対象は「種類 #ID」', () => {
    expect(describeTarget({ target_type: 'product', target_id: 12 })).toBe('商品 #12')
    expect(describeTarget({ target_type: 'store', target_id: null })).toBe('店舗')
    expect(describeTarget({ target_type: null, target_id: null })).toBe('—')
  })

  it('操作コードの判定', () => {
    expect(isAuditAction('product_updated')).toBe(true)
    expect(isAuditAction('drop_table')).toBe(false)
  })
})
