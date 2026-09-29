import { describe, expect, it } from 'vitest'
import { nameWithMemo, productSub } from './productLabel'

describe('nameWithMemo', () => {
  it('メモがあれば括弧で付ける', () => {
    expect(nameWithMemo('コーヒー', 'アイス')).toBe('コーヒー（アイス）')
  })

  it('メモが無い・空なら商品名だけ', () => {
    expect(nameWithMemo('コーヒー', null)).toBe('コーヒー')
    expect(nameWithMemo('コーヒー', undefined)).toBe('コーヒー')
    expect(nameWithMemo('コーヒー', '')).toBe('コーヒー')
  })
})

describe('productSub', () => {
  it('メモと商品コードを・でつなぐ', () => {
    expect(productSub({ product_code: 'P0011', product_memo: 'アイス' })).toBe('アイス・P0011')
  })

  it('無いものは省く', () => {
    expect(productSub({ product_code: 'P0001', product_memo: null })).toBe('P0001')
    expect(productSub({ product_code: '', product_memo: null })).toBe('')
  })
})
