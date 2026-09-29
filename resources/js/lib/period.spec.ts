import { describe, expect, it } from 'vitest'
import { isYmd, matchPreset, periodDays, periodError, presetPeriod, tokyoToday } from '@/lib/period'

describe('period', () => {
  it('実在する日付だけを YYYY-MM-DD として受け付ける', () => {
    expect(isYmd('2026-09-29')).toBe(true)
    expect(isYmd('2026-02-30')).toBe(false)
    expect(isYmd('2026-9-29')).toBe(false)
    expect(isYmd(undefined)).toBe(false)
  })

  it('日数は両端を含む', () => {
    expect(periodDays({ from: '2026-09-01', to: '2026-09-30' })).toBe(30)
    expect(periodDays({ from: '2026-09-29', to: '2026-09-29' })).toBe(1)
  })

  it('期間の検証：順序と 366 日の上限（06 §5.2）', () => {
    expect(periodError({ from: '2026-09-30', to: '2026-09-01' })).toBe('order')
    expect(periodError({ from: '2025-01-01', to: '2025-12-31' })).toBeNull()
    expect(periodError({ from: '2024-01-01', to: '2024-12-31' })).toBeNull() // うるう年の 366 日
    expect(periodError({ from: '2024-01-01', to: '2025-01-01' })).toBe('tooLong')
    expect(periodError({ from: 'x', to: '2026-09-01' })).toBe('invalid')
  })

  it('今週は月曜始まり、今月は 1 日から、先月は前月の 1 日〜末日', () => {
    expect(presetPeriod('thisWeek', '2026-09-29')).toEqual({ from: '2026-09-28', to: '2026-09-29' }) // 火曜
    expect(presetPeriod('thisWeek', '2026-10-04')).toEqual({ from: '2026-09-28', to: '2026-10-04' }) // 日曜
    expect(presetPeriod('thisMonth', '2026-09-29')).toEqual({ from: '2026-09-01', to: '2026-09-29' })
    expect(presetPeriod('lastMonth', '2026-03-15')).toEqual({ from: '2026-02-01', to: '2026-02-28' })
    expect(presetPeriod('lastMonth', '2026-01-10')).toEqual({ from: '2025-12-01', to: '2025-12-31' })
  })

  it('一致するプリセットを返す', () => {
    expect(matchPreset({ from: '2026-09-01', to: '2026-09-29' }, '2026-09-29')).toBe('thisMonth')
    expect(matchPreset({ from: '2026-09-02', to: '2026-09-29' }, '2026-09-29')).toBeNull()
  })

  it('東京の日付（UTC+9）', () => {
    expect(tokyoToday(new Date('2026-09-29T14:59:59Z'))).toBe('2026-09-29')
    expect(tokyoToday(new Date('2026-09-29T15:00:00Z'))).toBe('2026-09-30')
  })
})
