import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ProductEditPanel from '@/components/products/ProductEditPanel.vue'
import type { Product } from '@/types/api'

const api = vi.hoisted(() => ({
  changeStock: vi.fn(),
  createProduct: vi.fn(),
  deleteProduct: vi.fn(),
  updateProduct: vi.fn(),
}))
vi.mock('@/api/catalog', () => api)

const product: Product = {
  id: 5, code: 'P0005', name: 'コーヒー', memo: null, category_id: null, price: 400, color: 'gray', sort_order: 1,
  is_active: true, track_stock: false, stock_qty: 0, customer_visible: true, is_discount: false, options: [], option_groups: [],
}

let wrapper: VueWrapper | null = null
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})
beforeEach(() => {
  setActivePinia(createPinia())
  Object.values(api).forEach((fn) => fn.mockReset())
  Element.prototype.scrollIntoView = () => {}
})

describe('ProductEditPanel の保存後の入力欄', () => {
  it('全角の価格や前後の空白は、保存した値に揃えて見せる', async () => {
    api.updateProduct.mockResolvedValue({ ...product, name: 'カフェラテ', memo: '温', price: 999 })
    wrapper = mount(ProductEditPanel, {
      props: { product, categories: [], defaultCategoryId: null },
      attachTo: document.body,
    })

    await wrapper.get('#product-name').setValue('  カフェラテ  ')
    await wrapper.get('#product-memo').setValue(' 温 ')
    await wrapper.get('#product-price').setValue('９９９')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(api.updateProduct).toHaveBeenCalledOnce()
    expect((wrapper.get('#product-name').element as HTMLInputElement).value).toBe('カフェラテ')
    expect((wrapper.get('#product-memo').element as HTMLTextAreaElement).value).toBe('温')
    expect((wrapper.get('#product-price').element as HTMLInputElement).value).toBe('999')
  })
})
