import { describe, expect, it } from 'vitest'
import { isPasswordLengthValid, PASSWORD_MAX_BYTES, PASSWORD_MIN } from './password'

describe('isPasswordLengthValid', () => {
  it('8 文字以上・72 バイト以内だけ通す', () => {
    expect(PASSWORD_MIN).toBe(8)
    expect(PASSWORD_MAX_BYTES).toBe(72)
    expect(isPasswordLengthValid('a'.repeat(7))).toBe(false)
    expect(isPasswordLengthValid('a'.repeat(8))).toBe(true)
    expect(isPasswordLengthValid('a'.repeat(72))).toBe(true)
    expect(isPasswordLengthValid('a'.repeat(73))).toBe(false)
  })

  it('全角は 1 文字 3 バイトで数える', () => {
    expect(isPasswordLengthValid('あ'.repeat(24))).toBe(true)
    expect(isPasswordLengthValid('あ'.repeat(25))).toBe(false)
    expect(isPasswordLengthValid('a'.repeat(70) + 'あ')).toBe(false)
  })
})
