// 15 §3.1 WebPRNT の要求（XML）の組み立て。Star の SDK（StarWebPrintBuilder）は取り込まず、使う要素だけを同じ形で書く。
// 2026-10-05 実機（mC-Print2）で確認：要求は <root> で包まないと印字されない（応答は success になる）。
// プリンターは &amp; &lt; &gt; 以外の文字参照（&quot;・&#10; など）を戻さない。実際の印刷は lib/receipt/raster.ts で画像にして送る

/**
 * XML の文字のエスケープ。商品名・メモは利用者の入力なので、ここを通さずに組み立てない。
 * & < > の 3 つだけにする（Star の SDK と同じ）。実機は &quot; や &#10; を戻さず、属性を読まなかったり文字のまま印字したりする。
 * 属性の値は組み立て側の固定値だけで " を含まない
 */
export function escapeXml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/** 文字で送るときの漢字の文字コードの指定（§3.5）。mC-Print2 でエスケープを直したあと utf8・shift_jis とも漢字が出ることを確認 */
export const TEXT_CODEPAGE = 'utf8'

export interface TextStyle {
  /** 横・縦の倍率。合計・店舗名は 2 */
  width?: 1 | 2
  height?: 1 | 2
  emphasis?: boolean
}

export type Align = 'left' | 'center' | 'right'

/** レシートの 1 要素。文字の XML（toString）と画像（raster.ts）の両方をここから作る */
export type ReceiptOp =
  | { kind: 'align'; position: Align }
  | { kind: 'line'; text: string; style: TextStyle }
  | { kind: 'feed'; lines: number }
  | { kind: 'cut' }

/** 要素を積み上げる */
export class WebPrntRequest {
  private items: ReceiptOp[] = []

  get ops(): readonly ReceiptOp[] {
    return this.items
  }

  align(position: Align): this {
    this.items.push({ kind: 'align', position })
    return this
  }

  /** 1 行（末尾に改行を付ける） */
  line(text: string, style: TextStyle = {}): this {
    this.items.push({ kind: 'line', text, style })
    return this
  }

  feed(lines: number): this {
    this.items.push({ kind: 'feed', lines })
    return this
  }

  /** 紙送りしてから一部を残して切る */
  cut(): this {
    this.items.push({ kind: 'cut' })
    return this
  }

  /** 文字の要素で表した要求（漢字の出るプリンター向け・試験用） */
  toString(): string {
    return '<initialization/>' + this.items.map((op) => {
      switch (op.kind) {
        case 'align':
          return `<alignment position="${op.position}"/>`
        case 'line': {
          const attrs = [
            `codepage="${TEXT_CODEPAGE}"`,
            'international="japan"',
            `width="${op.style.width ?? 1}"`,
            `height="${op.style.height ?? 1}"`,
            `emphasis="${op.style.emphasis === true ? 'true' : 'false'}"`,
          ]
          return `<text ${attrs.join(' ')}>${escapeXml(`${op.text}\n`)}</text>`
        }
        case 'feed':
          return feedElement(op.lines)
        case 'cut':
          return CUT_ELEMENT
      }
    }).join('')
  }
}

export function feedElement(lines: number): string {
  return `<feed line="${lines}"/>`
}

export const CUT_ELEMENT = '<cutpaper feed="true" type="partial"/>'

/** ラスター画像の要素（データは 1 行 = ceil(幅 / 8) バイト、上位ビットが左、1 = 黒、を Base64 にしたもの） */
export function bitImageElement(width: number, height: number, base64: string): string {
  return `<bitimage width="${width}" height="${height}">${base64}</bitimage>`
}

/** SendMessage へ送る本文。要求は <root> で包み、文字列として <Request> に入れる（SDK の StarWebPrintTrader と同じ包み方） */
export function envelope(request: string): string {
  return '<StarWebPrint xmlns="http://www.star-m.jp" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
    + `<Request>${escapeXml(`<root>${request}</root>`)}</Request>`
    + '</StarWebPrint>'
}
