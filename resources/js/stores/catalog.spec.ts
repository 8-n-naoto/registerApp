import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RegisterBootstrap } from '@/api/register'
import { resetOfflineDbForTest } from '@/lib/offlineDb'
import { useCatalogStore } from '@/stores/catalog'
import { useRegisterStore } from '@/stores/register'
import { apiError } from '@/test/helpers'
import { makeBootstrap, makeProduct } from '@/test/register'

const api = vi.hoisted(() => ({ fetchBootstrap: vi.fn(), createSale: vi.fn(), fetchSale: vi.fn(), cancelSale: vi.fn() }))
vi.mock('@/api/register', () => api)

describe('catalog（GET /register/bootstrap の共有）', () => {
  beforeEach(() => {
    localStorage.clear()
    resetOfflineDbForTest()
    setActivePinia(createPinia())
    api.fetchBootstrap.mockReset()
  })

  it('同時に呼ばれたら 1 回の通信にまとめ、応答を持つ。先読みは持っていれば送らない', async () => {
    api.fetchBootstrap.mockResolvedValue(makeBootstrap())
    const catalog = useCatalogStore()
    const [a, b] = await Promise.all([catalog.fetch(), catalog.fetch()])
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(1)
    expect(a).toBe(b)
    expect(catalog.data).toBe(a)
    catalog.prefetch()
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(1)
  })

  it('clear() の前に出した要求の応答は残さない（ログアウトした人の商品を次の人に出さない）', async () => {
    let resolve: (v: RegisterBootstrap) => void = () => undefined
    api.fetchBootstrap.mockImplementationOnce(() => new Promise((r) => { resolve = r }))
    const catalog = useCatalogStore()
    const pending = catalog.fetch()
    catalog.clear()
    resolve(makeBootstrap())
    await pending
    expect(catalog.data).toBeNull()
  })

  it('レジは先読みした応答で読み込み中を出さずに描き、最新を取り直して反映する', async () => {
    api.fetchBootstrap.mockResolvedValueOnce(makeBootstrap())
    await useCatalogStore().fetch()

    let resolve: (v: RegisterBootstrap) => void = () => undefined
    api.fetchBootstrap.mockImplementationOnce(() => new Promise((r) => { resolve = r }))
    const register = useRegisterStore()
    const loading = register.load()
    expect(register.loading).toBe(false)
    expect(register.bootstrap).not.toBeNull()

    const base = makeBootstrap()
    resolve({ ...base, products: [...base.products, makeProduct(999, '新商品')] })
    await loading
    expect(register.products.get(999)?.name).toBe('新商品')
  })

  it('14 §7.3 受け取った応答を端末に残し、通信できないときはログイン中の店舗の分を返す（fromCache）', async () => {
    const { AxiosError } = await import('axios')
    const catalog = useCatalogStore()
    catalog.cacheStoreId = 1
    api.fetchBootstrap.mockResolvedValueOnce(makeBootstrap())
    await catalog.fetch()
    await flushPromises()
    expect(catalog.fromCache).toBe(false)

    setActivePinia(createPinia())
    const fresh = useCatalogStore()
    fresh.cacheStoreId = 1
    api.fetchBootstrap.mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
    const res = await fresh.fetch()
    expect(res.store.id).toBe(1)
    expect(fresh.fromCache).toBe(true)

    // 別の店舗でログインしているときは使わない。通信できない以外の失敗も使わない
    setActivePinia(createPinia())
    const other = useCatalogStore()
    other.cacheStoreId = 2
    api.fetchBootstrap.mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
    await expect(other.fetch()).rejects.toThrow('Network Error')
    other.cacheStoreId = 1
    api.fetchBootstrap.mockRejectedValueOnce(apiError(500, {}))
    await expect(other.fetch()).rejects.toThrow()
  })
})
