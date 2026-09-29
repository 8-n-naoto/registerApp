/** 数字の入力欄の値を整数にする。全角数字・3 桁区切りのカンマ・¥ を受け付ける。整数でなければ null */

function normalize(text: string): string {
  return text
    .trim()
    .replace(/[０-９]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0))
    .replace(/[，,¥￥\s]/g, '')
    .replace(/^[－−ー]/, '-')
}

/** 0 以上の整数（価格・在庫数） */
export function parseNonNegativeInt(text: string): number | null {
  const s = normalize(text)
  return /^\d{1,9}$/.test(s) ? Number(s) : null
}

/** 符号つきの整数（オプションの金額・在庫の増減） */
export function parseSignedInt(text: string): number | null {
  const s = normalize(text)
  return /^-?\d{1,9}$/.test(s) ? Number(s) : null
}
