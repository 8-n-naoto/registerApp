/**
 * サーバーの NoControlCharacters（12 §7）が拒否する制御文字（U+0000〜U+001F・U+007F）を取り除く。
 * allowNewline のときは改行（\n・\r）を残す。オフライン会計のように 422 になると端末に残ってしまう送信の前に使う
 */
export function stripControlCharacters(value: string, allowNewline = false): string {
  let out = ''
  for (const c of value) {
    const code = c.charCodeAt(0)
    const isControl = code < 0x20 || code === 0x7f
    if (!isControl || (allowNewline && (c === '\n' || c === '\r'))) out += c
  }
  return out
}
