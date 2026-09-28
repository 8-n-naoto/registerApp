import { describe, expect, it } from 'vitest'
import { formatYen } from './money'

describe('formatYen', () => {
  it('3 桁区切りと ¥ を付ける', () => {
    expect(formatYen(0)).toBe('¥0')
    expect(formatYen(1234)).toBe('¥1,234')
    expect(formatYen(99999999)).toBe('¥99,999,999')
  })

  it('負数は符号を ¥ の前に置く', () => {
    expect(formatYen(-500)).toBe('-¥500')
  })
})
