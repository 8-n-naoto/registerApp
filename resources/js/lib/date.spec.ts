import { describe, expect, it } from 'vitest'
import { formatBusinessDate } from './date'

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
