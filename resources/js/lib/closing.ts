/** レジ締めの計算（07 §6.1）。画面の表示用。保存時はサーバーが現金売上を再計算する */

/** 金種（08 §5.8） */
export const DENOMINATIONS = [10000, 5000, 1000, 500, 100, 50, 10, 5, 1] as const
export type Denomination = (typeof DENOMINATIONS)[number]

/** 金種ごとの枚数から合計を求める（未入力は 0 枚） */
export function countCash(counts: Partial<Record<Denomination, number | null>>): number {
  return DENOMINATIONS.reduce((sum, d) => sum + d * (counts[d] ?? 0), 0)
}

export interface ClosingFigures {
  expected: number // あるべき現金 = つり銭準備金 + 現金売上
  difference: number // 過不足 = 実際の現金 − あるべき現金（負 = 不足、正 = 過剰）
}

export function closingFigures(floatAmount: number, cashSales: number, countedCash: number): ClosingFigures {
  const expected = floatAmount + cashSales
  return { expected, difference: countedCash - expected }
}

export type DifferenceKind = 'short' | 'over' | 'even'

export function differenceKind(difference: number): DifferenceKind {
  if (difference < 0) return 'short'
  return difference > 0 ? 'over' : 'even'
}
