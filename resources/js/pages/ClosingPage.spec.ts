import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ClosingView } from '@/api/reports'
import ClosingPage from '@/pages/ClosingPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { Closing, Role } from '@/types/api'

const reports = vi.hoisted(() => ({
  fetchDailyReport: vi.fn(),
  fetchClosing: vi.fn(),
  saveClosing: vi.fn(),
}))
vi.mock('@/api/reports', () => reports)

function makeClosing(extra: Partial<Closing> = {}): Closing {
  return {
    business_date: '2026-09-29', float_amount: 30000, cash_sales: 52300, expected_cash: 82300, counted_cash: 82200,
    difference: -100, memo: null, changed_after_close: false, user_name: '山田', updated_at: '2026-09-29T22:10:00+09:00',
    ...extra,
  }
}

function makeView(extra: Partial<ClosingView> = {}): ClosingView {
  return { business_date: '2026-09-29', cash_sales: 52300, closing: null, ...extra }
}

async function mountPage(path: string, role: Role = 'owner') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  const stub = { template: '<p>stub</p>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/closing', name: 'closing', component: ClosingPage },
      { path: '/sales/daily', name: 'sales-daily', component: stub },
      { path: '/', name: 'home', component: stub },
    ],
  })
  await router.push(path)
  const w = mount(ClosingPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

async function input(w: Awaited<ReturnType<typeof mountPage>>['w'], selector: string, value: string): Promise<void> {
  await w.find(selector).setValue(value)
  await flushPromises()
}

describe('S07 レジ締め（08 §5.8）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    for (const fn of Object.values(reports)) fn.mockReset()
    reports.fetchClosing.mockResolvedValue(makeView())
  })

  it('準備金 30000・現金売上 52300・実際 82200 → あるべき 82300・不足 100 を赤で出す（AC-S07-1）', async () => {
    const { w } = await mountPage('/closing')
    expect(reports.fetchClosing).toHaveBeenCalledWith('2026-09-29')
    expect(w.find('[data-test="cash-sales"]').text()).toBe('¥52,300')
    expect(w.find('[data-test="last-saved"]').text()).toBe('まだ保存していません')
    await input(w, '#closing-float', '30000')
    expect(w.find('[data-test="expected"]').text()).toBe('¥82,300')
    await input(w, '#closing-counted', '82200')
    expect(w.find('[data-test="difference"]').text()).toBe('-¥100（不足）')
    expect(w.find('.closing-diff').classes()).toContain('closing-diff--short')
    await input(w, '#closing-counted', '82400')
    expect(w.find('[data-test="difference"]').text()).toBe('+¥100（過剰）')
    expect(w.find('.closing-diff').classes()).toContain('closing-diff--over')
    await input(w, '#closing-counted', '82300')
    expect(w.find('[data-test="difference"]').text()).toBe('¥0（過不足なし）')
  })

  it('金種の枚数を入れると合計を実際の現金に入れる（AC-S07-3）', async () => {
    const { w } = await mountPage('/closing')
    await input(w, '[data-test="denom-10000"]', '8')
    await input(w, '[data-test="denom-1000"]', '2')
    await input(w, '[data-test="denom-100"]', '1')
    expect(w.find('[data-test="denom-total"]').text()).toContain('¥82,100')
    expect((w.find('#closing-counted').element as HTMLInputElement).value).toBe('82100')
  })

  it('保存すると API を呼び、最終保存の日時と担当者を出す（AC-S07-2）', async () => {
    reports.saveClosing.mockResolvedValue(makeClosing({ memo: '両替' }))
    const { w } = await mountPage('/closing')
    await input(w, '#closing-float', '30000')
    await input(w, '#closing-counted', '82200')
    await input(w, '#closing-memo', ' 両替 ')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(reports.saveClosing).toHaveBeenCalledWith('2026-09-29', { float_amount: 30000, counted_cash: 82200, memo: '両替' })
    expect(w.text()).toContain('保存しました')
    expect(w.find('[data-test="last-saved"]').text()).toBe('最終保存：9/29 22:10 山田')
  })

  it('保存済みの値を入れて開き、締め後に変更があれば警告する', async () => {
    reports.fetchClosing.mockResolvedValue(makeView({ cash_sales: 53000, closing: makeClosing({ changed_after_close: true, memo: 'メモ' }) }))
    const { w } = await mountPage('/closing')
    expect((w.find('#closing-float').element as HTMLInputElement).value).toBe('30000')
    expect((w.find('#closing-counted').element as HTMLInputElement).value).toBe('82200')
    expect((w.find('#closing-memo').element as HTMLTextAreaElement).value).toBe('メモ')
    expect(w.find('[data-test="changed-after-close"]').exists()).toBe(true)
    expect(w.text()).toContain('保存時の現金売上 ¥52,300')
    expect(w.find('[data-test="difference"]').text()).toBe('-¥800（不足）')
  })

  it('保存し直すと「締め後に変更あり」が消える（AC-S07-2）', async () => {
    reports.fetchClosing.mockResolvedValue(makeView({ cash_sales: 53000, closing: makeClosing({ changed_after_close: true }) }))
    reports.saveClosing.mockResolvedValue(makeClosing({ cash_sales: 53000, expected_cash: 83000, counted_cash: 83000, difference: 0 }))
    const { w } = await mountPage('/closing')
    await input(w, '#closing-counted', '83000')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('[data-test="changed-after-close"]').exists()).toBe(false)
    expect(w.text()).not.toContain('保存時の現金売上')
  })

  it('未入力・範囲外は送らずに項目の下に出す', async () => {
    const { w } = await mountPage('/closing')
    await input(w, '#closing-float', '100000000')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(reports.saveClosing).not.toHaveBeenCalled()
    expect(w.findAll('.adm-error').map((e) => e.text())).toEqual([
      '0〜99,999,999 の整数を入力してください',
      '0〜99,999,999 の整数を入力してください',
    ])
  })

  it('422 は項目ごとのエラーと日付のエラーを出す', async () => {
    reports.saveClosing.mockRejectedValue(apiError(422, {
      message: 'x',
      errors: { memo: ['メモは 200 文字以内で入力してください'], date: ['未来の営業日は締められません'] },
    }))
    const { w } = await mountPage('/closing?date=2026-09-30')
    expect(reports.fetchClosing).toHaveBeenCalledWith('2026-09-30')
    await input(w, '#closing-float', '0')
    await input(w, '#closing-counted', '0')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('メモは 200 文字以内で入力してください')
    expect(w.find('[role="alert"]').text()).toBe('未来の営業日は締められません')
  })

  it('staff は ?date= を無視して現在の営業日を締める', async () => {
    await mountPage('/closing?date=2026-09-20', 'staff')
    expect(reports.fetchClosing).toHaveBeenCalledWith('2026-09-29')
  })

  it('403 はサーバーのメッセージを出す', async () => {
    reports.saveClosing.mockRejectedValue(apiError(403, { message: 'スタッフは当日のレジ締めのみ操作できます', code: 'FORBIDDEN' }))
    const { w } = await mountPage('/closing')
    await input(w, '#closing-float', '0')
    await input(w, '#closing-counted', '0')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('スタッフは当日のレジ締めのみ操作できます')
  })
})
