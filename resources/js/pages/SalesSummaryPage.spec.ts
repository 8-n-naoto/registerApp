import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { SummaryReport } from '@/api/reports'
import SalesSummaryPage from '@/pages/SalesSummaryPage.vue'
import { useAdminStore } from '@/stores/admin'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import type { Role } from '@/types/api'

const reports = vi.hoisted(() => ({
  fetchSummary: vi.fn(),
  exportUrl: vi.fn((type: string, from: string, to: string, storeId: number | null) =>
    `export?type=${type}&from=${from}&to=${to}${storeId === null ? '' : `&store_id=${storeId}`}`),
}))
vi.mock('@/api/reports', () => reports)
// グラフ（Chart.js）はテストでは描かない
vi.mock('@/components/BarChart.vue', () => ({ default: { props: ['labels', 'values', 'label'], template: '<div class="chart-stub" :data-count="values.length" />' } }))

function makeSummary(from: string, to: string): SummaryReport {
  return {
    from,
    to,
    totals: { total: 12000, count: 10, customers: 14, average: 1200, discount_total: 100, cancelled_count: 1 },
    by_date: [{ date: from, total: 12000, count: 10, customers: 14 }, { date: to, total: 0, count: 0, customers: 0 }],
    by_hour: [{ hour: 12, total: 12000, count: 10 }],
    by_tax: [{ tax_type_name: '標準', rate_permille: 100, total: 12000, tax_amount: 1090, taxable_amount: 10910 }],
    by_payment: [{ payment_method_name: '現金', is_cash: true, total: 12000, count: 10 }],
    ranking: [{ product_id: 1, product_name: 'コーヒー', quantity: 20, amount: 8000 }],
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
      { path: '/sales/summary', name: 'sales-summary', component: SalesSummaryPage },
      { path: '/admin/stores', name: 'admin-stores', component: stub },
      { path: '/', name: 'home', component: stub },
    ],
  })
  await router.push(path)
  const w = mount(SalesSummaryPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function button(text: string): HTMLButtonElement {
  const el = [...document.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

describe('SalesSummaryPage（S06）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    sessionStorage.clear()
    vi.clearAllMocks()
    reports.fetchSummary.mockImplementation((from: string, to: string) => Promise.resolve(makeSummary(from, to)))
  })

  it('既定は今月（営業日の月初〜当日）で、合計・日別（0 円の日を含む）・ランキングを出す', async () => {
    const { w } = await mountPage('/sales/summary')
    expect(reports.fetchSummary).toHaveBeenCalledWith('2026-09-01', '2026-09-29', null)
    expect(w.text()).toContain('2026/9/1（火） 〜 2026/9/29（火）')
    expect(w.text()).toContain('29 日間')
    expect(w.text()).toContain('¥12,000')
    expect(w.find('[data-test="by-date"] tbody').findAll('tr')).toHaveLength(2)
    expect(w.find('[data-test="ranking"]').text()).toContain('コーヒー')
    expect(button('今月').getAttribute('aria-pressed')).toBe('true')
    w.unmount()
  })

  it('［先月］で前月の 1 日〜末日を読み、URL に期間を残す', async () => {
    const { w, router } = await mountPage('/sales/summary')
    button('先月').click()
    await flushPromises()
    expect(reports.fetchSummary).toHaveBeenLastCalledWith('2026-08-01', '2026-08-31', null)
    expect(router.currentRoute.value.query).toMatchObject({ from: '2026-08-01', to: '2026-08-31' })
    w.unmount()
  })

  it('1 年を超える期間は送らずに理由を出す', async () => {
    const { w } = await mountPage('/sales/summary')
    button('期間を指定').click()
    await flushPromises()
    await w.find('input[type="date"]').setValue('2024-01-01')
    await w.find('.summary-custom').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('期間は 1 年以内で指定してください')
    expect(reports.fetchSummary).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('CSV 出力のリンクは種類と期間を付ける', async () => {
    const { w } = await mountPage('/sales/summary?from=2026-09-01&to=2026-09-10')
    await w.find('select').setValue('items')
    expect(w.find('[data-test="export"]').attributes('href')).toBe('export?type=items&from=2026-09-01&to=2026-09-10')
    w.unmount()
  })

  it('admin は ?store_id= の店舗を閲覧し、帯に店舗名を出す。store_id が無ければ店舗一覧へ案内する', async () => {
    const { w } = await mountPage('/sales/summary', 'admin')
    expect(reports.fetchSummary).not.toHaveBeenCalled()
    expect(w.text()).toContain('店舗一覧から店舗を選んでください')
    w.unmount()

    document.body.innerHTML = ''
    sessionStorage.setItem('regi:admin:viewing', JSON.stringify({ id: 3, name: '駅前店' }))
    const second = await mountPage('/sales/summary?store_id=3&from=2026-09-01&to=2026-09-10', 'admin')
    expect(useAdminStore().viewingStoreId).toBe(3)
    expect(reports.fetchSummary).toHaveBeenCalledWith('2026-09-01', '2026-09-10', 3)
    expect(second.w.text()).toContain('閲覧中：駅前店（閲覧のみ）')
    expect(second.w.find('[data-test="export"]').attributes('href')).toContain('store_id=3')
    second.w.unmount()
  })
})
