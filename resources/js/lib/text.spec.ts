import { describe, expect, it } from 'vitest'
import { stripControlCharacters } from './text'

describe('stripControlCharacters', () => {
  it('制御文字を取り除き、ふつうの文字・全角スペースは残す', () => {
    expect(stripControlCharacters('a\tb\u0000c\u001fd\u007fe　f')).toBe('abcde　f')
  })

  it('改行は既定で取り除き、allowNewline のときだけ残す', () => {
    expect(stripControlCharacters('1行目\r\n2行目')).toBe('1行目2行目')
    expect(stripControlCharacters('1行目\r\n2行目\t', true)).toBe('1行目\r\n2行目')
  })

  it('絵文字（サロゲートペア）は壊さない', () => {
    expect(stripControlCharacters('🍜\tラーメン')).toBe('🍜ラーメン')
  })
})
