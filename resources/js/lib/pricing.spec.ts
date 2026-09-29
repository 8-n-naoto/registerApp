import { describe, expect, it } from 'vitest'
import raw from '../../../tests/vectors/pricing.json'
import { calculate, PricingError, roundDiv } from '@/lib/pricing'
import type { PricingVectorFile } from '@/types/vectors'

// 07 §12：サーバー（tests/Unit/PriceCalculatorTest.php）と同じベクタを全件通す
const vectors = raw as PricingVectorFile

describe('試験ベクタのファイル', () => {
  it('件数と ID の重複', () => {
    expect(vectors.version).toBe(1)
    for (const [list, count] of [[vectors.rounding, 17], [vectors.pricing, 45]] as const) {
      const ids = list.map((v) => v.id)
      expect(ids).toHaveLength(count)
      expect(new Set(ids).size).toBe(ids.length)
    }
  })
})

describe.each(vectors.rounding)('丸め $id $note', (v) => {
  it('サーバーと同じ結果になる', () => {
    if (v.expected_error) {
      expect(() => roundDiv(v.numerator, v.denominator, 'floor')).toThrowError(v.expected_error)
      return
    }
    expect({
      floor: roundDiv(v.numerator, v.denominator, 'floor'),
      round: roundDiv(v.numerator, v.denominator, 'round'),
      ceil: roundDiv(v.numerator, v.denominator, 'ceil'),
    }).toEqual(v.expected)
  })
})

describe.each(vectors.pricing)('金額計算 $id $note', (v) => {
  it('サーバーと同じ結果になる', () => {
    if (!v.expected_error) {
      expect(calculate(v.input)).toEqual(v.expected)
      return
    }
    let caught: unknown = null
    try {
      calculate(v.input)
    } catch (e) {
      caught = e
    }
    expect(caught).toBeInstanceOf(PricingError)
    const err = caught as PricingError
    expect(err.reason).toBe(v.expected_error)
    if (v.expected_error_index !== undefined) expect(err.itemIndex).toBe(v.expected_error_index)
    if (v.expected_total !== undefined) expect(err.total).toBe(v.expected_total)
  })
})

describe('安全な整数でない入力', () => {
  it('小数・範囲外は例外（黙って計算しない）', () => {
    expect(() => roundDiv(1.5, 10, 'floor')).toThrowError('INVALID_ARGUMENT')
    expect(() => roundDiv(2 ** 53, 10, 'floor')).toThrowError('INVALID_ARGUMENT')
  })
})
