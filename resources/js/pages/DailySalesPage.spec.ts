import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { DailyReport } from '@/api/reports'
import DailySalesPage from '@/pages/DailySalesPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { makeSale } from '@/test/register'
import type { Role } from '@/types/api'

const register = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  createSale: vi.fn(),
  fetchSale: vi.fn(),
  cancelSale: vi.fn(),
}))
vi.mock('@/api/register', () => register)

const reports = vi.hoisted(() => ({
  fetchDailyReport: vi.fn(),
  fetchClosing: vi.fn(),
  saveClosing: vi.fn(),
}))
vi.mock('@/api/reports', () => reports)

function makeReport(extra: Partial<DailyReport> = {}): DailyReport {
  return {
    date: '2026-09-29',
    totals: { total: 1780, count: 2, customers: 3, average: 890, discount_total: 0, cancelled_count: 1 },
    by_tax: [{ tax_type_name: '標準', rate_permille: 100, total: 1780, tax_amount: 161, taxable_amount: 1619 }],
    by_payment: [
      { payment_method_name: 'カード', is_cash: false, total: 1000, count: 1 },
      { payment_method_name: '現金', is_cash: true, total: 780, count: 1 },
    ],
    by_product: [{ product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: null, quantity: 4, amount: 1600 }],
    by_category: [
      { category_id: 10, category_name: 'ドリンク', quantity: 4, amount: 1600 },
      { category_id: null, category_name: null, quantity: 1, amount: 180 },
    ],
    sales: [
      { id: 502, sold_at: '2026-09-29T14:10:00+09:00', total: 1000, payment_method_name: 'カード', tax_type_name: '標準', user_name: '山田', status: 'completed', item_count: 1 },
      { id: 501, sold_at: '2026-09-29T13:05:12+09:00', total: 780, payment_method_name: '現金', tax_type_name: '標準', user_name: '山田', status: 'completed', item_count: 2 },
      { id: 500, sold_at: '2026-09-29T12:00:00+09:00', total: 400, payment_method_name: '現金', tax_type_name: '標準', user_name: '山田', status: 'cancelled', item_count: 1 },
    ],
    closing: null,
    comparison: null,
    ...extra,
  }
}

