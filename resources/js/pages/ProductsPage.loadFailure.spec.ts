import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ja } from '@/i18n/ja'
import ProductsPage from '@/pages/ProductsPage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import { stubMatchMedia } from '@/test/matchMedia'
import type { Product } from '@/types/api'

const api = vi.hoisted(() => ({ fetchCatalog: vi.fn() }))
vi.mock('@/api/catalog', () => ({ ...api, optionTemplateUrl: 'option-template.csv' }))

const product: Product = {
  id: 1, code: 'P0001', name: 'コーヒー', memo: null, category_id: null, price: 400, color: 'gray', sort_order: 1,
  is_active: true, track_stock: false, stock_qty: 0, customer_visible: true, is_discount: false, options: [], option_groups: [],
}

let wrapper: VueWrapper | null = null

async function mountPage(): Promise<void> {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('owner')
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'home', component: ProductsPage }] })
  wrapper = mount(ProductsPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
}

describe('S08 商品の一覧を読めないとき', () => {
  beforeEach(() => {
    stubMatchMedia(true)
    api.fetchCatalog.mockReset()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('通信の打ち切りを「商品がありません」と見せず、再読み込みで一覧を出す', async () => {
    api.fetchCatalog.mockRejectedValueOnce(new AxiosError('timeout', 'ECONNABORTED'))
    await mountPage()
    expect(document.querySelector('[role="alert"]')?.textContent).toContain(ja.error.network)
    expect(document.body.textContent).not.toContain(ja.products.empty)

    api.fetchCatalog.mockResolvedValueOnce({ categories: [], products: [product] })
    const retry = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === ja.staff.retry)
    expect(retry).toBeDefined()
    retry?.click()
    await flushPromises()
    expect(document.querySelector('[role="alert"]')).toBeNull()
    expect(document.body.textContent).toContain('コーヒー')
  })
})
