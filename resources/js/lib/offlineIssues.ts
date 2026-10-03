import { fmt, ja } from '@/i18n/ja'
import { formatDateTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import type { OfflineSyncIssues } from '@/types/api'

/** 14 §6 オフライン会計の送信時に記録した問題を、画面に出す文にする（あるものだけ、決まった順で） */
export function describeIssues(issues: OfflineSyncIssues | null): string[] {
  if (!issues) return []
  const t = ja.offlineIssues
  const result: string[] = []
  if (issues.price_changed && issues.price_changed.length > 0) {
    const items = issues.price_changed
      .map((p) => fmt(t.priceItem, { name: p.name, recorded: formatYen(p.recorded), current: formatYen(p.current) }))
      .join('、')
    result.push(fmt(t.priceChanged, { items }))
  }
  if (issues.settings_changed) result.push(t.settingsChanged)
  if (issues.stock_short && issues.stock_short.length > 0) {
    const items = issues.stock_short.map((s) => fmt(t.stockItem, { name: s.product_name, n: s.short })).join('、')
    result.push(fmt(t.stockShort, { items }))
  }
  if (issues.time_adjusted) result.push(fmt(t.timeAdjusted, { time: formatDateTime(issues.time_adjusted.recorded) }))
  if (issues.operator_unknown) result.push(t.operatorUnknown)
  if (issues.order_conflict) result.push(fmt(t.orderConflict, { n: issues.order_conflict.order_ids.length }))
  return result
}
