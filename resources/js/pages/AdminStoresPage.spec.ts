import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { AdminStoreRow } from '@/api/admin'
import AdminStoresPage from '@/pages/AdminStoresPage.vue'
import { useAdminStore } from '@/stores/admin'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'

const api = vi.hoisted(() => ({
  fetchAdminStores: vi.fn(),
  setStoreActive: vi.fn(),
  backupUrl: 'backup-url',
}))
vi.mock('@/api/admin', () => api)

function makeStore(extra: Partial<AdminStoreRow> = {}): AdminStoreRow {
  return {
    id: 3,
    name: '駅前店',
    is_active: true,
    owner_login_ids: ['owner-a'],
    staff_count: 2,
    product_count: 15,
    today: { business_date: '2026-09-29', total: 12800, count: 9, last_sold_at: '2026-09-29T17:45:00+09:00' },
    ...extra,
  }
}

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('admin')
  const stub = { template: '<p>stub</p>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/stores', name: 'admin-stores', component: AdminStoresPage },
      { path: '/sales/daily', name: 'sales-daily', component: stub },
      { path: '/sales/summary', name: 'sales-summary', component: stub },
      { path: '/logs', name: 'logs', component: stub },
      { path: '/account', name: 'account', component: stub },
      { path: '/login', name: 'login', component: stub },
    ],
  })
  await router.push('/admin/stores')
  const w = mount(AdminStoresPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const el = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

function card(i: number): HTMLElement {
  const el = document.querySelectorAll<HTMLElement>('[data-test="store"]')[i]
  if (!el) throw new Error(`card ${i} not found`)
  return el
}

describe('AdminStoresPage（A01）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    sessionStorage.clear()
    vi.clearAllMocks()
    api.fetchAdminStores.mockResolvedValue([
      makeStore(),
      makeStore({ id: 4, name: '本店', is_active: false, owner_login_ids: [], today: { business_date: '2026-09-29', total: 0, count: 0, last_sold_at: null } }),
    ])
  })

  it('店舗ごとに状態・本日の売上・オーナー、上部にバックアップのリンクを出す', async () => {
    const { w } = await mountPage()
    expect(w.findAll('[data-test="store"]')).toHaveLength(2)
    expect(card(0).textContent).toContain('利用中')
    expect(card(0).textContent).toContain('売上 ¥12,800・9 件')
    expect(card(0).textContent).toContain('最終会計 17:45')
    expect(card(0).textContent).toContain('オーナー：owner-a')
    expect(card(1).textContent).toContain('停止中')
    expect(card(1).textContent).toContain('会計なし')
    expect(card(1).textContent).toContain('オーナー：なし')
    expect(w.find('[data-test="backup"]').attributes('href')).toBe('backup-url')
    w.unmount()
  })

  it('［期間集計］で閲覧中の店舗を覚えて S06 へ移る', async () => {
    const { w, router } = await mountPage()
    button('期間集計', card(0)).click()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('sales-summary')
    expect(router.currentRoute.value.query.store_id).toBe('3')
    expect(useAdminStore().viewingLabel(3)).toBe('閲覧中：駅前店（閲覧のみ）')
    w.unmount()
  })

  it('［利用停止］は確認してから送る', async () => {
    api.setStoreActive.mockResolvedValueOnce({ id: 3, is_active: false })
    const { w } = await mountPage()
    button('利用停止', card(0)).click()
    await flushPromises()
    expect(api.setStoreActive).not.toHaveBeenCalled()
    const dialog = document.querySelector<HTMLElement>('[role="dialog"], [role="alertdialog"]')
    if (!dialog) throw new Error('dialog not found')
    expect(dialog.textContent).toContain('「駅前店」の利用を停止しますか')
    button('利用停止', dialog).click()
    await flushPromises()
    expect(api.setStoreActive).toHaveBeenCalledWith(3, false)
    expect(card(0).textContent).toContain('停止中')
    expect(w.text()).toContain('「駅前店」を停止しました')
    w.unmount()
  })
})
