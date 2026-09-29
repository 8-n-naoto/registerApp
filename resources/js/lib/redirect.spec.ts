import { describe, expect, it } from 'vitest'
import { safeRedirect } from './redirect'

describe('safeRedirect', () => {
  it('アプリ内のパスはそのまま返す', () => {
    expect(safeRedirect('/sales/daily?date=2026-09-29')).toBe('/sales/daily?date=2026-09-29')
    expect(safeRedirect('/account#device')).toBe('/account#device')
  })

  it('外部 URL・プロトコル相対・バックスラッシュは捨てる', () => {
    expect(safeRedirect('https://evil.example/')).toBeNull()
    expect(safeRedirect('//evil.example/')).toBeNull()
    expect(safeRedirect('/\\evil.example')).toBeNull()
    expect(safeRedirect('javascript:alert(1)')).toBeNull()
  })

  it('文字列以外・空・ログイン画面は捨てる', () => {
    expect(safeRedirect(undefined)).toBeNull()
    expect(safeRedirect(['/a'])).toBeNull()
    expect(safeRedirect('')).toBeNull()
    expect(safeRedirect('/login?redirect=/')).toBeNull()
  })
})
