// 15 §7.4 レシートの組み立て（純粋関数）。S03 の簡易領収書（SaleReceipt.vue）と同じ項目・同じ順。
// 金額は会計の応答（スナップショット）の値をそのまま書く（計算しない。D-004）
import { fmt, ja } from '@/i18n/ja'
import { formatDateTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { permilleToPercent } from '@/lib/percent'
import { WebPrntRequest } from '@/lib/receipt/webprnt'
import type { Sale } from '@/types/api'

export type ReceiptKind = 'receipt' | 'reprint' | 'test'

/** 1 行の桁数（半角 = 1・全角 = 2）。80mm 紙は 48、58mm 紙は 32 */
export function columnsOf(paperWidth: 80 | 58): number {
  return paperWidth === 58 ? 32 : 48
}

/** 文字の幅。半角カナ以外の U+0100 以上は全角として 2 桁 */
export function charWidth(ch: string): 1 | 2 {
  const code = ch.codePointAt(0) ?? 0
  if (code < 0x100) return 1
  if (code >= 0xff61 && code <= 0xff9f) return 1
  return 2
}

export function textWidth(text: string): number {
  let w = 0
  for (const ch of text) w += charWidth(ch)
  return w
}

/** cols 桁ごとに折り返す（全角の途中では切らない） */
export function wrap(text: string, cols: number): string[] {
  const lines: string[] = []
  let cur = ''
  let w = 0
  for (const ch of text) {
    const cw = charWidth(ch)
    if (w + cw > cols) {
      lines.push(cur)
      cur = ''
      w = 0
    }
    cur += ch
    w += cw
  }
  if (cur !== '' || lines.length === 0) lines.push(cur)
  return lines
}

/**
 * 左の文字と右の文字を 1 行に置く。入りきらなければ左を折り返し、右は最後の行の右端に置く
 * （最後の行にも入らなければ、右だけの行を足す）
 */
export function leftRight(left: string, right: string, cols: number): string[] {
  const rw = textWidth(right)
  const lines = wrap(left, cols)
  const last = lines[lines.length - 1] ?? ''
  const gap = cols - textWidth(last) - rw
  if (gap >= 1) {
    lines[lines.length - 1] = last + ' '.repeat(gap) + right
  } else {
    lines.push(' '.repeat(Math.max(0, cols - rw)) + right)
  }
  return lines
}

/** 端末の時刻（Z 付きの UTC）は端末の時刻に直してから表示する。サーバーの値（+09:00 付き）はそのまま */
function localIso(iso: string): string {
  if (!iso.endsWith('Z')) return iso
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  const p = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

export interface ReceiptInput {
  sale: Sale
  paperWidth: 80 | 58
  kind: Exclude<ReceiptKind, 'test'>
}

/** WebPRNT の要求（SendMessage の <Request> の中身）を返す */
export function buildReceipt({ sale, paperWidth, kind }: ReceiptInput): string {
  const t = ja.receipt
  const p = ja.print.receipt
  const cols = columnsOf(paperWidth)
  const rule = '-'.repeat(cols)
  const r = new WebPrntRequest()
  const lines = (texts: string[]): void => {
    for (const text of texts) r.line(text)
  }

  // 1・2 店舗名と表題（倍角は 1 行の桁数が半分になる）
  r.align('center')
  for (const text of wrap(sale.store_name, Math.floor(cols / 2))) r.line(text, { width: 2, height: 2, emphasis: true })
  r.line(kind === 'reprint' ? p.reprintTitle : t.title, { emphasis: true })
  if (sale.status === 'cancelled') r.line(t.cancelled, { width: 2, height: 2, emphasis: true })

  // 3 日時・会計番号（端末に保存した会計は番号が無い）
  r.align('left')
  const saleNo = sale.id > 0 ? fmt(t.saleId, { id: sale.id }) : p.offlineSale
  lines(leftRight(formatDateTime(localIso(sale.sold_at)), saleNo, cols))
  r.line(rule)

  // 5 明細
  for (const item of sale.items) {
    const name = item.product_memo ? `${item.product_name}（${item.product_memo}）` : item.product_name
    lines(leftRight(name, formatYen(item.line_total), cols))
    // 2 行目：左にオプション、右に単価 × 数量（S03 と同じ並び）
    const options = item.options.length > 0 ? `  ${item.options.map((o) => o.option_name).join('・')}` : ''
    lines(leftRight(options, `${formatYen(item.unit_price + item.options_price)} × ${item.quantity}`, cols))
  }
  r.line(rule)

  // 7 合計など
  lines(leftRight(t.subtotal, formatYen(sale.subtotal), cols))
  if (sale.discount_amount > 0) {
    const label = sale.discount_type === 'percent' ? `${t.discount}（${sale.discount_value}%）` : t.discount
    lines(leftRight(label, formatYen(-sale.discount_amount), cols))
  }
  // 倍角の行は桁数が半分
  for (const text of leftRight(t.total, formatYen(sale.total), Math.floor(cols / 2))) r.line(text, { width: 2, height: 2, emphasis: true })
  const taxLabel = fmt(sale.price_mode === 'tax_included' ? t.taxLine : t.taxLineExcluded, { rate: permilleToPercent(sale.tax_rate_permille) })
  lines(leftRight(taxLabel, formatYen(sale.tax_amount), cols))
  lines(leftRight(t.paymentMethod, sale.payment_method_name, cols))
  if (sale.is_cash) {
    lines(leftRight(t.received, formatYen(sale.received), cols))
    lines(leftRight(t.change, formatYen(sale.change_amount), cols))
  }

  // 8 切断
  r.feed(1)
  r.cut()
  return r.toString()
}

/** テスト印刷（店舗設定の［この端末でテスト印刷］）。店舗名・日時・「テスト印刷」・桁の目安 */
export function buildTestPage(storeName: string, paperWidth: 80 | 58, now: Date): string {
  const p = ja.print.receipt
  const cols = columnsOf(paperWidth)
  const iso = localIso(now.toISOString())
  const r = new WebPrntRequest()
  r.align('center')
  r.line(storeName, { width: 2, height: 2, emphasis: true })
  r.line(p.testTitle, { emphasis: true })
  r.line(formatDateTime(iso))
  r.align('left')
  r.line('-'.repeat(cols))
  r.line(fmt(p.testColumns, { cols }))
  r.line('1234567890'.repeat(Math.ceil(cols / 10)).slice(0, cols))
  r.line(p.testKanji)
  r.feed(1)
  r.cut()
  return r.toString()
}
