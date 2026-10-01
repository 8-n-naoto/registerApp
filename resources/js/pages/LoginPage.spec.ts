import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import LoginPage from '@/pages/LoginPage.vue'
import { useUiStore } from '@/stores/ui'
import { apiError, makeMe } from '@/test/helpers'

const login = vi.fn()
vi.mock('@/api/auth', () => ({ login: (...args: unknown[]) => login(...args), fetchMe: vi.fn(), logout: vi.fn(), updatePassword: vi.fn() }))
// 端末の店舗に勤務中の人がいなければ切替の欄は出ない（13 §7）
vi.mock('@/api/attendance', () => ({ fetchOperators: vi.fn(() => Promise.resolve([])) }))

const Blank = { template: '<div />' }
let router: Router

async function mountAt(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginPage },
      { path: '/', name: 'home', component: Blank },
      { path: '/admin/stores', name: 'admin-stores', component: Blank },
      { path: '/sales/daily', name: 'sales-daily', component: Blank },
    ],
  })
  await router.push(path)
  return mount(LoginPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
}

async function submit(w: Awaited<ReturnType<typeof mountAt>>, password = 'wrong-pass') {
  await w.find('#login-id').setValue('owner-a')
  await w.find('#password').setValue(password)
  await w.find('form').trigger('submit')
  await flushPromises()
}

describe('S01 ログイン（08 §5.1）', () => {
  beforeEach(() => {
    login.mockReset()
  })
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it('AC-S01-1：owner は S00、admin は A01 へ', async () => {
    login.mockResolvedValue(makeMe('owner'))
    let w = await mountAt('/login')
    await submit(w, 'password')
    expect(login).toHaveBeenCalledWith({ login_id: 'owner-a', password: 'password', remember: false })
    expect(router.currentRoute.value.name).toBe('home')
    w.unmount()

    login.mockResolvedValue(makeMe('admin'))
    w = await mountAt('/login')
    await submit(w, 'password')
    expect(router.currentRoute.value.name).toBe('admin-stores')
  })

  it('AC-S01-2：誤ったパスワードはメッセージを出してパスワード欄を空にする', async () => {
    login.mockRejectedValue(apiError(422, { message: 'x', errors: { login_id: ['ログイン ID またはパスワードが違います'] } }))
    const w = await mountAt('/login')
    await submit(w)
    expect(w.find('.field__error').text()).toBe('ログイン ID またはパスワードが違います')
    expect((w.find('#password').element as HTMLInputElement).value).toBe('')
    expect((w.find('#login-id').element as HTMLInputElement).value).toBe('owner-a')
  })

  it('AC-S01-3：429 は残り秒数を出し、その間ボタンを無効にする', async () => {
    vi.useFakeTimers()
    login.mockRejectedValue(apiError(429, { message: 'x', code: 'TOO_MANY_ATTEMPTS' }, { 'retry-after': '30' }))
    const w = await mountAt('/login')
    await submit(w)
    expect(w.find('[role="alert"]').text()).toBe('しばらく待ってから再度お試しください（あと 30 秒）')
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await vi.advanceTimersByTimeAsync(30_000)
    expect(w.find('[role="alert"]').exists()).toBe(false)
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('AC-S01-4：停止中は API のメッセージを出す', async () => {
    login.mockRejectedValue(apiError(403, { message: 'この店舗は利用停止中です', code: 'STORE_SUSPENDED' }))
    const w = await mountAt('/login')
    await submit(w, 'password')
    expect(w.find('[role="alert"]').text()).toBe('この店舗は利用停止中です')
    expect(useUiStore().loginNotice).toBe('この店舗は利用停止中です')
  })

  it('AC-S01-6：redirect はアプリ内のパスだけ使う', async () => {
    login.mockResolvedValue(makeMe('owner'))
    let w = await mountAt('/login?redirect=/sales/daily')
    await submit(w, 'password')
    expect(router.currentRoute.value.path).toBe('/sales/daily')
    w.unmount()

    w = await mountAt('/login?redirect=https://evil.example/')
    await submit(w, 'password')
    expect(router.currentRoute.value.name).toBe('home')
  })
})
