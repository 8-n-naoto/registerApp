import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import TableOrderPage from '@/pages/TableOrderPage.vue'
import { apiError } from '@/test/helpers'
import type { PublicMenu, PublicMenuProduct, PublicOrder } from '@/types/api'

const api = vi.hoisted(() => ({ fetchMenu: vi.fn(), createCustomerOrder: vi.fn(), fetchCustomerOrders: vi.fn() }))
vi.mock('@/api/publicTable', () => api)

const TOKEN = 'tokenAAAA-0123456789abcdefghijklmnop'

function product(extra: Partial<PublicMenuProduct> = {}): PublicMenuProduct {
  return { id: 1, category_id: 10, name: 'コーヒー', memo: 'ホット', price: 400, color: 'blue', sold_out: false, options: [{ id: 5, name: '大盛り', price: 100 }], ...extra }
}

function menu(extra: Partial<PublicMenu> = {}): PublicMenu {
  return {
    store_name: 'テスト店 A',
    table_name: 'T1',
    price_mode: 'tax_included',
    accepting: true,
    not_accepting_reason: null,
    categories: [{ id: 10, name: 'ドリンク' }, { id: 20, name: 'デザート' }],
    products: [
      product(),
      product({ id: 2, category_id: 20, name: 'ケーキ', memo: null, price: 500, options: [], sold_out: true }),
      product({ id: 3, category_id: null, name: '水', memo: null, price: 0, options: [] }),
    ],
    limits: { max_items: 30, max_quantity: 20, max_orders_per_session: 20 },
    ...extra,
  }
}

const ORDER: PublicOrder = { order_no: 7, status: 'active', created_at: '2026-09-30T12:00:00+09:00', subtotal: 1000, items: [] }

let wrapper: VueWrapper | null = null

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/t/:token', name: 'table-order', component: TableOrderPage }] })
  await router.push(`/t/${TOKEN}`)
  wrapper = mount(TableOrderPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
}

function el(selector: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(selector)
  if (!found) throw new Error(`${selector} not found`)
  return found
}

function button(text: string, root: ParentNode = document): HTMLButtonElement {
  const found = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!found) throw new Error(`button ${text} not found`)
  return found
}

async function click(target: HTMLElement): Promise<void> {
  target.click()
  await flushPromises()
}

function confirmDialog(): HTMLElement | null {
  return document.querySelector<HTMLElement>('[role="alertdialog"]')
}

/** コーヒー（大盛り）× 2 をカートに入れてカートを開く */
async function addCoffeeAndOpenCart(): Promise<void> {
  await click(el('[data-product="1"]'))
  await click(el('[role="checkbox"]'))
  await click(el('[aria-label="1 つ増やす"]'))
  await click(el('[data-add]'))
  await click(el('[data-cart]'))
}

