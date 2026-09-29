import { shiftDate } from '@/lib/date'

// S06 期間集計の期間（08 §5.7、06 §5.2：from ≤ to、366 日以内）

export type PeriodPreset = 'thisWeek' | 'thisMonth' | 'lastMonth'

export interface Period {
  from: string
  to: string
}

export const MAX_PERIOD_DAYS = 366

const YMD = /^(\d{4})-(\d{2})-(\d{2})$/

export function isYmd(value: unknown): value is string {
  if (typeof value !== 'string') return false
  const m = YMD.exec(value)
  if (!m) return false
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])))
  return d.toISOString().slice(0, 10) === value // 2026-02-30 のような日付を除く
}

function toUtc(ymd: string): number {
  const m = YMD.exec(ymd)
  return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : Number.NaN
}

/** 両端を含む日数 */
export function periodDays(p: Period): number {
  return Math.round((toUtc(p.to) - toUtc(p.from)) / 86_400_000) + 1
}

/** 期間の検証。問題が無ければ null、あれば表示する理由の種類 */
export function periodError(p: Period): 'invalid' | 'order' | 'tooLong' | null {
  if (!isYmd(p.from) || !isYmd(p.to)) return 'invalid'
  if (p.from > p.to) return 'order'
  if (periodDays(p) > MAX_PERIOD_DAYS) return 'tooLong'
  return null
}

/** today（営業日）を基準にした期間。週は月曜始まり */
export function presetPeriod(preset: PeriodPreset, today: string): Period {
  const m = YMD.exec(today)
  if (!m) return { from: today, to: today }
  const [y, mo] = [Number(m[1]), Number(m[2])]
  switch (preset) {
    case 'thisWeek': {
      const weekday = new Date(toUtc(today)).getUTCDay() // 0 = 日
      return { from: shiftDate(today, -((weekday + 6) % 7)), to: today }
    }
    case 'thisMonth':
      return { from: `${m[1]}-${m[2]}-01`, to: today }
    case 'lastMonth': {
      const first = new Date(Date.UTC(y, mo - 2, 1)).toISOString().slice(0, 10)
      const last = new Date(Date.UTC(y, mo - 1, 0)).toISOString().slice(0, 10)
      return { from: first, to: last }
    }
  }
}

/** 期間がどのプリセットと一致するか（ボタンの選択表示用） */
export function matchPreset(p: Period, today: string): PeriodPreset | null {
  const presets: PeriodPreset[] = ['thisWeek', 'thisMonth', 'lastMonth']
  return presets.find((k) => {
    const q = presetPeriod(k, today)
    return q.from === p.from && q.to === p.to
  }) ?? null
}

/** 端末の時計から東京の日付を求める（admin は営業日を持たないため） */
export function tokyoToday(now: Date = new Date()): string {
  return new Date(now.getTime() + 9 * 3_600_000).toISOString().slice(0, 10)
}
