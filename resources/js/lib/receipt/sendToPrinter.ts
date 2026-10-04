// 15 §3・§7.3 端末からプリンター（WebPRNT）へ送り、結果を判定する。自動では送り直さない（2 枚出るのを防ぐ）
import { envelope } from '@/lib/receipt/webprnt'

export type PrintFailure =
  | 'paper_empty' | 'cover_open' | 'offline' | 'busy' | 'unreachable' | 'denied' | 'timeout' | 'unknown'

export type PrintResult = { ok: true } | { ok: false; reason: PrintFailure }

export const SEND_TIMEOUT_MS = 10_000

/**
 * 宛先の URL（ホストは店舗設定の値。スキーム・パスはここでだけ組み立てる）。
 * https のページ（本番）からは https のプリンターへ送る（iPad の Safari は https → http を必ず止める）。
 * http のページ（手元の開発環境）からは、プリンターの HTTPS を有効にしていなくても届くよう http で送る
 */
export function printerUrl(host: string, pageProtocol: string = globalThis.location?.protocol ?? 'https:'): string {
  const scheme = pageProtocol === 'http:' ? 'http' : 'https'
  return `${scheme}://${host}/StarWebPRNT/SendMessage`
}

/**
 * 応答（XML）の判定。<Response> の中に文字列で入った <root> の success・code・status（Automatic Status の 16 進）を読む
 * （2026-10-05 mC-Print2 の実機：`<Response>&lt;root&gt;&lt;success&gt;true&lt;/success&gt;&lt;code&gt;0&lt;/code&gt;&lt;status&gt;298A02…&lt;/status&gt;&lt;/root&gt;</Response>`）。
 * status の位置は SDK の StarWebPrintTrader と同じ（3 バイト目：0x20 カバー開・0x08 オフライン、6 バイト目：0x08 紙切れ）
 */
export function parseResponse(xml: string): PrintResult {
  const parse = (text: string): Document | null => {
    const doc = new DOMParser().parseFromString(text, 'text/xml')
    return doc.getElementsByTagName('parsererror').length > 0 ? null : doc
  }
  const outer = parse(xml)
  const inner = outer?.getElementsByTagName('Response')[0]?.textContent
  const doc = inner ? parse(inner) : null
  if (!doc) return { ok: false, reason: 'unknown' }
  const pick = (name: string): string => doc.getElementsByTagName(name)[0]?.textContent?.trim() ?? ''
  const success = pick('success') === 'true'
  const code = pick('code')
  const status = pick('status').replace(/\s/g, '')
  const byte = (index: number): number => {
    const v = Number.parseInt(status.substring(index * 2, index * 2 + 2), 16)
    return Number.isNaN(v) ? 0 : v
  }
  if (status.length >= 6 && (byte(2) & 0x20) !== 0) return { ok: false, reason: 'cover_open' }
  if (status.length >= 12 && (byte(5) & 0x08) !== 0) return { ok: false, reason: 'paper_empty' }
  if (success) return { ok: true }
  if (code === '2001') return { ok: false, reason: 'busy' }
  if (code === '1100' || (status.length >= 6 && (byte(2) & 0x08) !== 0)) return { ok: false, reason: 'offline' }
  return { ok: false, reason: 'unknown' }
}

/** Chrome のローカルネットワークの許可が拒否されているか（対応していないブラウザは false） */
async function localNetworkDenied(): Promise<boolean> {
  if (typeof navigator === 'undefined' || !('permissions' in navigator)) return false
  for (const name of ['local-network-access', 'local-network']) {
    try {
      // 許可の名前は標準の型に無いため文字列で問い合わせる（未対応の名前は例外になる）
      const state = await navigator.permissions.query({ name } as unknown as PermissionDescriptor)
      if (state.state === 'denied') return true
    } catch {
      // 未対応の名前
    }
  }
  return false
}

/** request は buildReceipt / buildTestPage の戻り値 */
export async function sendToPrinter(host: string, request: string, fetchImpl: typeof fetch = fetch): Promise<PrintResult> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), SEND_TIMEOUT_MS)
  try {
    const res = await fetchImpl(printerUrl(host), {
      method: 'POST',
      headers: { 'Content-Type': 'text/xml; charset=UTF-8' },
      body: envelope(request),
      signal: controller.signal,
      credentials: 'omit',
      cache: 'no-store',
    })
    if (!res.ok) return { ok: false, reason: 'unknown' }
    return parseResponse(await res.text())
  } catch (err) {
    if (controller.signal.aborted) return { ok: false, reason: 'timeout' }
    // 証明書を信頼していない・別のネットワーク・電源断・CORS はブラウザから区別できない（§7.3）
    void err
    return { ok: false, reason: (await localNetworkDenied()) ? 'denied' : 'unreachable' }
  } finally {
    clearTimeout(timer)
  }
}
