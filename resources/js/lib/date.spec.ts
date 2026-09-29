import { describe, expect, it } from 'vitest'
import { formatBusinessDate, formatDateTime } from './date'

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
