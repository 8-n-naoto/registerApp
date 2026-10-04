import { describe, expect, it } from 'vitest'
import { buildReceipt } from '@/lib/receipt/buildReceipt'
import { layoutOf, MAX_IMAGE_HEIGHT, packBits, rasterRequest, toBase64, type Painter } from '@/lib/receipt/raster'
import { envelope, WebPrntRequest } from '@/lib/receipt/webprnt'
import { makeSale } from '@/test/register'

interface Drawn { text: string; x: number; y: number; maxWidth?: number; font: string }

/** canvas の代わり。描いた文字を記録し、画素はすべて白を返す */
function fakePainter(log: { sizes: Array<[number, number]>; drawn: Drawn[] }) {
  return (width: number, height: number): Painter => {
    log.sizes.push([width, height])
    const p: Painter = {
      fillStyle: '#000',
      font: '',
      textAlign: 'left',
      textBaseline: 'alphabetic',
      fillRect: () => undefined,
      fillText: (text, x, y, maxWidth) => {
        log.drawn.push({ text, x, y, maxWidth, font: p.font })
      },
      getImageData: (_sx, _sy, sw, sh) => ({ data: new Uint8ClampedArray(sw * sh * 4).fill(255) }),
    }
    return p
  }
}

describe('15 §3.5 画像での印字', () => {
  it('升目：58mm は 384 ドットに 32 桁、80mm は 576 ドットに 48 桁で、左右に余白', () => {
    const l58 = layoutOf(58)
    expect(l58.width).toBe(384)
    expect(l58.cell * 32).toBeLessThanOrEqual(384 - 16)
    expect(l58.left).toBeGreaterThanOrEqual(8)
    const l80 = layoutOf(80)
    expect(l80.width).toBe(576)
    expect(l80.cell * 48).toBeLessThanOrEqual(576 - 16)
  })

  it('白黒に詰める：1 = 黒、上位ビットが左、1 行は ceil(幅 / 8) バイト', () => {
    // 幅 10・高さ 2。1 行目は x=0 と x=9 が黒、2 行目は全部白
    const w = 10
    const rgba = new Uint8ClampedArray(w * 2 * 4).fill(255)
    for (const x of [0, 9]) rgba.set([0, 0, 0, 255], x * 4)
    expect([...packBits(rgba, w, 2)]).toEqual([0x80, 0x40, 0x00, 0x00])
    // 透明は白
    expect([...packBits(new Uint8ClampedArray(8 * 4), 8, 1)]).toEqual([0x00])
    expect(toBase64(new Uint8Array([0x7f, 0xff]))).toBe('f/8=')
  })

  it('行は画像、紙送り・切断は要素のまま。<root> で包んで送る', () => {
    const log = { sizes: [] as Array<[number, number]>, drawn: [] as Drawn[] }
    const doc = new WebPrntRequest().align('center').line('領収書', { width: 2, height: 2, emphasis: true }).align('left').line('合計  ¥1').feed(1).cut()
    const req = rasterRequest(doc, 58, fakePainter(log))
    const l = layoutOf(58)
    expect(req).toMatch(/^<initialization\/><alignment position="left"\/><bitimage width="384" height="(\d+)">[A-Za-z0-9+/=]+<\/bitimage><feed line="1"\/><cutpaper feed="true" type="partial"\/>$/)
    expect(log.sizes).toEqual([[384, l.lineHeight * 3]])
    // 倍角の 3 文字は中央、1 文字 = 4 マス
    const big = log.drawn.filter((d) => d.font.startsWith('bold'))
    expect(big.map((d) => d.text).join('')).toBe('領収書')
    expect(big[0]?.maxWidth).toBe(l.cell * 4)
    const center = (big[0]!.x + big[2]!.x) / 2
    expect(Math.abs(center - (l.left + (l.cell * 32) / 2))).toBeLessThanOrEqual(l.cell)
    // 空白は描かず、桁の位置を保つ（「¥」は 5 桁目から）
    const small = log.drawn.filter((d) => !d.font.startsWith('bold'))
    expect(small.map((d) => d.text).join('')).toBe('合計¥1')
    expect(small[2]?.x).toBe(l.left + l.cell * 6 + l.cell / 2)
    expect(envelope(req)).toContain('<Request>&lt;root&gt;&lt;initialization/&gt;&lt;alignment position="left"/&gt;&lt;bitimage')
  })

  it('長いレシートは 1 枚の画像の高さを上限で分ける', () => {
    const log = { sizes: [] as Array<[number, number]>, drawn: [] as Drawn[] }
    const doc = new WebPrntRequest()
    for (let i = 0; i < 60; i++) doc.line(`行 ${i}`)
    const req = rasterRequest(doc, 80, fakePainter(log))
    const heights = [...req.matchAll(/<bitimage width="576" height="(\d+)">/g)].map((m) => Number(m[1]))
    expect(heights.length).toBeGreaterThan(1)
    expect(Math.max(...heights)).toBeLessThanOrEqual(MAX_IMAGE_HEIGHT)
    expect(heights.reduce((a, b) => a + b, 0)).toBe(layoutOf(80).lineHeight * 60)
  })

  it('会計のレシートも 1 本の画像（＋紙送り・切断）になる', () => {
    const log = { sizes: [] as Array<[number, number]>, drawn: [] as Drawn[] }
    const req = rasterRequest(buildReceipt({ sale: makeSale(), paperWidth: 58, kind: 'receipt' }), 58, fakePainter(log))
    expect(req).not.toContain('<text')
    expect(req.endsWith('<feed line="1"/><cutpaper feed="true" type="partial"/>')).toBe(true)
    expect(log.drawn.map((d) => d.text).join('')).toContain('領収書')
  })
})
