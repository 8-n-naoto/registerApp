import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import ReceiptPage from '@/pages/ReceiptPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { makeSale } from '@/test/register'
import type { Role } from '@/types/api'

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  createSale: vi.fn(),
  fetchSale: vi.fn(),
  cancelSale: vi.fn(),
}))
vi.mock('@/api/register', () => api)

async function mountPage(path: string, role: Role = 'owner') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/sales/:id(\\d+)/receipt', name: 'receipt', component: ReceiptPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
      { path: '/admin/stores', name: 'admin-stores', component: { template: '<p>admin</p>' } },
    ],
  })
  await router.push(path)
  const w = mount(ReceiptPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

describe('S03 簡易領収書（08 §5.4）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    for (const fn of Object.values(api)) fn.mockReset()
  })

  it('会計のスナップショットを表示する', async () => {
    api.fetchSale.mockResolvedValue(makeSale({
      discount_type: 'percent', discount_value: 10, discount_amount: 78, total: 702, tax_amount: 63, received: 1000, change_amount: 298,
      items: [{ id: 1, product_id: 3, product_name: 'ラテ', unit_price: 500, options_price: 50, quantity: 2, line_total: 1100, options: [{ product_option_id: 31, option_name: 'ショット', price: 50 }] }],
    }))
    const { w } = await mountPage('/sales/501/receipt')
    expect(api.fetchSale).toHaveBeenCalledWith(501, null)
    const text = w.text()
    for (const s of ['テスト店 A', '2026/9/29 13:05', '会計 ID 501', 'ラテ', 'ショット', '¥550 × 2', '¥1,100', '値引き（10%）', '-¥78', '¥702', 'うち消費税（10%）', '¥63', '現金', '¥1,000', '¥298']) {
      expect(text).toContain(s)
    }
    expect(w.find('.receipt__watermark').exists()).toBe(false)
  })

  it('取消済みは「取消」の透かし。現金以外は預かり・お釣りを出さない', async () => {
    api.fetchSale.mockResolvedValue(makeSale({ status: 'cancelled', is_cash: false, payment_method_name: 'カード', price_mode: 'tax_excluded' }))
    const { w } = await mountPage('/sales/501/receipt')
    expect(w.find('.receipt__watermark').text()).toBe('取消')
    expect(w.text()).not.toContain('お預かり')
    expect(w.text()).toContain('消費税（10%）')
    expect(w.text()).not.toContain('うち消費税')
  })

  it('admin は ?store_id= を付けて取得する', async () => {
    api.fetchSale.mockResolvedValue(makeSale())
    await mountPage('/sales/501/receipt?store_id=3', 'admin')
    expect(api.fetchSale).toHaveBeenCalledWith(501, 3)
  })

  it('403 はスタッフの当日制限、404 は見つからない旨を出す', async () => {
    api.fetchSale.mockRejectedValueOnce(apiError(403, { message: 'x', code: 'FORBIDDEN' }))
    const first = await mountPage('/sales/501/receipt', 'staff')
    expect(first.w.text()).toContain('スタッフは当日の会計のみ表示できます')
    first.w.unmount()

    api.fetchSale.mockRejectedValueOnce(apiError(404, { message: 'x', code: 'NOT_FOUND' }))
    const second = await mountPage('/sales/9/receipt')
    expect(second.w.text()).toContain('会計が見つかりません')
  })

  it('［印刷］は window.print、［閉じる］は直接開いたなら役割のホームへ', async () => {
    api.fetchSale.mockResolvedValue(makeSale())
    const print = vi.spyOn(window, 'print').mockImplementation(() => {})
    const { w, router } = await mountPage('/sales/501/receipt')
    const [close, printBtn] = w.findAll('.receipt-page__bar button')
    await printBtn?.trigger('click')
    expect(print).toHaveBeenCalledOnce()
    await close?.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('home')
  })
})
