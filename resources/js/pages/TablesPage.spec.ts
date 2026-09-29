import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import TablesPage from '@/pages/TablesPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { OrderTable } from '@/types/api'

const api = vi.hoisted(() => ({
  fetchOrderTables: vi.fn(),
  createOrderTable: vi.fn(),
  updateOrderTable: vi.fn(),
  deleteOrderTable: vi.fn(),
  regenerateOrderTableToken: vi.fn(),
  fetchOrderTableQr: vi.fn(),
  openOrderTable: vi.fn(),
  closeOrderTable: vi.fn(),
}))
vi.mock('@/api/orderTables', () => api)

const SECRET = 'SECRET-TOKEN-abcdefghijklmnopqrstuvwx'

function makeTable(extra: Partial<OrderTable> = {}): OrderTable {
  return {
    id: 1,
    name: 'T1',
    sort_order: 3,
    is_active: true,
    opened_at: null,
    session_expires_at: null,
    unpaid_order_count: 0,
    unpaid_subtotal: 0,
    token_rotated_at: '2026-09-28T18:30:00+09:00',
    ...extra,
  }
}

async function mountPage() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('owner')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings/tables', name: 'settings-tables', component: TablesPage },
      { path: '/', name: 'home', component: { template: '<p>home</p>' } },
    ],
  })
  await router.push('/settings/tables')
  const w = mount(TablesPage, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return w
}

function button(text: string): HTMLButtonElement {
  const el = [...document.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === text)
  if (!el) throw new Error(`button ${text} not found`)
  return el
}

