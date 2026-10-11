import { describe, expect, it } from 'vitest'
import { isCacheableNavigation } from './swNavigation'

type Matcher = typeof isCacheableNavigation

/**
 * workbox と同じく関数を文字列にしてから、外側の変数が無い場所で評価する（sw.js に埋め込まれた状態を再現する）。
 * 外側の変数を参照していると、ここで ReferenceError になる（QA-001）
 */
function serialized(scope: string): Matcher {
  const fakeSelf = { registration: { scope } }
  return new Function('self', `return (${isCacheableNavigation.toString()})`)(fakeSelf) as Matcher
}

const nav = (mode: RequestMode): Request => ({ mode }) as Request

describe('isCacheableNavigation（sw.js の画面キャッシュの判定）', () => {
  it('文字列にして埋め込んでも外側の変数を参照しない', () => {
    expect(() => serialized('https://example.test/regi/')({ request: nav('navigate'), url: new URL('https://example.test/regi/login') })).not.toThrow()
  })

  it('サブフォルダ /regi/：画面はキャッシュし、/api/ と /sanctum/ はキャッシュしない', () => {
    const match = serialized('https://example.test/regi/')
    expect(match({ request: nav('navigate'), url: new URL('https://example.test/regi/') })).toBe(true)
    expect(match({ request: nav('navigate'), url: new URL('https://example.test/regi/register') })).toBe(true)
    expect(match({ request: nav('navigate'), url: new URL('https://example.test/regi/api/me') })).toBe(false)
    expect(match({ request: nav('navigate'), url: new URL('https://example.test/regi/sanctum/csrf-cookie') })).toBe(false)
  })

  it('ドメイン直下（ローカル）でも同じ', () => {
    const match = serialized('http://localhost:8000/')
    expect(match({ request: nav('navigate'), url: new URL('http://localhost:8000/login') })).toBe(true)
    expect(match({ request: nav('navigate'), url: new URL('http://localhost:8000/api/reports/export') })).toBe(false)
    expect(match({ request: nav('navigate'), url: new URL('http://localhost:8000/sanctum/csrf-cookie') })).toBe(false)
  })

  it('画面の遷移以外（fetch・画像など）は対象にしない', () => {
    const match = serialized('https://example.test/regi/')
    expect(match({ request: nav('cors'), url: new URL('https://example.test/regi/login') })).toBe(false)
    expect(match({ request: nav('same-origin'), url: new URL('https://example.test/regi/') })).toBe(false)
  })
})
