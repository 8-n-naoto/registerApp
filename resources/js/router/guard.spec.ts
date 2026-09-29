import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiError, makeMe } from '@/test/helpers'

const fetchMe = vi.fn()
vi.mock('@/api/auth', () => ({ fetchMe: () => fetchMe(), login: vi.fn(), logout: vi.fn(), updatePassword: vi.fn() }))

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
})
