const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'] as const

/** 'YYYY-MM-DD' → '9/29（火）'。端末のタイムゾーンに影響されないよう UTC で曜日を求める */
export function formatBusinessDate(ymd: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd)
  if (!m) return ymd
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
  const weekday = WEEKDAYS[new Date(Date.UTC(y, mo - 1, d)).getUTCDay()]
  return `${mo}/${d}（${weekday}）`
}

/** ISO 8601（'2026-09-29T13:05:12+09:00'）→ '2026/9/29 13:05'。サーバーの時刻（東京）の表記をそのまま使う */
export function formatDateTime(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso)
  if (!m) return iso
  return `${m[1]}/${Number(m[2])}/${Number(m[3])} ${m[4]}:${m[5]}`
}

/** 'YYYY-MM-DD' を days 日ずらす（UTC で計算し、端末のタイムゾーンと夏時間に影響されない） */
export function shiftDate(ymd: string, days: number): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd)
  if (!m) return ymd
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + days))
  return d.toISOString().slice(0, 10)
}

/** ISO 8601 → 'HH:MM'（サーバーの時刻の表記のまま） */
export function formatTime(iso: string): string {
  const m = /T(\d{2}):(\d{2})/.exec(iso)
  return m ? `${m[1]}:${m[2]}` : iso
}

/** ISO 8601 → '9/29 22:10'（サーバーの時刻の表記のまま） */
export function formatMonthDayTime(iso: string): string {
  const m = /^\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso)
  return m ? `${Number(m[1])}/${Number(m[2])} ${m[3]}:${m[4]}` : iso
}

/** 時刻（ミリ秒）→ 東京の 'HH:MM:SS'（端末のタイムゾーンに影響されない。東京に夏時間は無い） */
export function formatTokyoClock(ms: number): string {
  return new Date(ms + 9 * 3600_000).toISOString().slice(11, 19)
}
