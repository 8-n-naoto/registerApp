import { describe, expect, it } from 'vitest'
import { canCancelItem, canCancelOrder, liveItems, orderDisplayStatus, orderPlace } from '@/lib/orders'

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

  it('置き場所はテーブル名 → テーブル未設定#連番（呼び名を添える）→ 呼び名 → テーブルなし', () => {
    expect(orderPlace({ table_name: 'T1', label: '窓側', takeout_no: null })).toBe('T1')
    expect(orderPlace({ table_name: null, label: null, takeout_no: 3 })).toBe('テーブル未設定#3')
    expect(orderPlace({ table_name: null, label: '田中', takeout_no: 1 })).toBe('テーブル未設定#1（田中）')
    expect(orderPlace({ table_name: null, label: '窓側', takeout_no: null })).toBe('窓側')
    expect(orderPlace({ table_name: null, label: null, takeout_no: null })).toBe('テーブルなし')
  })

  it('17：取消済みの品目は数えず、取り消せるのは取り消せる注文の取消済みでない品目だけ', () => {
    const live = { id: 1, cancelled_at: null }
    const gone = { id: 2, cancelled_at: '2026-09-29T12:30:00+09:00' }
    expect(liveItems([live, gone])).toEqual([live])
    expect(canCancelItem({ status: 'active', sale_id: null }, live)).toBe(true)
    expect(canCancelItem({ status: 'active', sale_id: null }, gone)).toBe(false)
    expect(canCancelItem({ status: 'active', sale_id: 9 }, live)).toBe(false)
    expect(canCancelItem({ status: 'cancelled', sale_id: null }, live)).toBe(false)
  })
})