async function mountPage(path: string, role: Role = 'owner') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  const stub = { template: '<p>stub</p>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/sales/daily', name: 'sales-daily', component: DailySalesPage },
      { path: '/closing', name: 'closing', component: stub },
      { path: '/sales/:id(\\d+)/receipt', name: 'receipt', component: stub },
      { path: '/', name: 'home', component: stub },
    ],
  })
  await router.push(path)
  const w = mount(DailySalesPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const el = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

const saleRows = (): HTMLButtonElement[] => [...document.querySelectorAll<HTMLButtonElement>('.sale-row')]

function row(i: number): HTMLButtonElement {
  const el = saleRows()[i]
  if (!el) throw new Error(`row ${i} not found`)
  return el
}

async function click(el: HTMLElement): Promise<void> {
  el.click()
  await flushPromises()
}

describe('S04 日次売上（08 §5.5）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    for (const fn of [...Object.values(register), ...Object.values(reports)]) fn.mockReset()
    reports.fetchDailyReport.mockImplementation(() => Promise.resolve(makeReport()))
  })

  it('開くときの API は日次売上の 1 回だけ（AC-S04-5）。集計・一覧・内訳を出す', async () => {
    const { w } = await mountPage('/sales/daily')
    expect(reports.fetchDailyReport).toHaveBeenCalledTimes(1)
    expect(reports.fetchDailyReport).toHaveBeenCalledWith(null, null)
    expect(register.fetchSale).not.toHaveBeenCalled()
    const text = w.text()
    for (const s of ['9/29（火）', '¥1,780', '2 件', '3 名', '¥890', '取消 1 件', '14:10', 'カード', 'コーヒー', '10%']) {
      expect(text).toContain(s)
    }
    expect(w.find('[data-test="closing-state"]').text()).toBe('未')
  })

  it('カテゴリ別に数量と金額を出し、カテゴリの無い明細は「未分類」にまとめる', async () => {
    const { w } = await mountPage('/sales/daily')
    const rows = w.find('[data-test="by-category"] tbody').findAll('tr')
    expect(rows.map((r) => r.findAll('td').map((d) => d.text()))).toEqual([
      ['ドリンク', '4', '¥1,600'],
      ['未分類', '1', '¥180'],
    ])
  })

  it('商品別の行にメモと商品コードを出し、同じ名前の商品を見分けられる', async () => {
    reports.fetchDailyReport.mockResolvedValue(makeReport({
      by_product: [
        { product_id: 1, product_name: 'コーヒー', product_code: 'P0001', product_memo: 'ホット', quantity: 4, amount: 1600 },
        { product_id: 11, product_name: 'コーヒー', product_code: 'P0011', product_memo: 'アイス', quantity: 2, amount: 900 },
      ],
    }))
    const { w } = await mountPage('/sales/daily')
    const subs = w.findAll('.product-cell__sub').map((e) => e.text())
    expect(subs).toEqual(['ホット・P0001', 'アイス・P0011'])
  })

  it('取消済みの会計は灰色の行に「取消」を付ける（AC-S04-2）', async () => {
    await mountPage('/sales/daily')
    expect(saleRows()).toHaveLength(3)
    expect(row(2).classList.contains('sale-row--cancelled')).toBe(true)
    expect(row(2).textContent).toContain('取消')
    expect(row(0).classList.contains('sale-row--cancelled')).toBe(false)
  })

  it('owner は日付を移動できる。?date= で取得する', async () => {
    const { router } = await mountPage('/sales/daily?date=2026-09-28')
    expect(reports.fetchDailyReport).toHaveBeenLastCalledWith('2026-09-28', null)
    reports.fetchDailyReport.mockImplementation((date: string) => Promise.resolve(makeReport({ date })))
    await flushPromises()
    await click(button('◀ 前日'))
    expect(router.currentRoute.value.query.date).toBe('2026-09-28')
    await click(button('翌日 ▶'))
    expect(router.currentRoute.value.query.date).toBe('2026-09-30')
    expect(reports.fetchDailyReport).toHaveBeenLastCalledWith('2026-09-30', null)
  })

  it('staff は日付を変えられず、?date= も無視する（AC-S04-3）', async () => {
    const { w } = await mountPage('/sales/daily?date=2026-09-28', 'staff')
    expect(reports.fetchDailyReport).toHaveBeenCalledWith(null, null)
    expect(w.text()).not.toContain('◀ 前日')
    expect(w.find('input[type="date"]').exists()).toBe(false)
  })

  it('admin は ?store_id= で閲覧し、取消ボタンとレジ締めへのリンクを出さない（AC-S04-4）', async () => {
    register.fetchSale.mockResolvedValue(makeSale())
    const { w } = await mountPage('/sales/daily?store_id=3', 'admin')
    expect(reports.fetchDailyReport).toHaveBeenCalledWith(null, 3)
    expect(w.text()).not.toContain('レジ締めへ')
    await click(row(1))
    expect(register.fetchSale).toHaveBeenCalledWith(501, 3)
    expect(document.body.textContent).toContain('会計の詳細')
    expect(document.body.textContent).not.toContain('この会計を取り消す')
  })

  it('取消すると応答で表示を差し替え、再取得しない（AC-S05-1）', async () => {
    register.fetchSale.mockResolvedValue(makeSale())
    register.cancelSale.mockResolvedValue(makeSale({ status: 'cancelled', cancelled_at: '2026-09-29T15:00:00+09:00', cancelled_by_name: '山田' }))
    await mountPage('/sales/daily')
    await click(row(1))
    expect(document.body.textContent).toContain('担当者')
    await click(button('この会計を取り消す'))
    expect(document.body.textContent).toContain('¥780 の会計を取り消します。在庫は元に戻ります。よろしいですか？')
    await click(button('取り消す'))
    expect(register.cancelSale).toHaveBeenCalledWith(501)
    expect(reports.fetchDailyReport).toHaveBeenCalledTimes(1)
    expect(document.body.textContent).toContain('会計を取り消しました')
    expect(row(1).classList.contains('sale-row--cancelled')).toBe(true)
    expect(document.body.textContent).toContain('取消 2 件')
    expect(document.body.textContent).toContain('1 件')
  })

  it('既に取り消されていれば案内を出し、日次売上と会計を取り直す（AC-S05-3）', async () => {
    register.fetchSale.mockResolvedValue(makeSale())
    register.cancelSale.mockRejectedValue(apiError(409, { message: 'x', code: 'ALREADY_CANCELLED' }))
    await mountPage('/sales/daily')
    await click(row(1))
    register.fetchSale.mockResolvedValue(makeSale({ status: 'cancelled' }))
    await click(button('この会計を取り消す'))
    await click(button('取り消す'))
    expect(document.body.textContent).toContain('既に取り消されています')
    expect(reports.fetchDailyReport).toHaveBeenCalledTimes(2)
    expect(register.fetchSale).toHaveBeenCalledTimes(2)
    expect(document.body.textContent).not.toContain('この会計を取り消す')
  })

  it('staff は現在の営業日以外の会計を取り消せない（AC-S05-2）', async () => {
    register.fetchSale.mockResolvedValue(makeSale({ business_date: '2026-09-28' }))
    await mountPage('/sales/daily', 'staff')
    await click(row(1))
    expect(document.body.textContent).toContain('会計の詳細')
    expect(document.body.textContent).not.toContain('この会計を取り消す')
  })

  it('締め済み・締め後に変更ありを表示する', async () => {
    const closing = {
      business_date: '2026-09-29', float_amount: 10000, cash_sales: 780, expected_cash: 10780, counted_cash: 10780,
      difference: 0, memo: null, changed_after_close: true, user_name: '山田', updated_at: '2026-09-29T20:00:00+09:00',
    }
    reports.fetchDailyReport.mockResolvedValue(makeReport({ closing }))
    const { w } = await mountPage('/sales/daily')
    expect(w.find('[data-test="closing-state"]').text()).toBe('締め後に変更あり')
  })

  it('読み込みに失敗したら再読み込みできる', async () => {
    reports.fetchDailyReport.mockRejectedValueOnce(apiError(500, { message: 'サーバーエラー' }))
    const { w } = await mountPage('/sales/daily')
    expect(w.text()).toContain('サーバーエラー')
    await click(button('再読み込み'))
    expect(w.text()).toContain('¥1,780')
  })
})
