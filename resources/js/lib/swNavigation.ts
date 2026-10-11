/**
 * Service Worker（workbox の runtimeCaching）で、画面の HTML をキャッシュしてよい要求かを決める（vite.config.ts）。
 *
 * この関数は workbox が文字列にして sw.js へ埋め込む。外側の変数・import は sw.js に持ち込めない
 * （QA-001：以前は vite.config.ts の basePath を参照し、sw.js で ReferenceError になっていた）。
 * サブフォルダ（本番 /regi/、ローカル /）は Service Worker の scope から求める。
 * /api/ と /sanctum/ は画面ではないためキャッシュしない（02 §9.3）。
 */
export function isCacheableNavigation({ request, url }: { request: Request; url: URL }): boolean {
  if (request.mode !== 'navigate') return false
  const scope = new URL((self as unknown as { registration: { scope: string } }).registration.scope).pathname
  return !url.pathname.startsWith(`${scope}api/`) && !url.pathname.startsWith(`${scope}sanctum/`)
}
