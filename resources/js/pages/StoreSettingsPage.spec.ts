import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { StoreSettingsBundle } from '@/api/settings'
import StoreSettingsPage from '@/pages/StoreSettingsPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { OrderSettings } from '@/types/api'

const api = vi.hoisted(() => ({
  fetchStoreSettings: vi.fn(),
  updateStoreSettings: vi.fn(),
  createTaxType: vi.fn(),
  updateTaxType: vi.fn(),
  reorderTaxTypes: vi.fn(),
  createPaymentMethod: vi.fn(),
  updatePaymentMethod: vi.fn(),
  reorderPaymentMethods: vi.fn(),
  fetchOrderSettings: vi.fn(),
  updateOrderSettings: vi.fn(),
}))
vi.mock('@/api/settings', () => api)

function bundle(): StoreSettingsBundle {
  return {
    store: { id: 1, name: 'テスト店 A', price_mode: 'tax_included', rounding: 'floor', day_cutoff_time: '04:30', stock_enabled: true },
    tax_types: [
      { id: 1, name: '店内', rate_permille: 100, sort_order: 1, is_default: true, is_active: true },
      { id: 2, name: 'テイクアウト', rate_permille: 80, sort_order: 2, is_default: false, is_active: true },
    ],
    payment_methods: [{ id: 1, name: '現金', is_cash: true, sort_order: 1, is_active: true }],
  }
}

function orderSettings(extra: Partial<OrderSettings> = {}): OrderSettings {
  return { customer_order_enabled: true, customer_order_approval: false, customer_session_minutes: 180, polling_mode: 'always', polling_windows: [], ...extra }
}

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('owner')
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'home', component: StoreSettingsPage }] })
  const w = mount(StoreSettingsPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return w
}

