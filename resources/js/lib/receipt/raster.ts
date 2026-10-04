// 15 §3.5 レシートを画像（WebPRNT の <bitimage>）にして送る。
// 端末の canvas に描いて白黒の画像にする（プリンターの文字コード・内蔵フォントに左右されない）。
// 桁の揃え（buildReceipt の空白詰め）を崩さないよう、1 桁 = 1 マスの升目に 1 文字ずつ置く（全角は 2 マス）
import { charWidth, columnsOf } from '@/lib/receipt/buildReceipt'
import { bitImageElement, CUT_ELEMENT, feedElement, type ReceiptOp, type WebPrntRequest } from '@/lib/receipt/webprnt'

/** 印字できる幅（ドット、203dpi）。58mm 紙 = 48mm、80mm 紙 = 72mm */
export const PRINT_DOTS: Record<80 | 58, number> = { 58: 384, 80: 576 }

/** 左右の余白（ドット）。端の文字が欠けないように空ける */
export const SIDE_MARGIN = 8

/** 1 つの <bitimage> の高さの上限は 2400 ドット。余裕を見て分ける */
export const MAX_IMAGE_HEIGHT = 1200

/** 白黒の判定のしきい値（輝度） */
const THRESHOLD = 160

export interface Layout {
  /** 画像の幅（ドット） */
  width: number
  /** 1 桁の幅（ドット） */
  cell: number
  /** 1 行の高さ（ドット、倍率 1） */
  lineHeight: number
  left: number
  cols: number
}

export function layoutOf(paperWidth: 80 | 58): Layout {
  const width = PRINT_DOTS[paperWidth]
  const cols = columnsOf(paperWidth)
  const cell = Math.floor((width - SIDE_MARGIN * 2) / cols)
  return { width, cell, lineHeight: cell * 2 + 6, cols, left: Math.floor((width - cell * cols) / 2) }
}

/** canvas の 2D の操作のうち、ここで使うもの（試験では記録用の偽物を渡す） */
export interface Painter {
  fillStyle: string | CanvasGradient | CanvasPattern
  font: string
  textAlign: CanvasTextAlign
  textBaseline: CanvasTextBaseline
  fillRect(x: number, y: number, w: number, h: number): void
  fillText(text: string, x: number, y: number, maxWidth?: number): void
  getImageData(sx: number, sy: number, sw: number, sh: number): { data: Uint8ClampedArray }
}

export type CreatePainter = (width: number, height: number) => Painter

const createCanvasPainter: CreatePainter = (width, height) => {
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) throw new Error('canvas 2d is not available')
  return ctx
}

/** RGBA の画素を 1 ビット（1 = 黒、上位ビットが左）に詰める */
export function packBits(rgba: Uint8ClampedArray, width: number, height: number): Uint8Array {
  const bytesPerRow = Math.ceil(width / 8)
  const out = new Uint8Array(bytesPerRow * height)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = (y * width + x) * 4
      const a = (rgba[i + 3] ?? 0) / 255
      // 透明は白として扱う
      const lum = 255 - a * (255 - ((rgba[i] ?? 0) * 0.299 + (rgba[i + 1] ?? 0) * 0.587 + (rgba[i + 2] ?? 0) * 0.114))
      if (lum < THRESHOLD) {
        const at = y * bytesPerRow + (x >> 3)
        out[at] = (out[at] ?? 0) | (0x80 >> (x & 7))
      }
    }
  }
  return out
}

export function toBase64(bytes: Uint8Array): string {
  let bin = ''
  const step = 0x8000
  for (let i = 0; i < bytes.length; i += step) bin += String.fromCharCode(...bytes.subarray(i, i + step))
  return btoa(bin)
}

type Block = { kind: 'image'; ops: Array<Extract<ReceiptOp, { kind: 'line' }> & { position: 'left' | 'center' | 'right' }> } | { kind: 'xml'; xml: string }

/** 行を画像のまとまりに、紙送り・切断を XML の要素に分ける */
function blocksOf(ops: readonly ReceiptOp[]): Block[] {
  const blocks: Block[] = []
  let position: 'left' | 'center' | 'right' = 'left'
  for (const op of ops) {
    if (op.kind === 'align') {
      position = op.position
    } else if (op.kind === 'line') {
      const last = blocks[blocks.length - 1]
      if (last?.kind === 'image') last.ops.push({ ...op, position })
      else blocks.push({ kind: 'image', ops: [{ ...op, position }] })
    } else {
      blocks.push({ kind: 'xml', xml: op.kind === 'feed' ? feedElement(op.lines) : CUT_ELEMENT })
    }
  }
  return blocks
}

function lineHeightOf(layout: Layout, op: { style: { height?: 1 | 2 } }): number {
  return layout.lineHeight * (op.style.height ?? 1)
}

/** 行を描く（1 文字ずつ升目に置く） */
function drawLines(p: Painter, layout: Layout, ops: Extract<Block, { kind: 'image' }>['ops']): void {
  p.fillStyle = '#fff'
  p.fillRect(0, 0, layout.width, ops.reduce((h, op) => h + lineHeightOf(layout, op), 0))
  p.fillStyle = '#000'
  p.textAlign = 'center'
  p.textBaseline = 'middle'
  let y = 0
  for (const op of ops) {
    const sx = op.style.width ?? 1
    const h = lineHeightOf(layout, op)
    const cell = layout.cell * sx
    p.font = `${op.style.emphasis === true ? 'bold ' : ''}${layout.cell * 2 * (op.style.height ?? 1)}px sans-serif`
    let units = 0
    for (const ch of op.text) units += charWidth(ch)
    const span = layout.cols * layout.cell
    const textW = units * cell
    let x = layout.left
    if (op.position === 'center') x += Math.floor((span - textW) / 2)
    else if (op.position === 'right') x += span - textW
    for (const ch of op.text) {
      const w = charWidth(ch) * cell
      if (ch !== ' ') p.fillText(ch, x + w / 2, y + h / 2, w)
      x += w
    }
    y += h
  }
}

/** レシートを画像の要求（SendMessage の <root> の中身）にする */
export function rasterRequest(doc: WebPrntRequest, paperWidth: 80 | 58, createPainter: CreatePainter = createCanvasPainter): string {
  const layout = layoutOf(paperWidth)
  // 画像の中で揃えるので、印字位置は左に固定する（前の印刷の揃えを持ち越さない）
  const parts: string[] = ['<initialization/>', '<alignment position="left"/>']
  for (const block of blocksOf(doc.ops)) {
    if (block.kind === 'xml') {
      parts.push(block.xml)
      continue
    }
    const height = block.ops.reduce((h, op) => h + lineHeightOf(layout, op), 0)
    const p = createPainter(layout.width, height)
    drawLines(p, layout, block.ops)
    for (let top = 0; top < height; top += MAX_IMAGE_HEIGHT) {
      const h = Math.min(MAX_IMAGE_HEIGHT, height - top)
      const bits = packBits(p.getImageData(0, top, layout.width, h).data, layout.width, h)
      parts.push(bitImageElement(layout.width, h, toBase64(bits)))
    }
  }
  return parts.join('')
}
