// 勤怠・勤務表の表示用の小さな計算（13 §6）。時刻はサーバーの表記（日本時間）のまま扱う

const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'] as const

/** 分 → 'H:MM'（例 545 → '9:05'）。null は '—' */
export function formatMinutes(minutes: number | null): string {
  if (minutes === null) return '—'
  const sign = minutes < 0 ? '-' : ''
  const abs = Math.abs(minutes)
  return `${sign}${Math.floor(abs / 60)}:${String(abs % 60).padStart(2, '0')}`
}

/** 'YYYY-MM' を delta か月ずらす */
export function shiftMonth(month: string, delta: number): string {
  const m = /^(\d{4})-(\d{2})$/.exec(month)
  if (!m) return month
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1 + delta, 1))
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
}

/** 'YYYY-MM' → '2026年10月' */
export function formatMonth(month: string): string {
  const m = /^(\d{4})-(\d{2})$/.exec(month)
  return m ? `${m[1]}年${Number(m[2])}月` : month
}

/** 'YYYY-MM' の全日（'YYYY-MM-DD'） */
export function monthDays(month: string): string[] {
  const m = /^(\d{4})-(\d{2})$/.exec(month)
  if (!m) return []
  const last = new Date(Date.UTC(Number(m[1]), Number(m[2]), 0)).getUTCDate()
  return Array.from({ length: last }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`)
}

/** 'YYYY-MM-DD' の曜日（0 = 日） */
export function weekdayOf(ymd: string): number {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd)
  return m ? new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))).getUTCDay() : 0
}

export function weekdayLabel(day: number): string {
  return WEEKDAYS[day] ?? ''
}

/** ISO 8601 → <input type="datetime-local"> の値 'YYYY-MM-DDTHH:MM'（サーバーの時刻の表記のまま） */
export function toLocalInput(iso: string | null): string {
  if (!iso) return ''
  const m = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/.exec(iso)
  return m?.[1] ?? ''
}

/** 勤務表の時刻 'HH:MM'（00:00〜29:59。日をまたぐ予定は 24 時を超えて書く） */
export const SHIFT_TIME_PATTERN = /^([01]\d|2\d):[0-5]\d$/

/** '9:00' のような入力を 'HH:MM' にそろえる。形が違えばそのまま返す（サーバーで 422） */
export function normalizeShiftTime(input: string): string {
  const s = input.trim().replace('：', ':')
  const m = /^(\d{1,2}):?(\d{2})$/.exec(s)
  return m ? `${m[1]?.padStart(2, '0')}:${m[2]}` : s
}

/** 'YYYY-MM-DD' → '10/5（月）' */
export function formatDay(ymd: string): string {
  const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(ymd)
  return m ? `${Number(m[1])}/${Number(m[2])}（${weekdayLabel(weekdayOf(ymd))}）` : ymd
}

/** 区分の時間帯 → '09:00〜12:00・13:00〜15:00' */
export function segmentsText(segments: readonly { start: string; end: string }[]): string {
  return segments.map((s) => `${s.start}〜${s.end}`).join('・')
}
