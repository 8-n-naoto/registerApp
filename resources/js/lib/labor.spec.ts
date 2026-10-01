import { describe, expect, it } from 'vitest'
import { formatDay, formatMinutes, formatMonth, monthDays, normalizeShiftTime, shiftMonth, toLocalInput, weekdayOf } from '@/lib/labor'

describe('lib/labor', () => {
  it('分を H:MM にする', () => {
    expect(formatMinutes(0)).toBe('0:00')
    expect(formatMinutes(545)).toBe('9:05')
    expect(formatMinutes(6000)).toBe('100:00')
    expect(formatMinutes(null)).toBe('—')
  })

  it('月をずらす・月の日を並べる', () => {
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(formatMonth('2026-10')).toBe('2026年10月')
    expect(monthDays('2028-02')).toHaveLength(29)
    expect(monthDays('2026-09').at(-1)).toBe('2026-09-30')
    expect(weekdayOf('2026-10-04')).toBe(0)
  })

  it('入力の形にそろえる', () => {
    expect(toLocalInput('2026-09-29T09:02:00+09:00')).toBe('2026-09-29T09:02')
    expect(toLocalInput(null)).toBe('')
    expect(normalizeShiftTime('9:00')).toBe('09:00')
    expect(normalizeShiftTime('2600')).toBe('26:00')
    expect(normalizeShiftTime('25：30')).toBe('25:30')
    expect(normalizeShiftTime('abc')).toBe('abc')
  })
})

describe('formatDay', () => {
  it('月/日（曜）', () => {
    expect(formatDay('2026-10-05')).toBe('10/5（月）')
    expect(formatDay('x')).toBe('x')
  })
})
