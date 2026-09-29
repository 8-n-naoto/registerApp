/**
 * ログイン後の遷移先（08 AC-S01-6）。アプリ内のパスだけを許し、外部 URL・プロトコル相対・ログイン画面は捨てる
 */
export function safeRedirect(value: unknown): string | null {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) {
    return null
  }
  const origin = 'http://app.invalid'
  let url: URL
  try {
    url = new URL(value, origin)
  } catch {
    return null
  }
  if (url.origin !== origin || url.pathname === '/login') {
    return null
  }
  return url.pathname + url.search + url.hash
}
