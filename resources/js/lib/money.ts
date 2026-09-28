const formatter = new Intl.NumberFormat('ja-JP')

/** 整数の円を「¥1,234」にする（負数は「-¥1,234」） */
export function formatYen(amount: number): string {
  const sign = amount < 0 ? '-' : ''
  return `${sign}¥${formatter.format(Math.abs(amount))}`
}
