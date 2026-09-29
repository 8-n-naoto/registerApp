import { describe, expect, it } from 'vitest'
import { closingFigures, countCash, differenceKind } from './closing'

describe('closingFigures（07 §6.1・§6.3）', () => {
  it('C02：準備金 10000・現金売上 1700・実際 11650 → あるべき 11700・不足 50', () => {
    expect(closingFigures(10000, 1700, 11650)).toEqual({ expected: 11700, difference: -50 })
  })

  it('C03：実際 11800 → 過剰 100', () => {
    expect(closingFigures(10000, 1700, 11800).difference).toBe(100)
  })

  it('AC-S07-1：準備金 30000・現金売上 52300・実際 82200 → 不足 100', () => {
    expect(closingFigures(30000, 52300, 82200)).toEqual({ expected: 82300, difference: -100 })
  })
})

describe('differenceKind', () => {
  it('負は不足、正は過剰、0 は過不足なし', () => {
    expect(differenceKind(-1)).toBe('short')
    expect(differenceKind(1)).toBe('over')
    expect(differenceKind(0)).toBe('even')
  })
})

describe('countCash（AC-S07-3）', () => {
  it('金種ごとの枚数の合計。未入力は 0 枚', () => {
    expect(countCash({ 10000: 8, 1000: 2, 100: 1, 1: null })).toBe(82100)
    expect(countCash({ 10000: 1, 5000: 1, 1000: 1, 500: 1, 100: 1, 50: 1, 10: 1, 5: 1, 1: 1 })).toBe(16666)
    expect(countCash({})).toBe(0)
  })
})
