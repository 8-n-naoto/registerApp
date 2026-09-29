import { describe, expect, it } from 'vitest'
import { canCancelOrder, orderDisplayStatus, orderPlace } from '@/lib/orders'

describe('lib/orders', () => {
  it('表示の状態は取消 → 会計済み → 確認待ち → 提供済み → 提供中の順に決まる', () => {
    expect(orderDisplayStatus({ status: 'cancelled', sale_id: 1, served_at: 'x' })).toBe('cancelled')
    expect(orderDisplayStatus({ status: 'active', sale_id: 1, served_at: null })).toBe('paid')
    expect(orderDisplayStatus({ status: 'pending', sale_id: null, served_at: null })).toBe('pending')
    expect(orderDisplayStatus({ status: 'active', sale_id: null, served_at: 'x' })).toBe('served')
    expect(orderDisplayStatus({ status: 'active', sale_id: null, served_at: null })).toBe('active')
  })

  it('会計済み・取消済みは取り消せない（AC-S15-3）', () => {
    expect(canCancelOrder({ status: 'active', sale_id: null })).toBe(true)
    expect(canCancelOrder({ status: 'pending', sale_id: null })).toBe(true)
    expect(canCancelOrder({ status: 'active', sale_id: 9 })).toBe(false)
    expect(canCancelOrder({ status: 'cancelled', sale_id: null })).toBe(false)
  })

  it('置き場所はテーブル名 → 呼び名 → テーブルなし', () => {
    expect(orderPlace({ table_name: 'T1', label: '窓側' })).toBe('T1')
    expect(orderPlace({ table_name: null, label: '窓側' })).toBe('窓側')
    expect(orderPlace({ table_name: null, label: null })).toBe('テーブルなし')
  })
})
