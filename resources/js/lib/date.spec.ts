import { describe, expect, it } from 'vitest'
import { formatBusinessDate, formatDateTime, formatMonthDayTime, formatTime, shiftDate } from './date'

describe('formatBusinessDate', () => {
  it('月/日（曜日）にする', () => {
    expect(formatBusinessDate('2026-09-29')).toBe('9/29（火）')
    expect(formatBusinessDate('2026-01-04')).toBe('1/4（日）')
    expect(formatBusinessDate('2028-02-29')).toBe('2/29（火）')
  })

  it('形式が違えばそのまま返す', () => {
    expect(formatBusinessDate('2026/09/29')).toBe('2026/09/29')
  })
})

describe('formatDateTime', () => {
  it('サーバーの時刻の表記のまま 年/月/日 時:分 にする', () => {
    expect(formatDateTime('2026-09-29T13:05:12+09:00')).toBe('2026/9/29 13:05')
    expect(formatDateTime('2026-01-02T00:00:00+09:00')).toBe('2026/1/2 00:00')
  })

  it('形式が違えばそのまま返す', () => {
    expect(formatDateTime('昨日')).toBe('昨日')
  })
})

describe('shiftDate', () => {
  it('月末・年末・うるう年をまたいでずらす', () => {
    expect(shiftDate('2026-09-30', 1)).toBe('2026-10-01')
    expect(shiftDate('2026-01-01', -1)).toBe('2025-12-31')
    expect(shiftDate('2028-02-28', 1)).toBe('2028-02-29')
    expect(shiftDate('2026-09-29', 0)).toBe('2026-09-29')
  })

  it('形式が違えばそのまま返す', () => {
    expect(shiftDate('today', 1)).toBe('today')
  })
})

describe('formatTime', () => {
  it('時:分にする', () => {
    expect(formatTime('2026-09-30T01:30:00+09:00')).toBe('01:30')
  })
})

describe('formatMonthDayTime', () => {
  it('月/日 時:分にする', () => {
    expect(formatMonthDayTime('2026-09-29T22:10:05+09:00')).toBe('9/29 22:10')
  })
})