describe('TablesPage（S16）', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    vi.clearAllMocks()
    api.fetchOrderTables.mockResolvedValue([
      makeTable(),
      makeTable({ id: 2, name: 'T2', sort_order: 4, opened_at: '2026-09-30T11:00:00+09:00' }),
      makeTable({ id: 3, name: 'カウンター', sort_order: 5, is_active: false }),
    ])
    api.fetchOrderTableQr.mockImplementation((id: number) =>
      Promise.resolve({ url: `https://example.test/regi/t/${SECRET}${id}`, svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h1v1H0z"/></svg>` }),
    )
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('一覧に名前・有効 / 無効・利用中・QR の作成日を出す', async () => {
    const w = await mountPage()
    const rows = w.findAll('.tbl-row')
    expect(rows).toHaveLength(3)
    expect(rows[0]?.text()).toContain('T1')
    expect(rows[0]?.text()).toContain('有効')
    expect(rows[0]?.text()).toContain('QR 作成 2026/9/28 18:30')
    expect(rows[0]?.text()).not.toContain('利用中')
    expect(rows[1]?.text()).toContain('利用中')
    expect(rows[2]?.text()).toContain('停止中')
    expect(rows[2]?.classes()).toContain('adm-row--off')
  })

  it('テーブルを追加する（名前の前後の空白は落とす）', async () => {
    api.createOrderTable.mockResolvedValue(makeTable({ id: 9, name: 'T9', sort_order: 6 }))
    const w = await mountPage()
    button('＋テーブルを追加').click()
    await flushPromises()
    await w.find('#table-new-name').setValue('  T9 ')
    await w.find('form[aria-label="テーブルの追加"]').trigger('submit')
    await flushPromises()
    expect(api.createOrderTable).toHaveBeenCalledWith('T9')
    expect(w.findAll('.tbl-row')).toHaveLength(4)
    expect(w.text()).toContain('「T9」を追加しました')
  })

  it('名前の重複（422）は入力欄の下に出す', async () => {
    api.createOrderTable.mockRejectedValue(apiError(422, { message: '入力内容を確認してください', errors: { name: ['同じ名前のテーブルがあります'] } }))
    const w = await mountPage()
    button('＋テーブルを追加').click()
    await flushPromises()
    await w.find('#table-new-name').setValue('T1')
    await w.find('form[aria-label="テーブルの追加"]').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('同じ名前のテーブルがあります')
    expect(w.find('#table-new-name').attributes('aria-invalid')).toBe('true')
  })

  it('編集は名前と有効を送り、並び順は今の値のまま送る', async () => {
    api.updateOrderTable.mockResolvedValue(makeTable({ name: 'T1 窓側', is_active: false }))
    const w = await mountPage()
    await w.findAll('.tbl-row')[0]?.trigger('click')
    await w.find('#table-name-1').setValue('T1 窓側')
    await w.find('form[aria-label="テーブルの編集"] input[type="checkbox"]').setValue(false)
    await w.find('form[aria-label="テーブルの編集"]').trigger('submit')
    await flushPromises()
    expect(api.updateOrderTable).toHaveBeenCalledWith(1, { name: 'T1 窓側', sort_order: 3, is_active: false })
    expect(w.findAll('.tbl-row')[0]?.text()).toContain('T1 窓側')
  })

  it('QR は画像で出し、URL の文字は画面のどこにも出さない', async () => {
    await mountPage()
    const qrButtons = [...document.querySelectorAll<HTMLButtonElement>('button')].filter((b) => b.textContent?.trim() === 'QR を表示')
    qrButtons[0]?.click()
    await flushPromises()
    expect(api.fetchOrderTableQr).toHaveBeenCalledWith(1)
    const dialog = document.querySelector('[role="dialog"]')
    expect(dialog?.textContent).toContain('テスト店 A')
    expect(dialog?.textContent).toContain('T1')
    expect(dialog?.textContent).toContain('スマホのカメラで読み取って注文してください')
    const img = dialog?.querySelector('img')
    expect(img?.getAttribute('src')).toMatch(/^data:image\/svg\+xml;charset=utf-8,%3Csvg/)
    expect(img?.getAttribute('alt')).toBe('T1 の注文用 QR コード')
    expect(document.body.innerHTML).not.toContain(SECRET)
    expect(document.body.innerHTML).not.toContain('example.test')
  })

  it('1 件の印刷は印刷用の要素に QR・店舗名・テーブル名だけを出して印刷する', async () => {
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => undefined)
    await mountPage()
    ;[...document.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'QR を表示')?.click()
    await flushPromises()
    button('印刷').click()
    await flushPromises()
    expect(printSpy).toHaveBeenCalledTimes(1)
    expect(document.body.classList.contains('qr-printing')).toBe(true)
    const print = document.querySelector('.qr-print')
    expect(print?.classList.contains('qr-print--single')).toBe(true)
    expect(print?.querySelectorAll('.qr-print__card')).toHaveLength(1)
    expect(print?.textContent).toContain('テスト店 A')
    expect(print?.textContent).toContain('T1')
    expect(print?.textContent).not.toContain('スマホのカメラ')
    expect(document.body.innerHTML).not.toContain(SECRET)

    window.dispatchEvent(new Event('afterprint'))
    await flushPromises()
    expect(document.body.classList.contains('qr-printing')).toBe(false)
    expect(document.querySelector('.qr-print')).toBeNull()
  })

  it('全テーブルの印刷は有効なテーブルだけを 1 枚 6 件ずつ並べる', async () => {
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => undefined)
    api.fetchOrderTables.mockResolvedValue([
      ...Array.from({ length: 7 }, (_, i) => makeTable({ id: i + 1, name: `T${i + 1}` })),
      makeTable({ id: 8, name: '無効', is_active: false }),
    ])
    await mountPage()
    button('全テーブルを印刷').click()
    await flushPromises()
    expect(api.fetchOrderTableQr).toHaveBeenCalledTimes(7)
    expect(api.fetchOrderTableQr).not.toHaveBeenCalledWith(8)
    expect(printSpy).toHaveBeenCalledTimes(1)
    const sheets = document.querySelectorAll('.qr-print__sheet')
    expect(sheets).toHaveLength(2)
    expect(sheets[0]?.querySelectorAll('.qr-print__card')).toHaveLength(6)
    expect(sheets[1]?.querySelectorAll('.qr-print__card')).toHaveLength(1)
    expect(document.querySelector('.qr-print')?.textContent).not.toContain('無効')
  })

  it('QR を作り直す前に確認し、作り直したら印刷し直しを案内する（AC-S16-2）', async () => {
    api.regenerateOrderTableToken.mockResolvedValue(makeTable({ token_rotated_at: '2026-09-30T12:00:00+09:00' }))
    const w = await mountPage()
    await w.findAll('.tbl-row')[0]?.trigger('click')
    button('QR を作り直す').click()
    await flushPromises()
    const dialog = document.querySelector('[role="alertdialog"]')
    expect(dialog?.textContent).toContain('「T1」の QR を作り直しますか')
    expect(dialog?.textContent).toContain('古い QR コードは使えなくなります。印刷し直してください')
    expect(api.regenerateOrderTableToken).not.toHaveBeenCalled()
    button('作り直す').click()
    await flushPromises()
    expect(api.regenerateOrderTableToken).toHaveBeenCalledWith(1)
    expect(document.querySelector('[role="alertdialog"]')).toBeNull()
    expect(w.text()).toContain('「T1」の QR を作り直しました。印刷し直してください')
  })

  it('削除は確認してから行い、未会計の注文があれば（409）ダイアログにその旨を出して残す（AC-S16-4）', async () => {
    api.deleteOrderTable.mockRejectedValueOnce(apiError(409, { message: '未会計の注文があるテーブルは削除できません', code: 'TABLE_HAS_UNPAID_ORDERS' }))
    const w = await mountPage()
    await w.findAll('.tbl-row')[0]?.trigger('click')
    button('削除').click()
    await flushPromises()
    expect(document.querySelector('[role="alertdialog"]')?.textContent).toContain('テーブル「T1」を削除しますか')
    ;[...document.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === '削除')?.click()
    await flushPromises()
    expect(document.querySelector('[role="alertdialog"]')?.textContent).toContain('未会計の注文があるテーブルは削除できません')

    api.deleteOrderTable.mockResolvedValueOnce(undefined)
    ;[...document.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === '削除')?.click()
    await flushPromises()
    expect(api.deleteOrderTable).toHaveBeenCalledWith(1)
    expect(w.findAll('.tbl-row')).toHaveLength(2)
    expect(w.text()).toContain('「T1」を削除しました')
  })

  it('一覧が読めなければ再読み込みを出す', async () => {
    api.fetchOrderTables.mockRejectedValueOnce(apiError(500, { message: 'サーバーでエラーが起きました' }))
    const w = await mountPage()
    expect(w.text()).toContain('サーバーでエラーが起きました')
    button('再読み込み').click()
    await flushPromises()
    expect(w.findAll('.tbl-row')).toHaveLength(3)
  })
})
