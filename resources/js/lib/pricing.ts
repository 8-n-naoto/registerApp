// 07 §1・§2・§12：金額計算。サーバーの App\Services\PriceCalculator と同じ結果を返す
// （tests/vectors/pricing.json を両方で通す）。画面の合計・税・お釣り・expected_total はここの結果だけを使う。
// 浮動小数を使わない：Math.floor(n / d) は大きな n で誤るので、(n - n % d) / d で割る（07 §2.2）
import type { DiscountType, PriceMode, Rounding } from '@/types/api'

export interface PricingItem {
  unit_price: number
  option_prices: number[]
  quantity: number
}

export interface PricingDiscount {
  type: DiscountType
  value: number
}

export interface PricingAmountsInput {
  price_mode: PriceMode
  rounding: Rounding
  tax_rate_permille: number
  items: PricingItem[]
  discount: PricingDiscount | null
}

export interface PricingInput extends PricingAmountsInput {
  is_cash: boolean
  received: number | null
}

export interface PricingAmounts {
  line_totals: number[]
  subtotal: number
  discount_amount: number
  total: number
  tax_amount: number
}

export interface PricingResult extends PricingAmounts {
  received: number
  change_amount: number
}

export type PricingErrorReason = 'NEGATIVE_LINE_PRICE' | 'NEGATIVE_SUBTOTAL' | 'RECEIVED_SHORT' | 'INVALID_ARGUMENT'

/** 07 §1.4 の計算エラー。message は reason（試験ベクタの expected_error）で始まる */
export class PricingError extends Error {
  constructor(
    readonly reason: PricingErrorReason,
    readonly itemIndex: number | null = null,
    readonly total: number | null = null,
  ) {
    super(reason)
    this.name = 'PricingError'
  }
}

function assertInt(...values: number[]): void {
  for (const v of values) {
    if (!Number.isSafeInteger(v)) throw new PricingError('INVALID_ARGUMENT')
  }
}

/** 07 §2：非負の整数 n / d を端数処理する。round は 0.5 を切り上げる */
export function roundDiv(numerator: number, denominator: number, rounding: Rounding): number {
  assertInt(numerator, denominator)
  if (numerator < 0 || denominator <= 0) throw new PricingError('INVALID_ARGUMENT')
  const r = numerator % denominator
  const q = (numerator - r) / denominator
  if (rounding === 'floor') return q
  if (rounding === 'ceil') return q + (r > 0 ? 1 : 0)
  return q + (2 * r >= denominator ? 1 : 0)
}

/**
 * 07 §1.3 の金額部分（小計・値引き・合計・税）。expected_total はこの total。
 * 単価が負の明細は割引の商品（docs/10「割引の商品」）で、オプションを持てない。小計が負ならエラー
 */
export function calculateAmounts(input: PricingAmountsInput): PricingAmounts {
  const { rounding, tax_rate_permille: rate } = input
  assertInt(rate)

  const lineTotals = input.items.map((item, i) => {
    assertInt(item.unit_price, item.quantity, ...item.option_prices)
    const perUnit = item.option_prices.reduce((sum, p) => sum + p, item.unit_price)
    const isDiscount = item.unit_price < 0
    if (isDiscount ? item.option_prices.length > 0 : perUnit < 0) throw new PricingError('NEGATIVE_LINE_PRICE', i)
    return perUnit * item.quantity
  })
  const subtotal = lineTotals.reduce((sum, v) => sum + v, 0)
  assertInt(subtotal)
  if (subtotal < 0) throw new PricingError('NEGATIVE_SUBTOTAL')

  let discountAmount = 0
  if (input.discount !== null) {
    assertInt(input.discount.value)
    discountAmount = input.discount.type === 'amount'
      ? Math.min(input.discount.value, subtotal)
      : Math.min(roundDiv(subtotal * input.discount.value, 100, rounding), subtotal)
  }
  const after = subtotal - discountAmount

  let total: number
  let tax: number
  if (input.price_mode === 'tax_included') {
    total = after
    tax = roundDiv(total * rate, 1000 + rate, rounding)
  } else {
    tax = roundDiv(after * rate, 1000, rounding)
    total = after + tax
  }

  return { line_totals: lineTotals, subtotal, discount_amount: discountAmount, total, tax_amount: tax }
}

/** 07 §1.1 預かり・お釣り。現金以外は預かり = 合計、お釣り 0 */
export function settle(total: number, isCash: boolean, received: number | null): { received: number; change_amount: number } {
  if (!isCash) return { received: total, change_amount: 0 }
  if (received === null || received < total) throw new PricingError('RECEIVED_SHORT', null, total)
  assertInt(received)
  return { received, change_amount: received - total }
}

/** 07 §1.3 の calculate（金額 → 預かりの順に判定する） */
export function calculate(input: PricingInput): PricingResult {
  const amounts = calculateAmounts(input)
  return { ...amounts, ...settle(amounts.total, input.is_cash, input.received) }
}
