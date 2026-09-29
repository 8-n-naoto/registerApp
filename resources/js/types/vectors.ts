// tests/vectors/pricing.json の型（07 §12.3）。JSON を any で受けないために定義する
import type { PricingErrorReason, PricingInput, PricingResult } from '@/lib/pricing'

export interface RoundingVector {
  id: string
  numerator: number
  denominator: number
  expected?: { floor: number; round: number; ceil: number }
  expected_error?: 'INVALID_ARGUMENT'
  note: string
}

export interface PricingVector {
  id: string
  note: string
  input: PricingInput
  expected?: PricingResult
  expected_error?: Exclude<PricingErrorReason, 'INVALID_ARGUMENT'>
  expected_error_index?: number
  expected_total?: number
}

export interface PricingVectorFile {
  version: number
  rounding: RoundingVector[]
  pricing: PricingVector[]
}
