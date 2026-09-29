import type { DailyReport } from '@/api/reports'
import type { Sale } from '@/types/api'

/**
 * 取り消した会計を日次売上から差し引く（08 AC-S05-1：取消 API の応答で差し替え、再取得しない）。
 * 集計の定義は 07 §7.2。行が 0 件になった税区分・支払方法・商品は外す。締め済みなら「締め後に変更あり」にする
 */
export function applyCancel(report: DailyReport, sale: Sale): DailyReport {
  const row = report.sales.find((s) => s.id === sale.id)
  if (row === undefined || row.status === 'cancelled') return report

  const total = report.totals.total - sale.total
  const count = report.totals.count - 1
  const totals = {
    total,
    count,
    customers: report.totals.customers - (sale.customer_count ?? 0),
    average: count === 0 ? 0 : Math.floor(total / count),
    discount_total: report.totals.discount_total - sale.discount_amount,
    cancelled_count: report.totals.cancelled_count + 1,
  }

  const byTax = report.by_tax
    .map((r) =>
      r.tax_type_name === sale.tax_type_name && r.rate_permille === sale.tax_rate_permille
        ? { ...r, total: r.total - sale.total, tax_amount: r.tax_amount - sale.tax_amount, taxable_amount: r.taxable_amount - (sale.total - sale.tax_amount) }
        : r,
    )
    .filter((r) => r.total !== 0 || r.tax_amount !== 0)

  const byPayment = report.by_payment
    .map((r) =>
      r.payment_method_name === sale.payment_method_name && r.is_cash === sale.is_cash
        ? { ...r, total: r.total - sale.total, count: r.count - 1 }
        : r,
    )
    .filter((r) => r.count > 0)
    .sort((a, b) => b.total - a.total || a.payment_method_name.localeCompare(b.payment_method_name))

  let byProduct = report.by_product.map((r) => ({ ...r }))
  for (const item of sale.items) {
    const target = byProduct.find((r) => r.product_id === item.product_id && r.product_name === item.product_name)
    if (target) {
      target.quantity -= item.quantity
      target.amount -= item.line_total
    }
  }
  byProduct = byProduct
    .filter((r) => r.quantity > 0)
    .sort((a, b) => b.amount - a.amount || a.product_id - b.product_id || a.product_name.localeCompare(b.product_name))

  return {
    ...report,
    totals,
    by_tax: byTax,
    by_payment: byPayment,
    by_product: byProduct,
    sales: report.sales.map((s) => (s.id === sale.id ? { ...s, status: 'cancelled' as const } : s)),
    closing: report.closing === null ? null : { ...report.closing, changed_after_close: true },
  }
}

export type ClosingState = 'none' | 'done' | 'changed'

/** レジ締めの状況（08 §5.5：未 / 済 / 締め後に変更あり） */
export function closingState(report: Pick<DailyReport, 'closing'>): ClosingState {
  if (report.closing === null) return 'none'
  return report.closing.changed_after_close ? 'changed' : 'done'
}
