import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import ClockInPage from '@/pages/ClockInPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { Operator } from '@/types/api'

const clockIn = vi.fn()
const switchOperator = vi.fn()
const fetchOperators = vi.fn<() => Promise<Operator[]>>()
const logout = vi.fn()
vi.mock('@/api/auth', () => ({ fetchMe: vi.fn(), login: vi.fn(), logout: () => logout(), updatePassword: vi.fn() }))
vi.mock('@/api/attendance', () => ({
  clockIn: (...a: unknown[]) => clockIn(...a),
  switchOperator: (...a: unknown[]) => switchOperator(...a),
  fetchOperators: () => fetchOperators(),
}))

const Blank = { template: '<div />' }
let router: Router

async function mountAt(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = { ...makeMe('staff'), attendance: null }
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/clock-in', name: 'clock-in', component: ClockInPage },
      { path: '/', name: 'home', component: Blank },
      { path: '/login', name: 'login', component: Blank },
      { path: '/orders', name: 'orders', component: Blank },
    ],
  })
  await router.push(path)
  const w = mount(ClockInPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return w
}

describe('S22 出勤（13 §7）', () => {
  beforeEach(() => {
    clockIn.mockReset()
    switchOperator.mockReset()
    logout.mockReset()
    fetchOperators.mockResolvedValue([
      { id: 1, name: '山田', role: 'staff', on_break: false },
      { id: 2, name: '佐藤', role: 'staff', on_break: true },
      { id: 3, name: '店長', role: 'owner', on_break: false },
    ])
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('パスワードで出勤し、元の画面へ戻る', async () => {
    clockIn.mockResolvedValue(makeMe('staff'))
    const w = await mountAt('/clock-in?redirect=/orders')
    expect(w.text()).toContain('山田さんはまだ出勤していません')
    await w.find('#clock-in-password').setValue('password')
    await w.find('form.clock-card').trigger('submit')
    await flushPromises()
    expect(clockIn).toHaveBeenCalledWith('password')
    expect(useAuthStore().working).toBe(true)
    expect(router.currentRoute.value.name).toBe('orders')
  })

  it('パスワードが違えば 422 の文言を出して入力を消す', async () => {
    clockIn.mockRejectedValue(apiError(422, { message: 'x', errors: { password: ['パスワードが違います'] } }))
    const w = await mountAt('/clock-in')
    await w.find('#clock-in-password').setValue('wrong')
    await w.find('form.clock-card').trigger('submit')
    await flushPromises()
    expect(w.find('#clock-in-error').text()).toBe('パスワードが違います')
    expect((w.find('#clock-in-password').element as HTMLInputElement).value).toBe('')
  })

  it('勤務中の人の一覧（自分を除く）。staff はそのまま、owner はパスワードで切り替える', async () => {
    switchOperator.mockResolvedValue(makeMe('staff'))
    const w = await mountAt('/clock-in')
    const btns = w.findAll('.op-pick__btn')
    expect(btns.map((b) => b.text().replace(/\s+/g, ''))).toEqual(['佐藤スタッフ・休憩中', '店長オーナー'])

    await btns[1]?.trigger('click')
    await flushPromises()
    expect(switchOperator).not.toHaveBeenCalled()
    await w.find('#operator-password').setValue('owner-pass')
    await w.find('form.op-pick__form').trigger('submit')
    await flushPromises()
    expect(switchOperator).toHaveBeenCalledWith(3, 'owner-pass')
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('staff への切替はパスワード不要', async () => {
    switchOperator.mockResolvedValue(makeMe('staff'))
    const w = await mountAt('/clock-in')
    await w.findAll('.op-pick__btn')[0]?.trigger('click')
    await flushPromises()
    expect(switchOperator).toHaveBeenCalledWith(2, undefined)
  })

  it('［別の人でログイン］でログアウトしてログイン画面へ', async () => {
    logout.mockResolvedValue(undefined)
    const w = await mountAt('/clock-in')
    await w.find('.clock-card__other').trigger('click')
    await flushPromises()
    expect(logout).toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('login')
  })
})