describe('S09 店舗設定（08 §5.10）', () => {
  beforeEach(() => {
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchStoreSettings.mockResolvedValue(bundle())
    api.fetchOrderSettings.mockResolvedValue(orderSettings())
  })

  it('設定を読み込み、税率は % で、既定・現金扱いの印を出す', async () => {
    const w = await mountPage()
    expect((w.find('#store-name').element as HTMLInputElement).value).toBe('テスト店 A')
    const selects = w.findAll('select')
    expect((selects[0]?.element as HTMLSelectElement).value).toBe('04')
    expect((selects[1]?.element as HTMLSelectElement).value).toBe('30')
    const text = w.text()
    expect(text).toContain('10%')
    expect(text).toContain('8%')
    expect(text).toContain('既定')
    expect(text).toContain('現金扱い')
  })

  it('保存は締め時刻を HH:MM にして送り、ホームの店舗名も変える', async () => {
    const w = await mountPage()
    api.updateStoreSettings.mockResolvedValue({ ...bundle().store, name: '新店名', price_mode: 'tax_excluded', day_cutoff_time: '02:05' })
    await w.find('#store-name').setValue(' 新店名 ')
    await w.findAll('[role="radio"]').find((b) => b.text() === '税抜で登録')?.trigger('click')
    const selects = w.findAll('select')
    await selects[0]?.setValue('02')
    await selects[1]?.setValue('05')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(api.updateStoreSettings).toHaveBeenCalledWith({ name: '新店名', price_mode: 'tax_excluded', rounding: 'floor', day_cutoff_time: '02:05', stock_enabled: true })
    expect(useAuthStore().me?.store?.name).toBe('新店名')
    expect(w.text()).toContain('保存しました')
    expect(w.text()).toContain('会計のときに消費税を足します')
  })

  it('AC-S09-5：在庫管理のスイッチを OFF にして保存する', async () => {
    const w = await mountPage()
    const box = w.findAll('input[type="checkbox"]').find((c) => c.element.parentElement?.textContent?.includes('在庫管理を使う'))
    expect((box?.element as HTMLInputElement).checked).toBe(true)
    api.updateStoreSettings.mockResolvedValue({ ...bundle().store, stock_enabled: false })
    await box?.setValue(false)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updateStoreSettings).toHaveBeenCalledWith(expect.objectContaining({ stock_enabled: false }))
    expect(useAuthStore().me?.store?.stock_enabled).toBe(false)
  })

  it('AC-S09-3：最後の有効な税区分は停止できず「1 つ以上必要です」', async () => {
    const data = bundle()
    data.tax_types[1] = { ...data.tax_types[1]!, is_active: false }
    api.fetchStoreSettings.mockResolvedValue(data)
    const w = await mountPage()

    await w.find('[aria-label="店内 を編集"]').trigger('click')
    const active = w.findAll('.adm-check').find((c) => c.text() === '有効')
    await active?.find('input').setValue(false)
    await w.find<HTMLInputElement>('#tax-name-1').element.form?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(api.updateTaxType).not.toHaveBeenCalled()
    expect(w.text()).toContain('有効な税区分は 1 つ以上必要です')
  })

  it('税区分の追加は % を千分率にして送り、一覧を取り直す', async () => {
    const w = await mountPage()
    api.createTaxType.mockResolvedValue({})
    const next = bundle()
    next.tax_types.push({ id: 3, name: 'イートイン', rate_permille: 100, sort_order: 3, is_default: false, is_active: true })
    api.fetchStoreSettings.mockResolvedValue(next)

    await w.findAll('button').find((b) => b.text() === '＋税区分を追加')?.trigger('click')
    await w.find('#tax-name-new').setValue('イートイン')
    await w.find('#tax-rate-new').setValue('10')
    await w.find<HTMLInputElement>('#tax-name-new').element.form?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(api.createTaxType).toHaveBeenCalledWith({ name: 'イートイン', rate_permille: 100, is_default: false })
    expect(w.text()).toContain('イートイン')
  })

  it('税率が不正なら送らない', async () => {
    const w = await mountPage()
    await w.findAll('button').find((b) => b.text() === '＋税区分を追加')?.trigger('click')
    await w.find('#tax-name-new').setValue('x')
    await w.find('#tax-rate-new').setValue('8.25')
    await w.find<HTMLInputElement>('#tax-name-new').element.form?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(api.createTaxType).not.toHaveBeenCalled()
    expect(w.text()).toContain('小数は 1 桁まで')
  })

  it('支払方法の 422 は項目の下に出す', async () => {
    const w = await mountPage()
    api.createPaymentMethod.mockRejectedValue(apiError(422, { message: 'x', errors: { name: ['同じ名前の支払方法があります'] } }))
    await w.findAll('button').find((b) => b.text() === '＋支払方法を追加')?.trigger('click')
    await w.find('#pay-name-new').setValue('現金')
    await w.find<HTMLInputElement>('#pay-name-new').element.form?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(w.text()).toContain('同じ名前の支払方法があります')
  })
})

