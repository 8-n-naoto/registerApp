/**
 * サーバーが埋めた <meta name="app-auth">（app.blade.php）。'0' なら、HTML を返した時点でログインしていない。
 * 未ログインで開いたとき GET /me（401）を呼ばずにログイン画面を出すために使う（QA-004）。
 * Service Worker のキャッシュから返った HTML では古いことがあるため、端末に残した利用者がある場合は使わない（auth ストア）
 */
export function serverSaysGuest(): boolean {
  return document.querySelector<HTMLMetaElement>('meta[name="app-auth"]')?.content === '0'
}
