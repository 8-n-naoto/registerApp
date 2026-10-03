import { AxiosError } from 'axios'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ME_CACHE_KEY, useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { Me } from '@/types/api'

const authApi = vi.hoisted(() => ({ fetchMe: vi.fn<() => Promise<Me>>(), login: vi.fn(), logout: vi.fn(), updatePassword: vi.fn() }))
vi.mock('@/api/auth', () => authApi)
vi.mock('@/api/register', () => ({ fetchBootstrap: vi.fn() }))

const networkError = (): AxiosError => new AxiosError('Network Error', 'ERR_NETWORK')

describe('auth：端末に残す利用者（14 §7.3）', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    authApi.fetchMe.mockReset()
    authApi.logout.mockReset()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('受け取った GET /me を残し、通信できないまま起動したら残した利用者で動く（offlineSession）', async () => {
    authApi.fetchMe.mockResolvedValueOnce(makeMe('staff'))
    await useAuthStore().fetchMe()
    await flushPromises()
    expect(JSON.parse(localStorage.getItem(ME_CACHE_KEY) ?? 'null')).toMatchObject({ user: { role: 'staff' } })

    setActivePinia(createPinia())
    const auth = useAuthStore()
    authApi.fetchMe.mockRejectedValueOnce(networkError())
    await auth.fetchMe()
    expect(auth.me?.user.role).toBe('staff')
    expect(auth.offlineSession).toBe(true)

    // 通信が戻って確かめられたら通常に戻る
    authApi.fetchMe.mockResolvedValueOnce(makeMe('staff'))
    await auth.refreshMe()
    expect(auth.offlineSession).toBe(false)
  })

  it('navigator.onLine が false なら GET /me を呼ばずに残した利用者で動く', async () => {
    localStorage.setItem(ME_CACHE_KEY, JSON.stringify(makeMe('owner')))
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    const auth = useAuthStore()
    await auth.fetchMe()
    expect(authApi.fetchMe).not.toHaveBeenCalled()
    expect(auth.isOwner).toBe(true)
    expect(auth.offlineSession).toBe(true)
  })

  it('admin は残さない。401 は残した利用者を使わずログイン画面へ。ログアウトで消す', async () => {
    authApi.fetchMe.mockResolvedValueOnce(makeMe('admin'))
    await useAuthStore().fetchMe()
    await flushPromises()
    expect(localStorage.getItem(ME_CACHE_KEY)).toBeNull()

    localStorage.setItem(ME_CACHE_KEY, JSON.stringify(makeMe('staff')))
    setActivePinia(createPinia())
    const auth = useAuthStore()
    authApi.fetchMe.mockRejectedValueOnce(apiError(401, {}))
    await auth.fetchMe()
    expect(auth.me).toBeNull()
    expect(auth.offlineSession).toBe(false)
    expect(localStorage.getItem(ME_CACHE_KEY)).toBeNull()

    localStorage.setItem(ME_CACHE_KEY, JSON.stringify(makeMe('staff')))
    authApi.logout.mockResolvedValueOnce(undefined)
    await auth.logout()
    expect(localStorage.getItem(ME_CACHE_KEY)).toBeNull()
  })
})