describe('S09 注文の設定（12 §8.8）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    for (const fn of Object.values(api)) fn.mockReset()
    api.fetchStoreSettings.mockResolvedValue(bundle())
    api.fetchOrderSettings.mockResolvedValue(orderSettings())
  })

  function panel(): HTMLElement {
    const el = document.querySelector<HTMLElement>('[aria-labelledby="order-settings-heading"]')
    if (!el) throw new Error('panel not found')
    return el
  }

  function buttonIn(root: ParentNode, text: string): HTMLButtonElement {
    const el = [...root.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
    if (!el) throw new Error(`button ${text} not found`)
    return el
  }

  function select(label: string): HTMLSelectElement {
    const el = panel().querySelector<HTMLSelectElement>(`select[aria-label="${label}"]`)
    if (!el) throw new Error(`select ${label} not found`)
    return el
  }

  async function choose(el: HTMLSelectElement, value: string): Promise<void> {
    el.value = value
    el.dispatchEvent(new Event('change'))
    await flushPromises()
  }

  it('読み込んだ設定を出し、この欄だけを保存する', async () => {
    api.updateOrderSettings.mockImplementation((input: OrderSettings) => Promise.resolve(input))
    await mountPage()
    const enabled = panel().querySelector<HTMLInputElement>('[data-customer-order]')
    expect(enabled?.checked).toBe(true)
    expect((panel().querySelector('#order-session') as HTMLSelectElement).value).toBe('180')
    expect(panel().querySelector('[role="radio"][aria-checked="true"]')?.textContent?.trim()).toBe('常に ON')

    panel().querySelector<HTMLInputElement>('[data-approval]')?.click()
    await choose(panel().querySelector('#order-session') as HTMLSelectElement, '90')
    await flushPromises()
    panel().querySelector<HTMLButtonElement>('[data-save-orders]')?.click()
    await flushPromises()
    expect(api.updateOrderSettings).toHaveBeenCalledWith({
      customer_order_enabled: true,
      customer_order_approval: true,
      customer_session_minutes: 90,
      polling_mode: 'always',
      polling_windows: [],
    })
    expect(api.updateStoreSettings).not.toHaveBeenCalled()
    expect(panel().querySelector('.adm-ok')?.textContent).toContain('保存しました')
  })

  it('AC-S09-6：時間で切り替え。時間帯は 3 つまで、5 分刻み、翌日までの説明を出して送る', async () => {
    api.updateOrderSettings.mockImplementation((input: OrderSettings) => Promise.resolve(input))
    await mountPage()
    await (buttonIn(panel(), '時間で切り替え').click(), flushPromises())
    expect(panel().querySelectorAll('[data-window]')).toHaveLength(1)
    expect(panel().textContent).toContain('終了が開始より前なら翌日まで')
    expect([...select('時間帯 1 開始（分）').options].map((o) => o.value)).toHaveLength(12)

    await (buttonIn(panel(), '＋時間帯を追加').click(), flushPromises())
    await choose(select('時間帯 2 開始（時）'), '22')
    await choose(select('時間帯 2 終了（時）'), '02')
    await choose(select('時間帯 2 終了（分）'), '30')
    await (buttonIn(panel(), '＋時間帯を追加').click(), flushPromises())
    expect(panel().querySelectorAll('[data-window]')).toHaveLength(3)
    expect([...panel().querySelectorAll('button')].some((b) => b.textContent?.trim() === '＋時間帯を追加')).toBe(false)
    buttonIn(panel().querySelector('[data-window="2"]') ?? panel(), '削除').click()
    await flushPromises()

    panel().querySelector<HTMLButtonElement>('[data-save-orders]')?.click()
    await flushPromises()
    expect(api.updateOrderSettings).toHaveBeenCalledWith(expect.objectContaining({
      polling_mode: 'schedule',
      polling_windows: [{ start: '11:00', end: '14:00' }, { start: '22:00', end: '02:30' }],
    }))
  })

  it('AC-S09-7：開始と終了が同じ時間帯は送らずに止める', async () => {
    api.fetchOrderSettings.mockResolvedValue(orderSettings({ polling_mode: 'schedule', polling_windows: [{ start: '11:00', end: '14:00' }] }))
    await mountPage()
    await choose(select('時間帯 1 終了（時）'), '11')
    expect(panel().querySelector('[data-window="0"] .adm-error')?.textContent).toContain('開始と終了は別の時刻にしてください')
    panel().querySelector<HTMLButtonElement>('[data-save-orders]')?.click()
    await flushPromises()
    expect(api.updateOrderSettings).not.toHaveBeenCalled()
  })

  it('サーバーの 422 は時間帯の下に出す', async () => {
    api.fetchOrderSettings.mockResolvedValue(orderSettings({ polling_mode: 'schedule', polling_windows: [{ start: '11:00', end: '14:00' }] }))
    api.updateOrderSettings.mockRejectedValue(apiError(422, { message: '入力に誤りがあります', errors: { 'polling_windows.0.end': ['時刻の形式が正しくありません'] } }))
    await mountPage()
    panel().querySelector<HTMLButtonElement>('[data-save-orders]')?.click()
    await flushPromises()
    expect(panel().querySelector('[data-window="0"] .adm-error')?.textContent).toContain('時刻の形式が正しくありません')
  })

  it('注文の設定を読めなくても、ほかの欄は使える', async () => {
    api.fetchOrderSettings.mockRejectedValue(apiError(500, { message: 'x' }))
    await mountPage()
    expect(panel().querySelector('[role="alert"]')?.textContent).toContain('x')
    expect(document.querySelector('#store-name')).not.toBeNull()
  })
})
