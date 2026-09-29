import { describe, expect, it } from 'vitest'
import { parseNonNegativeInt, parseSignedInt } from '@/lib/numberInput'

describe('数字の入力欄', () => {
  it('0 以上の整数', () => {
    expect(parseNonNegativeInt('450')).toBe(450)
    expect(parseNonNegativeInt(' １，２００ ')).toBe(1200)
    expect(parseNonNegativeInt('¥9,999,999')).toBe(9999999)
    expect(parseNonNegativeInt('0')).toBe(0)
    for (const bad of ['', '-1', '1.5', '12円', 'abc', '1234567890']) {
      expect(parseNonNegativeInt(bad)).toBeNull()
    }
  })

  it('符号つきの整数', () => {
    expect(parseSignedInt('-100')).toBe(-100)
    expect(parseSignedInt('－５０')).toBe(-50)
    expect(parseSignedInt('100')).toBe(100)
    for (const bad of ['', '--1', '1.0', '+', '-']) {
      expect(parseSignedInt(bad)).toBeNull()
    }
  })
})
