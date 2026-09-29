/** 税率は千分率の整数で持つ（CLAUDE.md 絶対ルール 4）。画面の % 表記との変換を浮動小数なしで行う */

/** 100 → '10'、85 → '8.5' */
export function permilleToPercent(permille: number): string {
  const whole = Math.trunc(permille / 10)
  const tenth = permille % 10
  return tenth === 0 ? String(whole) : `${whole}.${tenth}`
}

/** '10' → 100、'8.5' → 85、全角数字も可。0〜100（小数 1 桁まで）でなければ null */
export function percentToPermille(text: string): number | null {
  const s = text.trim().replace(/[０-９．]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 0xfee0))
  const m = /^(\d{1,3})(?:\.(\d))?$/.exec(s)
  if (!m) return null
  const value = Number(m[1]) * 10 + Number(m[2] ?? '0')
  return value <= 1000 ? value : null
}
