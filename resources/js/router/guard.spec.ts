import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiError, makeMe } from '@/test/helpers'

const fetchMe = vi.fn()
vi.mock('@/api/auth', () => ({ fetchMe: () => fetchMe(), login: vi.fn(), logout: vi.fn(), updatePassword: vi.fn() }))
// ガードだけを見る。初回のバンドルに含める S02 は部品が多く、読み込みに時間がかかるので空の画面に置き換える
vi.mock('@/pages/RegisterPage.vue', () => ({ default: { template: '<p>register</p>' } }))
vi.mock('@/pages/TableOrderPage.vue', () => ({ default: { template: '<p>table</p>' } }))

async function freshRouter() {
  vi.resetModules()
  setActivePinia(createPinia())
  const { router } = await import('@/router')
  return router
}

describe('ナビゲーションガード（08 §4）', () => {
  beforeEach(() => {
    fetchMe.mockReset()
  })

  it('未ログインは /login?redirect= へ', async () => {
    fetchMe.mockRejectedValue(apiError(401, { message: 'Unauthenticated.' }))
    const router = await freshRouter()
    await router.push('/account')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/account')
  })

  it('GET /me は初回に 1 回だけ呼ぶ', async () => {
    fetchMe.mockResolvedValue(makeMe('owner'))
    const router = await freshRouter()
    await router.push('/')
    await router.push('/account')
    expect(fetchMe).toHaveBeenCalledTimes(1)
  })

  it('admin は S00 ではなく A01 へ、owner / staff は A01 を開けない', async () => {
    fetchMe.mockResolvedValue(makeMe('admin'))
    let router = await freshRouter()
    await router.push('/')
    expect(router.currentRoute.value.name).toBe('admin-stores')

    fetchMe.mockResolvedValue(makeMe('staff'))
    router = await freshRouter()
    await router.push('/admin/stores')
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('ログイン済みでログイン画面を開くと役割のホームへ', async () => {
    fetchMe.mockResolvedValue(makeMe('owner'))
    const router = await freshRouter()
    await router.push('/login')
    expect(router.currentRoute.value.name).toBe('home')
  })

  it('お客さんの画面（C01）は GET /me を呼ばずに開く（12 §8.9）', async () => {
    const router = await freshRouter()
    await router.push('/t/abcdefghijklmnopqrstuvwxyz')
    expect(router.currentRoute.value.name).toBe('table-order')
    expect(fetchMe).not.toHaveBeenCalled()
  })

  it('13 §7：勤務中でない owner / staff は出勤（S22）へ。アカウントは開ける', async () => {
    fetchMe.mockResolvedValue({ ...makeMe('staff'), attendance: null })
    const router = await freshRouter()
    await router.push('/')
    expect(router.currentRoute.value.name).toBe('clock-in')
    expect(router.currentRoute.value.query.redirect).toBeUndefined()

    await router.push('/sales/daily')
    expect(router.currentRoute.value.name).toBe('clock-in')
    expect(router.currentRoute.value.query.redirect).toBe('/sales/daily')

    await router.push('/account')
    expect(router.currentRoute.value.name).toBe('account')
  })

  it('13 §7：勤務中なら出勤（S22）は開かずホームへ。admin は出勤しない', async () => {
    fetchMe.mockResolvedValue(makeMe('owner'))
    let router = await freshRouter()
    await router.push('/clock-in')
    expect(router.currentRoute.value.name).toBe('home')

    fetchMe.mockResolvedValue(makeMe('admin'))
    router = await freshRouter()
    await router.push('/sales/daily')
    expect(router.currentRoute.value.name).toBe('sales-daily')
  })
})
