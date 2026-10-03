import { fmt, ja } from '@/i18n/ja'
import { permilleToPercent } from '@/lib/percent'
import { PRODUCT_COLORS } from '@/lib/productColors'
import type { AuditLogRow } from '@/types/api'

// S12 操作ログの「変更内容」を 1 行にする（08 §5.14、AC-S12-1「価格 400 → 450」）

/** 画面に出さない項目（サーバーは記録しない約束だが、万一入っていても表示しない。AC-S12-2） */
const HIDDEN_KEYS = new Set(['password', 'password_hash', 'current_password', 'new_password', 'remember_token', 'token'])

/** 値を表示名に置き換える項目（商品名などの自由入力は置き換えない） */
const ENUM_KEYS = new Set(['price_mode', 'rounding', 'status', 'selection'])

const t = ja.auditLog

function label(key: string): string {
  const labels: Record<string, string> = t.fields
  return labels[key] ?? key
}

function formatValue(key: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return t.empty
  if (typeof value === 'boolean') return value ? t.yes : t.no
  if (key === 'rate_permille' && typeof value === 'number') return `${permilleToPercent(value)}%`
  if (typeof value === 'string' && key === 'color') return PRODUCT_COLORS.find((c) => c.key === value)?.label ?? value
  if (typeof value === 'string' && ENUM_KEYS.has(key)) {
    const enums: Record<string, string> = t.values
    return enums[value] ?? value
  }
  if (typeof value === 'number' || typeof value === 'string') return String(value)
  if (Array.isArray(value)) {
    return value.every((v) => typeof v === 'string' || typeof v === 'number') ? value.join('、') || t.empty : fmt(t.count, { n: value.length })
  }
  return t.omitted
}

function visibleKeys(obj: Record<string, unknown> | null): string[] {
  return obj === null ? [] : Object.keys(obj).filter((k) => !HIDDEN_KEYS.has(k))
}

/** 例：「価格 400 → 450、販売中 はい → いいえ」。追加は「商品名 コーヒー、価格 400」、削除は変更前だけ */
export function describeChanges(row: Pick<AuditLogRow, 'before' | 'after'>): string {
  const { before, after } = row
  if (before !== null && after !== null) {
    const keys = [...new Set([...visibleKeys(after), ...visibleKeys(before)])]
    return keys.map((k) => `${label(k)} ${formatValue(k, before[k])} → ${formatValue(k, after[k])}`).join('、')
  }
  const only = after ?? before
  if (only === null) return ''
  return visibleKeys(only).map((k) => `${label(k)} ${formatValue(k, only[k])}`).join('、')
}

/** 対象の表示：「商品 #12」 */
export function describeTarget(row: Pick<AuditLogRow, 'target_type' | 'target_id'>): string {
  if (row.target_type === null) return t.empty
  const types: Record<string, string> = t.targets
  const name = types[row.target_type] ?? row.target_type
  return row.target_id === null ? name : `${name} #${row.target_id}`
}

/** 絞り込みの選択肢（06 §2.6 の順） */
export const AUDIT_ACTIONS = [
  'login_succeeded', 'login_failed', 'password_changed', 'store_initialized', 'store_settings_updated',
  'tax_type_created', 'tax_type_updated', 'payment_method_created', 'payment_method_updated',
  'category_created', 'category_updated', 'category_deleted',
  'product_created', 'product_updated', 'product_deleted', 'product_stock_changed', 'products_imported',
  'option_created', 'option_updated', 'option_deleted',
  'option_group_created', 'option_group_updated', 'option_group_deleted',
  'sale_cancelled', 'sale_offline_synced', 'sale_offline_reviewed', 'closing_saved', 'staff_created', 'staff_updated', 'staff_password_reset',
  'store_suspended', 'store_resumed', 'backup_downloaded',
  // 12 §5.19（注文）
  'order_created', 'order_accepted', 'order_cancelled',
  'order_table_created', 'order_table_updated', 'order_table_deleted', 'order_table_token_regenerated',
  'order_table_opened', 'order_table_closed', 'order_settings_updated',
  // 15 §4（レシートプリンター）
  'printer_settings_updated',
] as const

export type AuditActionCode = (typeof AUDIT_ACTIONS)[number]

export function isAuditAction(value: unknown): value is AuditActionCode {
  return typeof value === 'string' && (AUDIT_ACTIONS as readonly string[]).includes(value)
}
