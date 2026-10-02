// 管理画面の行き先（デザインシステム「レジアプリ」の 3 段：レール＝グループ → ページタブ → 画面内の切替）
// レール・ページタブ・ホームのカード・スマホの管理メニューはすべてこの並びを使う
import type { RouteLocationRaw } from 'vue-router'
import type { IconName } from '@/lib/icons'
import type { Role } from '@/types/api'

export interface NavPage {
  /** ルート名 */
  name: string
  label: string
  roles: Role[]
  /** ルート名と違う行き先（例：CSV 一括登録は商品の画面をダイアログ付きで開く） */
  to?: RouteLocationRaw
}

export interface NavGroup {
  key: string
  label: string
  icon: IconName
  pages: NavPage[]
  /** レールの下端に置く（アカウント） */
  bottom?: boolean
}

const ALL: Role[] = ['owner', 'staff', 'admin']
const STORE: Role[] = ['owner', 'staff']
const OWNER: Role[] = ['owner']

export const NAV_GROUPS: NavGroup[] = [
  {
    key: 'sales',
    label: '売上',
    icon: 'chart',
    pages: [
      { name: 'sales-daily', label: '日次売上', roles: ALL },
      { name: 'sales-summary', label: '期間集計', roles: ['owner', 'admin'] },
      { name: 'closing', label: 'レジ締め', roles: STORE },
    ],
  },
  { key: 'products', label: '商品', icon: 'box', pages: [{ name: 'products', label: '商品・カテゴリ', roles: OWNER }] },
  {
    key: 'staff',
    label: 'スタッフ',
    icon: 'users',
    pages: [
      { name: 'attendance', label: '勤怠', roles: STORE },
      { name: 'shifts', label: '勤務表', roles: STORE },
      { name: 'settings-staff', label: 'スタッフ', roles: OWNER },
    ],
  },
  {
    key: 'store',
    label: '店舗設定',
    icon: 'gear',
    pages: [
      { name: 'settings-store', label: '店舗設定', roles: OWNER },
      { name: 'settings-tables', label: 'テーブル・QR', roles: OWNER },
    ],
  },
  { key: 'logs', label: '記録', icon: 'log', pages: [{ name: 'logs', label: '操作ログ', roles: ['owner', 'admin'] }] },
  { key: 'account', label: 'アカウント', icon: 'user', bottom: true, pages: [{ name: 'account', label: 'アカウント', roles: ALL }] },
]

/** 役割で見えるページだけを残し、ページが 1 つもないグループは除く */
export function navFor(role: Role | null): NavGroup[] {
  if (!role) return []
  return NAV_GROUPS.map((g) => ({ ...g, pages: g.pages.filter((p) => p.roles.includes(role)) })).filter((g) => g.pages.length > 0)
}

/** ルート名から、そのページが属するグループを探す（管理の画面でなければ null） */
export function groupOf(routeName: unknown): NavGroup | null {
  if (typeof routeName !== 'string') return null
  return NAV_GROUPS.find((g) => g.pages.some((p) => p.name === routeName)) ?? null
}

export function pageTo(page: NavPage): RouteLocationRaw {
  return page.to ?? { name: page.name }
}

/** グループの最初のページ（レール・ホームのカードの行き先） */
export function groupTo(group: NavGroup): RouteLocationRaw {
  const first = group.pages[0]
  return first ? pageTo(first) : { name: 'home' }
}
