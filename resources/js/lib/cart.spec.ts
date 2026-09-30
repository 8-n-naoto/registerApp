import { describe, expect, it } from 'vitest'
import { addOne, canAddOne, isCartLines, lineKey, MAX_QUANTITY, orderedQty, reconcile, removeOne, toPricingItems, type CartLine } from '@/lib/cart'
import type { Product } from '@/types/api'

function product(id: number, extra: Partial<Product> = {}): Product {
  return {
    id, category_id: null, code: `P${id}`, name: `商品${id}`, memo: null, price: 400, color: 'gray', sort_order: id, is_active: true,
    track_stock: false, stock_qty: 0, customer_visible: true, is_discount: false, options: [], ...extra,
  }
}

const withOptions = product(3, {
  price: 500,
  options: [
    { id: 11, product_id: 3, name: 'ショット', price: 50, sort_order: 1, is_active: true },
    { id: 12, product_id: 3, name: 'オーツ', price: 60, sort_order: 2, is_active: true },
  ],
})

describe('lib/cart（S02 の注文）', () => {
  it('lineKey はオプションの順番によらない', () => {
    expect(lineKey(3, [12, 11])).toBe('3:11,12')
    expect(lineKey(3, [11, 12])).toBe('3:11,12')
    expect(lineKey(1, [])).toBe('1:')
  })

  it('AC-S02-2：同じ商品を 3 回で 1 行・数量 3、オプション違いは別の行', () => {
    let lines: CartLine[] = []
    for (let i = 0; i < 3; i++) lines = addOne(lines, product(1), [])
    expect(lines).toEqual([{ key: '1:', product_id: 1, option_ids: [], quantity: 3 }])

    lines = addOne(lines, withOptions, [12, 11])
    lines = addOne(lines, withOptions, [11])
    lines = addOne(lines, withOptions, [11, 12])
    expect(lines.map((l) => [l.key, l.quantity])).toEqual([['1:', 3], ['3:11,12', 2], ['3:11', 1]])
    expect(orderedQty(lines, 3)).toBe(3)
  })

  it('AC-S02-4：在庫管理 ON・在庫 2 は 2 個で止まる（オプション違いも合算）。OFF は何個でも', () => {
    const tracked = product(2, { track_stock: true, stock_qty: 2, options: withOptions.options })
    let lines: CartLine[] = []
    lines = addOne(lines, tracked, [])
    lines = addOne(lines, tracked, [11])
    expect(canAddOne(lines, tracked)).toBe(false)
    expect(addOne(lines, tracked, [])).toEqual(lines)

    const soldOut = product(4, { track_stock: true, stock_qty: 0 })
    expect(canAddOne([], soldOut)).toBe(false)

    let free: CartLine[] = []
    for (let i = 0; i < 50; i++) free = addOne(free, product(1), [])
    expect(free[0]?.quantity).toBe(50)
    expect(canAddOne(free, product(1), '1:')).toBe(true)
  })

  it('1 行の数量は 999 まで', () => {
    const lines: CartLine[] = [{ key: '1:', product_id: 1, option_ids: [], quantity: MAX_QUANTITY }]
    expect(canAddOne(lines, product(1), '1:')).toBe(false)
    expect(addOne(lines, product(1), [])[0]?.quantity).toBe(MAX_QUANTITY)
  })

  it('removeOne は数量を減らし、0 になった行を消す', () => {
    const lines: CartLine[] = [
      { key: '1:', product_id: 1, option_ids: [], quantity: 2 },
      { key: '2:', product_id: 2, option_ids: [], quantity: 1 },
    ]
    expect(removeOne(lines, '1:')[0]?.quantity).toBe(1)
    expect(removeOne(lines, '2:').map((l) => l.key)).toEqual(['1:'])
  })

  it('reconcile は無くなった商品・オプションの行を外し、名前を返す', () => {
    const lines: CartLine[] = [
      { key: '1:', product_id: 1, option_ids: [], quantity: 1 },
      { key: '3:12', product_id: 3, option_ids: [12], quantity: 1 },
      { key: '9:', product_id: 9, option_ids: [], quantity: 1 },
    ]
    const now = new Map<number, Product>([
      [1, product(1)],
      [3, { ...withOptions, options: withOptions.options.filter((o) => o.id !== 12) }],
    ])
    const previous = new Map<number, Product>([[9, product(9, { name: '限定品', memo: '春' })]])
    const result = reconcile(lines, now, previous)
    expect(result.lines.map((l) => l.key)).toEqual(['1:'])
    expect(result.removed).toEqual(['商品3', '限定品（春）'])
  })

  it('toPricingItems はマスタの価格とオプションの価格を渡す', () => {
    const lines: CartLine[] = [{ key: '3:11,12', product_id: 3, option_ids: [11, 12], quantity: 2 }]
    expect(toPricingItems(lines, new Map([[3, withOptions]]))).toEqual([{ unit_price: 500, option_prices: [50, 60], quantity: 2 }])
  })

  it('isCartLines は壊れた値を拒否する', () => {
    expect(isCartLines([{ key: '1:', product_id: 1, option_ids: [], quantity: 1 }])).toBe(true)
    expect(isCartLines([{ key: '1:', product_id: 1, option_ids: [], quantity: 0 }])).toBe(false)
    expect(isCartLines([{ key: '1:', product_id: '1', option_ids: [], quantity: 1 }])).toBe(false)
    expect(isCartLines([{ key: '1:', product_id: 1, option_ids: ['a'], quantity: 1 }])).toBe(false)
    expect(isCartLines({})).toBe(false)
  })
})
