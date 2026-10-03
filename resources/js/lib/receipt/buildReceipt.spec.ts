import { describe, expect, it } from 'vitest'
import { buildReceipt, buildTestPage, columnsOf, leftRight, textWidth, wrap } from '@/lib/receipt/buildReceipt'
import { envelope, escapeXml } from '@/lib/receipt/webprnt'
import { makeSale } from '@/test/register'
import type { Sale, SaleItem } from '@/types/api'

/** 要求の <text> の中身を 1 行ずつ取り出す（末尾の改行を除く） */
function texts(request: string): string[] {
  return [...request.matchAll(/<text [^>]*>([\s\S]*?)<\/text>/g)].map((m) =>
    (m[1] ?? '')
      .replace(/&#10;$/, '')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')
      .replace(/&apos;/g, "'")
      .replace(/&amp;/g, '&'))
}

/** 倍角の行（width="2"）の中身 */
function bigTexts(request: string): string[] {
  return [...request.matchAll(/<text [^>]*width="2"[^>]*>([\s\S]*?)<\/text>/g)].map((m) => (m[1] ?? '').replace(/&#10;$/, ''))
}

function receipt(extra: Partial<Sale> = {}, paperWidth: 80 | 58 = 80, kind: 'receipt' | 'reprint' = 'receipt'): string {
  return buildReceipt({ sale: makeSale(extra), paperWidth, kind })
}

/** 明細 1 行だけの会計 */
function withItem(extra: Partial<SaleItem>): string {
  const sale = makeSale()
  const first = sale.items[0]
  if (!first) throw new Error('item')
  return buildReceipt({ sale: { ...sale, items: [{ ...first, ...extra }] }, paperWidth: 80, kind: 'receipt' })
}

describe('15 §7.4 レシートの組み立て', () => {
  it('幅の数え方と折り返し・左右揃え', () => {
    expect(columnsOf(80)).toBe(48)
    expect(columnsOf(58)).toBe(32)
    expect(textWidth('ｺｰﾋｰ')).toBe(4)
    expect(textWidth('コーヒー¥')).toBe(9)
    expect(wrap('あいうえお', 4)).toEqual(['あい', 'うえ', 'お'])
    expect(leftRight('合計', '¥780', 12)).toEqual(['合計    ¥780'])
    // 左が長いときは折り返し、右は最後の行の右端（入らなければ右だけの行）
    expect(leftRight('あいうえおか', '¥1', 8)).toEqual(['あいうえ', 'おか  ¥1'])
    expect(leftRight('あいうえ', '¥1', 8)).toEqual(['あいうえ', '      ¥1'])
  })

  it('T1：80mm・明細 2 行・現金は 48 桁で左右揃え、合計は倍角、最後に切断', () => {
    const req = receipt()
    const lines = texts(req)
    expect(lines[0]).toBe('テスト店 A')
    expect(lines[1]).toBe('領収書')
    expect(lines).toContain(`コーヒー${' '.repeat(48 - 8 - 4)}¥400`)
    expect(lines).toContain('-'.repeat(48))
    for (const line of lines.slice(2)) expect(textWidth(line)).toBeLessThanOrEqual(48)
    expect(lines.find((l) => l.startsWith('お預かり'))).toBe(`お預かり${' '.repeat(48 - 8 - 6)}¥1,000`)
    expect(lines.find((l) => l.startsWith('お釣り'))?.endsWith('¥220')).toBe(true)
    expect(bigTexts(req)).toContain(`合計${' '.repeat(24 - 4 - 4)}¥780`)
    expect(req.startsWith('<initialization/>')).toBe(true)
    expect(req.endsWith('<cutpaper feed="true" type="partial"/>')).toBe(true)
  })

  it('T2：58mm は 32 桁', () => {
    const lines = texts(receipt({}, 58))
    expect(lines).toContain('-'.repeat(32))
    expect(lines).toContain(`コーヒー${' '.repeat(32 - 8 - 4)}¥400`)
  })

  it('T3：長い商品名は折り返し、金額は右端', () => {
    const lines = texts(withItem({ product_name: 'あ'.repeat(30), line_total: 1200 }))
    expect(lines).toContain('あ'.repeat(24))
    expect(lines).toContain(`${'あ'.repeat(6)}${' '.repeat(48 - 12 - 6)}¥1,200`)
  })

  it('T4：値引き（％）・外税', () => {
    const lines = texts(receipt({ discount_type: 'percent', discount_value: 10, discount_amount: 78, price_mode: 'tax_excluded' }))
    expect(lines.some((l) => l.startsWith('値引き（10%）') && l.endsWith('-¥78'))).toBe(true)
    expect(lines.some((l) => l.startsWith('消費税（10%）'))).toBe(true)
  })

  it('T5：メモは商品名の後ろ、オプションは 2 行目', () => {
    const lines = texts(withItem({
      product_memo: 'ホット',
      options_price: 50,
      line_total: 450,
      options: [{ product_option_id: 1, option_name: 'ショット', price: 50 }, { product_option_id: 2, option_name: '大盛り', price: 0 }],
    }))
    expect(lines).toContain(`コーヒー（ホット）${' '.repeat(48 - 18 - 4)}¥450`)
    expect(lines.some((l) => l.startsWith('  ショット・大盛り') && l.endsWith('¥450 × 1'))).toBe(true)
  })

  it('T6：再発行は「領収書（再発行）」', () => {
    expect(texts(receipt({}, 80, 'reprint'))[1]).toBe('領収書（再発行）')
  })

  it('T7：取消済みは「取消」を倍角で', () => {
    expect(bigTexts(receipt({ status: 'cancelled' }))).toContain('取消')
    expect(bigTexts(receipt())).not.toContain('取消')
  })

  it('T8：商品名の < & " はエスケープされ、包んでも構造が壊れない', () => {
    const req = withItem({ product_name: `<b>&"x'` })
    expect(req).toContain('&lt;b&gt;&amp;&quot;x&apos;')
    expect(req).not.toContain('<b>')
    const doc = new DOMParser().parseFromString(envelope(req), 'text/xml')
    expect(doc.getElementsByTagName('parsererror')).toHaveLength(0)
    // <Request> の中身を取り出すと元の要求に戻る
    expect(doc.getElementsByTagName('Request')[0]?.textContent).toBe(req)
    expect(escapeXml('a\nb')).toBe('a&#10;b')
  })

  it('端末に保存した会計（id = 0）は番号の代わりに「オフライン会計」', () => {
    const lines = texts(receipt({ id: 0, is_offline: true, sold_at: '2026-09-29T04:05:00.000Z' }))
    expect(lines.some((l) => l.endsWith('オフライン会計'))).toBe(true)
    expect(lines.some((l) => l.includes('会計 ID'))).toBe(false)
  })

  it('会計 ID を右に出す', () => {
    expect(texts(receipt()).some((l) => l.endsWith('会計 ID 501'))).toBe(true)
  })

  it('テスト印刷は店舗名・「テスト印刷」・桁の目安', () => {
    const lines = texts(buildTestPage('テスト店 A', 58, new Date(2026, 9, 4, 10, 0)))
    expect(lines[0]).toBe('テスト店 A')
    expect(lines[1]).toBe('テスト印刷')
    expect(lines).toContain('1 行 32 桁')
    expect(lines).toContain('12345678901234567890123456789012')
  })
})
