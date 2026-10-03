// 15 §3.1 WebPRNT の要求（XML）の組み立て。Star の SDK（StarWebPrintBuilder）は取り込まず、使う要素だけを同じ形で書く。
// 要素の名前・属性は SDK の出力に合わせている（P-0 の実機確認で確定する。docs/10 に記録）

/** XML の文字のエスケープ。商品名・メモは利用者の入力なので、ここを通さずに組み立てない */
export function escapeXml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
    .replace(/\n/g, '&#10;')
}

/**
 * 漢字の文字コードの指定（§3.5）。日本向けモデルでは utf8 で漢字が出る見込み（未確認）。
 * P-0 で出なかった場合はここを shift_jis に替えるか、画像の印字に切り替える
 */
export const TEXT_CODEPAGE = 'utf8'

export interface TextStyle {
  /** 横・縦の倍率（1〜6）。合計・店舗名は 2 */
  width?: 1 | 2
  height?: 1 | 2
  emphasis?: boolean
}

export type Align = 'left' | 'center' | 'right'

/** 要素を積み上げて要求の文字列を作る */
export class WebPrntRequest {
  private parts: string[] = ['<initialization/>']

  align(position: Align): this {
    this.parts.push(`<alignment position="${position}"/>`)
    return this
  }

  /** 1 行（末尾に改行を付ける） */
  line(text: string, style: TextStyle = {}): this {
    const attrs = [
      `codepage="${TEXT_CODEPAGE}"`,
      'international="japan"',
      `width="${style.width ?? 1}"`,
      `height="${style.height ?? 1}"`,
      `emphasis="${style.emphasis === true ? 'true' : 'false'}"`,
    ]
    this.parts.push(`<text ${attrs.join(' ')}>${escapeXml(`${text}\n`)}</text>`)
    return this
  }

  feed(lines: number): this {
    this.parts.push(`<feed line="${lines}"/>`)
    return this
  }

  /** 紙送りしてから一部を残して切る（mC-Print3 の既定の切り方） */
  cut(): this {
    this.parts.push('<cutpaper feed="true" type="partial"/>')
    return this
  }

  toString(): string {
    return this.parts.join('')
  }
}

/** SendMessage へ送る本文。要求は文字列として <Request> に入れる（SDK の StarWebPrintTrader と同じ包み方） */
export function envelope(request: string): string {
  return '<StarWebPrint xmlns="http://www.star-m.jp" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
    + `<Request>${escapeXml(request)}</Request>`
    + '</StarWebPrint>'
}
