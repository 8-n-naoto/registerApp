import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError, type AxiosResponse } from 'axios'
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
  createAdminStore: vi.fn(),
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

function input(id: string, value: string): void {
  const el = document.querySelector<HTMLInputElement>(`#${id}`)
  if (!el) throw new Error(`input ${id} not found`)
  el.value = value
  el.dispatchEvent(new Event('input'))
}

function fillStoreForm(password = 'test-owner-pass', confirmation = password): void {
  input('store-new-name', ' 新しい店 ')
  input('store-new-owner-name', '店長')
  input('store-new-owner-login', 'new-owner')
  input('store-new-password', password)
  input('store-new-password-confirm', confirmation)
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

  it('店舗を追加すると、送信後にフォームを閉じて一覧を読み直し、通知を出す', async () => {
    api.createAdminStore.mockResolvedValue({ id: 5, name: '新しい店', owner_login_id: 'new-owner' })
    const { w } = await mountPage()
    expect(api.fetchAdminStores).toHaveBeenCalledTimes(1)

    button('＋店舗を追加').click()
    await flushPromises()
    fillStoreForm()
    button('追加する').click()
    await flushPromises()

    expect(api.createAdminStore).toHaveBeenCalledWith({
      name: '新しい店',
      owner_name: '店長',
      owner_login_id: 'new-owner',
      password: 'test-owner-pass',
      password_confirmation: 'test-owner-pass',
    })
    expect(api.fetchAdminStores).toHaveBeenCalledTimes(2)
    expect(document.querySelector('#store-new-name')).toBeNull()
    expect(w.text()).toContain('「新しい店」を追加しました（オーナー：new-owner）')
    w.unmount()
  })

  it('店舗の追加：画面で分かる誤りは送らずに欄の下へ出す', async () => {
    const { w } = await mountPage()
    button('＋店舗を追加').click()
    await flushPromises()

    button('追加する').click()
    await flushPromises()
    expect(api.createAdminStore).not.toHaveBeenCalled()
    expect(w.text()).toContain('店舗名を入力してください')
    expect(w.text()).toContain('ログイン ID は半角英数字')
    expect(w.text()).toContain('パスワードは 8 文字以上')

    fillStoreForm('test-owner-pass', 'different-pass')
    button('追加する').click()
    await flushPromises()
    expect(api.createAdminStore).not.toHaveBeenCalled()
    expect(w.text()).toContain('初期パスワードが一致しません')
    w.unmount()
  })

  it('店舗の追加：サーバーの 422 は欄の下に出し、フォームと入力を残す', async () => {
    api.createAdminStore.mockRejectedValue(
      new AxiosError('invalid', undefined, undefined, undefined, {
        status: 422,
        data: { message: 'invalid', errors: { owner_login_id: ['このログイン ID はすでに使われています'] } },
      } as AxiosResponse),
    )
    const { w } = await mountPage()
    button('＋店舗を追加').click()
    await flushPromises()
    fillStoreForm()
    button('追加する').click()
    await flushPromises()

    expect(w.text()).toContain('このログイン ID はすでに使われています')
    expect(document.querySelector<HTMLInputElement>('#store-new-owner-login')?.value).toBe('new-owner')
    expect(api.fetchAdminStores).toHaveBeenCalledTimes(1)
    w.unmount()
  })
})
