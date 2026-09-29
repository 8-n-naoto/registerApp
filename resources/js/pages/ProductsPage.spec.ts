import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ImportResult } from '@/api/catalog'
import ProductsPage from '@/pages/ProductsPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import { stubMatchMedia } from '@/test/matchMedia'
import type { Category, Product } from '@/types/api'

const api = vi.hoisted(() => ({
  fetchCatalog: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
  changeStock: vi.fn(),
  reorderProducts: vi.fn(),
  importProducts: vi.fn(),
  createCategory: vi.fn(),
  updateCategory: vi.fn(),
  deleteCategory: vi.fn(),
  reorderCategories: vi.fn(),
  createOption: vi.fn(),
  updateOption: vi.fn(),
  deleteOption: vi.fn(),
  reorderOptions: vi.fn(),
}))
vi.mock('@/api/catalog', () => api)

function product(id: number, name: string, categoryId: number | null, extra: Partial<Product> = {}): Product {
  return {
    id, name, category_id: categoryId, price: 400, color: 'gray', sort_order: id,
    is_active: true, track_stock: false, stock_qty: 0, options: [], ...extra,
  }
}

const categories: Category[] = [
  { id: 20, name: 'フード', sort_order: 2, product_count: 1 },
  { id: 10, name: 'ドリンク', sort_order: 1, product_count: 2 },
]

async function mountPage(path = '/products') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('owner')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/products', name: 'products', component: ProductsPage },
      { path: '/', name: 'home', component: ProductsPage },
    ],
  })
  await router.push(path)
  const w = mount(ProductsPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { w, router }
}

function tab(name: string): HTMLElement {
  const el = [...document.querySelectorAll<HTMLElement>('.tabs__tab')].find((b) => b.textContent?.trim() === name)
  if (!el) throw new Error(`tab ${name} not found`)
  return el
}

const names = (): string[] => [...document.querySelectorAll('.tile__name')].map((e) => e.textContent ?? '')