describe('TableOrderPage（C01）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    sessionStorage.clear()
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchMenu.mockResolvedValue(menu())
    api.fetchCustomerOrders.mockResolvedValue([])
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('店舗名・テーブル名を出し、分類のタブ（分類なしは「その他」）で商品を切り替える', async () => {
    await mountPage()
    expect(api.fetchMenu).toHaveBeenCalledWith(TOKEN)
    expect(el('.c01__store').textContent).toBe('テスト店 A')
    expect(el('.c01__table').textContent).toBe('T1')
    expect([...document.querySelectorAll('.c01__tab')].map((b) => b.textContent?.trim())).toEqual(['ドリンク', 'デザート', 'その他'])
    expect(document.querySelector('[data-product="1"]')).not.toBeNull()
    expect(document.querySelector('[data-product="2"]')).toBeNull()
    await click(button('その他'))
    expect(document.querySelector('[data-product="3"]')).not.toBeNull()
    expect(document.querySelector('[data-product="1"]')).toBeNull()
  })

  it('AC-C01-3：売切の商品は「売切」を出して押せない', async () => {
    await mountPage()
    await click(button('デザート'))
    const cake = el('[data-product="2"]') as HTMLButtonElement
    expect(cake.disabled).toBe(true)
    expect(cake.textContent).toContain('売切')
    await click(cake)
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('商品のシート：オプション・数量で金額が変わり、カートに入れると下のボタンに点数と金額が出る', async () => {
    await mountPage()
    await click(el('[data-product="1"]'))
    expect(el('[data-add]').textContent?.trim()).toBe('カートに入れる（¥400）')
    const option = el('[role="checkbox"]')
    await click(option)
    expect(option.getAttribute('aria-checked')).toBe('true')
    await click(el('[aria-label="1 つ増やす"]'))
    expect(el('[data-quantity]').textContent).toBe('2')
    expect(el('[data-add]').textContent?.trim()).toBe('カートに入れる（¥1,000）')
    await click(el('[data-add]'))
    expect(document.querySelector('[data-add]')).toBeNull()
    expect(el('[data-cart]').textContent?.trim()).toBe('注文内容を見る（2 点 ¥1,000）')
    expect(document.querySelector('.c01__notice')?.textContent).toContain('コーヒー をカートに入れました')
  })

  it('確認してから送る。［やめる］では送らない。送ったら番号を出してカートを空にする', async () => {
    api.createCustomerOrder.mockResolvedValue(ORDER)
    await mountPage()
    await addCoffeeAndOpenCart()
    expect(el('[data-total]').textContent).toBe('¥1,000')

    await click(el('[data-order]'))
    expect(confirmDialog()?.textContent).toContain('この内容で注文しますか？')
    expect(confirmDialog()?.textContent).toContain('2 点・目安 ¥1,000')
    await click(button('やめる'))
    expect(api.createCustomerOrder).not.toHaveBeenCalled()

    await click(el('[data-order]'))
    await click(button('注文する', confirmDialog() ?? document))
    expect(api.createCustomerOrder).toHaveBeenCalledTimes(1)
    expect(confirmDialog()).toBeNull()
    expect(el('.c01__done-title').textContent).toContain('ご注文を受け付けました（#7）')
    expect(el('[data-cart]').textContent?.trim()).toBe('注文内容を見る')
  })

  it('AC-C01-2：通信断はシートにエラーを出してカートを残し、送り直すと同じ client_uuid', async () => {
    api.createCustomerOrder.mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK')).mockResolvedValueOnce(ORDER)
    await mountPage()
    await addCoffeeAndOpenCart()
    await click(el('[data-order]'))
    await click(button('注文する', confirmDialog() ?? document))
    expect(document.querySelector('.cart [role="alert"]')?.textContent).toContain('通信できません')
    expect(document.querySelector('[data-line]')).not.toBeNull()

    await click(el('[data-order]'))
    await click(button('注文する', confirmDialog() ?? document))
    const uuids = api.createCustomerOrder.mock.calls.map((c) => (c[1] as { client_uuid: string }).client_uuid)
    expect(uuids).toHaveLength(2)
    expect(uuids[1]).toBe(uuids[0])
  })

  it('受け付けていないときは理由を出し、［注文する］を出さない', async () => {
    api.fetchMenu.mockResolvedValue(menu({ accepting: false, not_accepting_reason: 'disabled' }))
    await mountPage()
    expect(el('.c01__closed').textContent).toContain('現在 QR での注文は受け付けていません')
    await click(el('[data-cart]'))
    expect(document.querySelector('[data-order]')).toBeNull()
  })

  it('税抜の店は目安の合計に（税抜）を付ける', async () => {
    api.fetchMenu.mockResolvedValue(menu({ price_mode: 'tax_excluded' }))
    await mountPage()
    await addCoffeeAndOpenCart()
    expect(el('.cart__total').textContent).toContain('目安の合計（税抜）')
  })

  it('無効なトークン（404）は案内だけを出す', async () => {
    api.fetchMenu.mockRejectedValue(apiError(404, { message: 'Not Found' }))
    await mountPage()
    expect(el('.c01__invalid').textContent).toContain('この QR コードは使えません')
    expect(document.querySelector('[data-cart]')).toBeNull()
  })

  it('読み込みの失敗（429）は待つ秒数を出し、［もう一度読み込む］で取り直す', async () => {
    api.fetchMenu.mockRejectedValueOnce(apiError(429, { message: 'Too Many Attempts.' }, { 'retry-after': '20' }))
    await mountPage()
    expect(document.querySelector('.c01__error')?.textContent).toContain('（20 秒）')
    await click(button('もう一度読み込む'))
    expect(el('.c01__table').textContent).toBe('T1')
  })

  it('注文履歴：番号・状態・品目ごとの準備中／お届け済みを出し、［更新］で取り直す', async () => {
    api.fetchCustomerOrders.mockResolvedValue([
      { ...ORDER, items: [
        { product_name: 'コーヒー', product_memo: null, quantity: 2, line_total: 1000, memo: null, served: true, options: ['大盛り'] },
        { product_name: '水', product_memo: null, quantity: 1, line_total: 0, memo: null, served: false, options: [] },
      ] },
      { ...ORDER, order_no: 8, status: 'pending' },
    ])
    await mountPage()
    await click(button('注文履歴'))
    const first = el('[data-history="7"]')
    expect(first.textContent).toContain('#7')
    expect(first.textContent).toContain('コーヒー（大盛り） ×2')
    expect(first.textContent).toContain('お届け済み')
    expect(first.textContent).toContain('準備中')
    expect(el('[data-history="8"]').textContent).toContain('確認中')
    await click(button('更新'))
    expect(api.fetchCustomerOrders).toHaveBeenCalledTimes(2)
  })
})
