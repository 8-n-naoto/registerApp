// S02 の注文（08 §7.2 lines）の操作。金額は lib/pricing.ts で計算し、ここでは明細の組み立てと在庫の上限だけを扱う
import type { PricingItem } from '@/lib/pricing'
import { nameWithMemo } from '@/lib/productLabel'
import type { Product } from '@/types/api'

export interface CartLine {
  key: string // 商品 ID + 並べ替えたオプション ID（同じ組み合わせは 1 行）
  product_id: number
  option_ids: number[]
  quantity: number
}

export const MAX_LINES = 100 // 06 §4.2 items は 100 件まで
export const MAX_QUANTITY = 999 // 06 §4.2 quantity は 999 まで

export function lineKey(productId: number, optionIds: readonly number[]): string {
  return `${productId}:${[...optionIds].sort((a, b) => a - b).join(',')}`
}

/** その商品の注文数（オプション違いの行も合算。在庫はオプションに関係なく商品単位） */
export function orderedQty(lines: readonly CartLine[], productId: number): number {
  return lines.reduce((sum, l) => (l.product_id === productId ? sum + l.quantity : sum), 0)
}

/** もう 1 個足せるか（AC-S02-4：在庫管理 ON なら注文数が在庫に達したら不可。OFF は数量の上限だけ） */
export function canAddOne(lines: readonly CartLine[], product: Product, key?: string): boolean {
  if (key !== undefined && (lines.find((l) => l.key === key)?.quantity ?? 0) >= MAX_QUANTITY) return false
  if (!product.track_stock) return true
  return orderedQty(lines, product.id) < product.stock_qty
}

/** 1 個追加する。同じ組み合わせの行があれば数量を増やし、なければ末尾に行を足す。足せなければ元の配列を返す */
export function addOne(lines: readonly CartLine[], product: Product, optionIds: readonly number[]): CartLine[] {
  const key = lineKey(product.id, optionIds)
  if (!canAddOne(lines, product, key)) return [...lines]
  const existing = lines.find((l) => l.key === key)
  if (existing) return lines.map((l) => (l.key === key ? { ...l, quantity: l.quantity + 1 } : l))
  if (lines.length >= MAX_LINES) return [...lines]
  const sorted = [...optionIds].sort((a, b) => a - b)
  return [...lines, { key, product_id: product.id, option_ids: sorted, quantity: 1 }]
}

/** 数量を 1 減らす。0 になった行は消す */
export function removeOne(lines: readonly CartLine[], key: string): CartLine[] {
  return lines
    .map((l) => (l.key === key ? { ...l, quantity: l.quantity - 1 } : l))
    .filter((l) => l.quantity > 0)
}

/**
 * マスタ（bootstrap の商品）に合わせて注文を直す。販売を終えた商品・オプションの行は外し、外した商品名を返す
 * （AC-S02-9。名前は外す前のマスタから引く。無ければ空の名前は返さない）
 */
export function reconcile(
  lines: readonly CartLine[],
  products: ReadonlyMap<number, Product>,
  previous: ReadonlyMap<number, Product> = new Map(),
): { lines: CartLine[]; removed: string[] } {
  const kept: CartLine[] = []
  const removed: string[] = []
  for (const line of lines) {
    const product = products.get(line.product_id)
    const ok = product !== undefined
      && line.option_ids.every((id) => product.options.some((o) => o.id === id))
    if (ok) {
      kept.push(line)
    } else {
      const source = product ?? previous.get(line.product_id)
      const name = source === undefined ? undefined : nameWithMemo(source.name, source.memo)
      if (name !== undefined && !removed.includes(name)) removed.push(name)
    }
  }
  return { lines: kept, removed }
}

/** 計算用の明細（lib/pricing.ts の入力）。reconcile 済みの注文を渡す */
export function toPricingItems(lines: readonly CartLine[], products: ReadonlyMap<number, Product>): PricingItem[] {
  return lines.map((line) => {
    const product = products.get(line.product_id)
    if (!product) throw new Error(`product ${line.product_id} is not loaded`)
    return {
      unit_price: product.price,
      option_prices: line.option_ids.map((id) => product.options.find((o) => o.id === id)?.price ?? 0),
      quantity: line.quantity,
    }
  })
}

/** localStorage から読んだ値が注文の形か（壊れた値・古い形式を捨てる） */
export function isCartLines(value: unknown): value is CartLine[] {
  return Array.isArray(value) && value.every((l: unknown) => {
    if (typeof l !== 'object' || l === null) return false
    const r = l as Record<string, unknown>
    return typeof r.key === 'string'
      && Number.isSafeInteger(r.product_id)
      && Number.isSafeInteger(r.quantity) && (r.quantity as number) >= 1 && (r.quantity as number) <= MAX_QUANTITY
      && Array.isArray(r.option_ids) && r.option_ids.every((id) => Number.isSafeInteger(id))
  })
}
