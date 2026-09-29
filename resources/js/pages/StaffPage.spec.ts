import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import StaffPage from '@/pages/StaffPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { User } from '@/types/api'

const api = vi.hoisted(() => ({
  fetchStaff: vi.fn(),
  createStaff: vi.fn(),
  updateStaff: vi.fn(),
  resetStaffPassword: vi.fn(),
}))
vi.mock('@/api/staff', () => api)

function makeStaff(extra: Partial<User> = {}): User {
  return { id: 7, login_id: 'sato', name: '佐藤', role: 'staff', store_id: 1, is_active: true, last_login_at: '2026-09-28T18:30:00+09:00', ...extra }
}

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('owner')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings/staff', name: 'settings-staff', component: StaffPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
    ],
  })
  await router.push('/settings/staff')
  const w = mount(StaffPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return w
}

function button(text: string): HTMLButtonElement {
  const el = [...document.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

describe('StaffPage（S10）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    vi.clearAllMocks()
    api.fetchStaff.mockResolvedValue([makeStaff(), makeStaff({ id: 8, login_id: 'suzuki', name: '鈴木', is_active: false, last_login_at: null })])
  })

  it('一覧に表示名・ログイン ID・状態・最終ログインを出す', async () => {
    const w = await mountPage()
    const rows = w.findAll('.staff-row')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('佐藤')
    expect(rows[0]?.text()).toContain('sato')
    expect(rows[0]?.text()).toContain('最終ログイン 2026/9/28 18:30')
    expect(rows[1]?.text()).toContain('停止中')
    expect(rows[1]?.text()).toContain('未ログイン')
    w.unmount()
  })

  it('追加：ログイン ID の重複は入力欄の下に理由を出す（AC-S10-3）', async () => {
    api.createStaff.mockRejectedValueOnce(apiError(422, { message: '入力内容を確認してください', errors: { login_id: ['このログイン ID は使用できません'] } }))
    const w = await mountPage()
    button('＋スタッフを追加').click()
    await flushPromises()
    await w.find('#staff-new-name').setValue('田中')
    await w.find('#staff-new-login').setValue('sato')
    await w.find('#staff-new-password').setValue('password-123')
    await w.find('.staff-form').trigger('submit')
    await flushPromises()
    expect(api.createStaff).toHaveBeenCalledWith({ name: '田中', login_id: 'sato', password: 'password-123' })
    expect(w.text()).toContain('このログイン ID は使用できません')

    api.createStaff.mockResolvedValueOnce(makeStaff({ id: 9, login_id: 'tanaka', name: '田中' }))
    await w.find('#staff-new-login').setValue('tanaka')
    await w.find('.staff-form').trigger('submit')
    await flushPromises()
    expect(w.findAll('.staff-row')).toHaveLength(3)
    expect(w.text()).toContain('「田中」を追加しました')
    w.unmount()
  })

  it('行をタップして停止にし、保存する', async () => {
    api.updateStaff.mockResolvedValueOnce(makeStaff({ is_active: false }))
    const w = await mountPage()
    await w.findAll('.staff-row')[0]?.trigger('click')
    const check = w.find<HTMLInputElement>('.staff-edit input[type="checkbox"]')
    expect(check.element.checked).toBe(true)
    await check.setValue(false)
    await w.find('.staff-edit form').trigger('submit')
    await flushPromises()
    expect(api.updateStaff).toHaveBeenCalledWith(7, { name: '佐藤', is_active: false })
    expect(w.findAll('.staff-row')[0]?.classes()).toContain('adm-row--off')
    w.unmount()
  })

  it('パスワードの再設定：短すぎるものは送らない', async () => {
    api.resetStaffPassword.mockResolvedValueOnce(undefined)
    const w = await mountPage()
    await w.findAll('.staff-row')[0]?.trigger('click')
    await w.find('#staff-password-7').setValue('short')
    await w.find('.staff-password').trigger('submit')
    await flushPromises()
    expect(api.resetStaffPassword).not.toHaveBeenCalled()
    expect(w.text()).toContain('パスワードは 8〜72 文字で入力してください')

    await w.find('#staff-password-7').setValue('new-password-1')
    await w.find('.staff-password').trigger('submit')
    await flushPromises()
    expect(api.resetStaffPassword).toHaveBeenCalledWith(7, 'new-password-1')
    expect(w.text()).toContain('パスワードを再設定しました')
    expect(w.find<HTMLInputElement>('#staff-password-7').element.value).toBe('')
    w.unmount()
  })
})