describe('S08 商品管理（08 §5.9）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    stubMatchMedia(true)
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchCatalog.mockResolvedValue({
      categories,
      products: [
        product(3, 'ケーキ', 20),
        product(2, '紅茶', 10, { track_stock: true, stock_qty: 5 }),
        product(1, 'コーヒー', 10),
        product(4, 'おまけ', null, { track_stock: true, stock_qty: 0 }),
      ],
    })
  })

  it('タブはすべて・未分類・カテゴリの並び順・＋カテゴリ。すべてはカテゴリ順 → 未分類', async () => {
    await mountPage()
    expect([...document.querySelectorAll('.tabs__tab')].map((e) => e.textContent?.trim()))
      .toEqual(['すべて', '未分類', 'ドリンク', 'フード', '＋カテゴリ'])
    expect(names()).toEqual(['コーヒー', '紅茶', 'ケーキ', 'おまけ'])

    tab('未分類').click()
    await flushPromises()
    expect(names()).toEqual(['おまけ'])
  })

  it('AC-S08-2：在庫管理 ON は「残 n」、在庫 0 は「売切」', async () => {
    await mountPage()
    expect(document.body.textContent).toContain('残 5')
    expect(document.body.textContent).toContain('売切')
  })

  it('AC-S08-1：在庫管理を OFF にすると在庫数の欄が消える', async () => {
    await mountPage()
    ;[...document.querySelectorAll<HTMLElement>('.items__btn')][1]?.click() // 紅茶
    await flushPromises()
    expect(document.querySelector('#stock-value')).not.toBeNull()

    const check = [...document.querySelectorAll<HTMLLabelElement>('.adm-check')].find((l) => l.textContent?.includes('在庫を管理する'))
    check?.querySelector('input')?.click()
    await flushPromises()
    expect(document.querySelector('#stock-value')).toBeNull()
  })

  it('在庫の入荷は add で送り、表示を更新する', async () => {
    await mountPage()
    ;[...document.querySelectorAll<HTMLElement>('.items__btn')][1]?.click()
    await flushPromises()
    api.changeStock.mockResolvedValue(product(2, '紅茶', 10, { track_stock: true, stock_qty: 8 }))
    const input = document.querySelector<HTMLInputElement>('#stock-value')
    if (!input) throw new Error('no stock input')
    input.value = '3'
    input.dispatchEvent(new Event('input'))
    ;[...document.querySelectorAll<HTMLElement>('button')].find((b) => b.textContent?.trim() === '入荷 +')?.click()
    await flushPromises()
    expect(api.changeStock).toHaveBeenCalledWith(2, 'add', 3)
    expect(document.body.textContent).toContain('現在の在庫 8')
    expect(document.body.textContent).toContain('残 8')
  })

  it('新規の商品は選択中のカテゴリに入れ、価格は整数にして送る', async () => {
    await mountPage()
    tab('フード').click()
    await flushPromises()
    api.createProduct.mockResolvedValue(product(9, 'パン', 20, { price: 1200 }))
    ;[...document.querySelectorAll<HTMLElement>('button')].find((b) => b.textContent?.trim() === '＋商品')?.click()
    await flushPromises()
    const nameInput = document.querySelector<HTMLInputElement>('#product-name')
    const priceInput = document.querySelector<HTMLInputElement>('#product-price')
    if (!nameInput || !priceInput) throw new Error('no inputs')
    nameInput.value = 'パン'
    nameInput.dispatchEvent(new Event('input'))
    priceInput.value = '1,200'
    priceInput.dispatchEvent(new Event('input'))
    nameInput.form?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(api.createProduct).toHaveBeenCalledWith({
      name: 'パン', price: 1200, category_id: 20, color: 'gray', is_active: true, track_stock: false, stock_qty: 0,
    })
    expect(names()).toContain('パン')
  })

  it('AC-S08-4：カテゴリを削除すると商品は未分類に移る', async () => {
    await mountPage()
    tab('ドリンク').click()
    await flushPromises()
    ;[...document.querySelectorAll<HTMLElement>('button')].find((b) => b.textContent?.trim() === '編集')?.click()
    await flushPromises()
    ;[...document.querySelectorAll<HTMLElement>('.adm-btn--danger')].find((b) => b.textContent?.trim() === '削除')?.click()
    await flushPromises()
    expect(document.body.textContent).toContain('所属している商品 2 件は「未分類」に移ります')

    api.deleteCategory.mockResolvedValue(undefined)
    ;[...document.querySelectorAll<HTMLElement>('.big-btn--danger')].at(-1)?.click()
    await flushPromises()
    expect(api.deleteCategory).toHaveBeenCalledWith(10)
    expect(document.querySelector('.tabs__tab[aria-pressed="true"]')?.textContent?.trim()).toBe('未分類')
    expect(names()).toEqual(['コーヒー', '紅茶', 'おまけ'])
  })

  it('並び替えはカテゴリのタブで行い、［完了］で PUT /products/order', async () => {
    await mountPage()
    tab('ドリンク').click()
    await flushPromises()
    ;[...document.querySelectorAll<HTMLElement>('button')].find((b) => b.textContent?.trim() === '並び替え')?.click()
    await flushPromises()
    const grips = document.querySelectorAll<HTMLElement>('[data-sort-grip]')
    grips[0]?.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
    await flushPromises()
    api.reorderProducts.mockResolvedValue(undefined)
    ;[...document.querySelectorAll<HTMLElement>('button')].find((b) => b.textContent?.trim() === '完了')?.click()
    await flushPromises()
    expect(api.reorderProducts).toHaveBeenCalledWith([2, 1])
    expect(names()).toEqual(['紅茶', 'コーヒー'])
  })

  it('AC-S08-5：エラー行のある CSV は行番号と理由を出し、登録ボタンを押せない', async () => {
    const result: ImportResult = {
      dry_run: true, valid_count: 1, new_categories: ['スイーツ'],
      errors: [{ line: 3, messages: ['価格：0〜9,999,999 の整数で入力してください'] }],
      warnings: [{ line: 2, messages: ['同名の商品が既にあります'] }],
      rows: [],
    }
    api.importProducts.mockResolvedValue(result)
    await mountPage('/products?import=1')

    const input = document.querySelector<HTMLInputElement>('input[type="file"]')
    if (!input) throw new Error('no file input')
    const file = new File(['x'], 'p.csv', { type: 'text/csv' })
    Object.defineProperty(input, 'files', { value: [file], configurable: true })
    input.dispatchEvent(new Event('change'))
    await flushPromises()

    expect(api.importProducts).toHaveBeenCalledWith(file, true)
    const text = document.body.textContent ?? ''
    expect(text).toContain('3 行目')
    expect(text).toContain('価格：0〜9,999,999 の整数で入力してください')
    expect(text).toContain('同名の商品が既にあります')
    expect(text).toContain('新しく作るカテゴリ：スイーツ')
    const submit = [...document.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('件を登録する'))
    expect(submit?.disabled).toBe(true)
  })

  it('エラーが無ければ登録でき、一覧を取り直す。422 の IMPORT_INVALID は details を出す', async () => {
    const ok: ImportResult = { dry_run: true, valid_count: 2, new_categories: [], errors: [], warnings: [], rows: [] }
    api.importProducts.mockResolvedValueOnce(ok)
    await mountPage('/products?import=1')
    const input = document.querySelector<HTMLInputElement>('input[type="file"]')
    if (!input) throw new Error('no file input')
    Object.defineProperty(input, 'files', { value: [new File(['x'], 'p.csv')], configurable: true })
    input.dispatchEvent(new Event('change'))
    await flushPromises()

    api.importProducts.mockRejectedValueOnce(apiError(422, {
      message: '取り込めない行があります。', code: 'IMPORT_INVALID',
      details: { ...ok, dry_run: false, valid_count: 0, errors: [{ line: 0, messages: ['商品数の上限（500 件）を超えます'] }] },
    }))
    const submit = [...document.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('2 件を登録する'))
    expect(submit?.disabled).toBe(false)
    submit?.click()
    await flushPromises()
    expect(api.importProducts).toHaveBeenLastCalledWith(expect.any(File), false)
    expect(document.body.textContent).toContain('ファイル全体')
    expect(document.body.textContent).toContain('商品数の上限（500 件）を超えます')
  })
})
