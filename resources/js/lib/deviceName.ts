import { stripControlCharacters } from '@/lib/text'

/** 端末名（08 §5.12）。この端末のブラウザに保存し、会計の device_name に使う。localStorage が使えなければ保存しない */
const KEY = 'regi:device_name'
export const DEVICE_NAME_MAX = 30

export function loadDeviceName(): string {
  try {
    // 制御文字を拒否する前に保存した端末名でも、会計・注文が 422 にならないようにする（F-009）
    return stripControlCharacters(localStorage.getItem(KEY) ?? '')
  } catch {
    return ''
  }
}

/** 保存できたら true。空文字は削除 */
export function saveDeviceName(name: string): boolean {
  const value = stripControlCharacters(name).trim().slice(0, DEVICE_NAME_MAX)
  try {
    if (value === '') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, value)
    return true
  } catch {
    return false
  }
}
