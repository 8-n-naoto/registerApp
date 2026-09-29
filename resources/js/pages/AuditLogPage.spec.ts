import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import AuditLogPage from '@/pages/AuditLogPage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import type { AuditLogRow, Role } from '@/types/api'

const api = vi.hoisted(() => ({ fetchLogs: vi.fn() }))
vi.mock('@/api/logs', () => api)

function makeRow(id: number, extra: Partial<AuditLogRow> = {}): AuditLogRow {
  return {
    id,
    created_at: '2026-09-29T10:15:00+09:00',
    store_name: 'テスト店 A',
    user_name: '山田',
    action: 'product_updated',
    action_label: '商品の変更',
    target_type: 'product',
    target_id: 12,
    before: { price: 400 },
    after: { price: 450 },
    ip: null,
    ...extra,
  }
}

async function mountPage(path: string, role: Role = 'owner') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/logs', name: 'logs', component: AuditLogPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
    ],
  })
  await router.push(path)
  const w = mount(AuditLogPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

describe('AuditLogPage（S12）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    sessionStorage.clear()
    vi.clearAllMocks()
  })

  it('価格の変更を「商品の変更　価格 400 → 450」で出し、パスワードは出さない（AC-S12-1・2）', async () => {
    api.fetchLogs.mockResolvedValueOnce({
      data: [
        makeRow(2),
        makeRow(1, { action: 'staff_created', action_label: 'スタッフの追加', target_type: 'user', target_id: 7, before: null, after: { name: '佐藤', login_id: 'sato', password: 'should-not-show' } }),
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
    })
    const { w } = await mountPage('/logs')
    expect(api.fetchLogs).toHaveBeenCalledWith(1, null, null)
    const rows = w.findAll('[data-test="log-row"]')
    expect(rows[0]?.text()).toContain('商品の変更')
    expect(rows[0]?.text()).toContain('商品 #12')
    expect(rows[0]?.text()).toContain('価格 400 → 450')
    expect(w.text()).not.toContain('should-not-show')
    expect(w.text()).not.toContain('店舗：') // owner には店舗名を出さない
    expect(w.find('[data-test="more"]').exists()).toBe(false)
    w.unmount()
  })

  it('［もっと見る］で次のページを後ろに足す', async () => {
    api.fetchLogs
      .mockResolvedValueOnce({ data: [makeRow(3)], meta: { current_page: 1, last_page: 2, total: 2 } })
      .mockResolvedValueOnce({ data: [makeRow(2)], meta: { current_page: 2, last_page: 2, total: 2 } })
    const { w } = await mountPage('/logs')
    await w.find('[data-test="more"]').trigger('click')
    await flushPromises()
    expect(api.fetchLogs).toHaveBeenLastCalledWith(2, null, null)
    expect(w.findAll('[data-test="log-row"]')).toHaveLength(2)
    expect(w.find('[data-test="more"]').exists()).toBe(false)
    w.unmount()
  })

  it('操作の種類で絞り込むと 1 ページ目から読み直し、URL に残す', async () => {
    api.fetchLogs.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    const { w, router } = await mountPage('/logs')
    await w.find('[data-test="filter"]').setValue('sale_cancelled')
    await flushPromises()
    expect(api.fetchLogs).toHaveBeenLastCalledWith(1, 'sale_cancelled', null)
    expect(router.currentRoute.value.query.action).toBe('sale_cancelled')
    expect(w.find('[data-test="no-rows"]').exists()).toBe(true)
    w.unmount()
  })

  it('admin は store_id が無ければ全店舗で、店舗名を行に出す', async () => {
    api.fetchLogs.mockResolvedValue({ data: [makeRow(1)], meta: { current_page: 1, last_page: 1, total: 1 } })
    const { w } = await mountPage('/logs', 'admin')
    expect(api.fetchLogs).toHaveBeenCalledWith(1, null, null)
    expect(w.text()).toContain('全店舗（閲覧のみ）')
    expect(w.text()).toContain('店舗：テスト店 A')
    w.unmount()

    document.body.innerHTML = ''
    const second = await mountPage('/logs?store_id=4', 'admin')
    expect(api.fetchLogs).toHaveBeenLastCalledWith(1, null, 4)
    expect(second.w.text()).not.toContain('店舗：')
    second.w.unmount()
  })
})
