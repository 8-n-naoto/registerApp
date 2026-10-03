import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PrinterSettingsPanel from '@/components/settings/PrinterSettingsPanel.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { PrinterSettings } from '@/types/api'

// 15 §8.4 S09「レシートプリンター」

const api = vi.hoisted(() => ({ updatePrinterSettings: vi.fn() }))
vi.mock('@/api/settings', () => api)
const receipt = vi.hoisted(() => ({ printSale: vi.fn(), printTest: vi.fn() }))
vi.mock('@/lib/receipt', () => receipt)

const PRINTER: PrinterSettings = { host: '192.168.1.50', paper_width: 58 }

function mountPanel(printer: PrinterSettings | null = null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const me = makeMe('owner')
  useAuthStore().me = me.store ? { ...me, store: { ...me.store, printer } } : me
  return mount(PrinterSettingsPanel, { global: { plugins: [pinia] }, attachTo: document.body })
}

describe('S09 レシートプリンター', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    api.updatePrinterSettings.mockReset()
    receipt.printTest.mockReset()
  })

  it('未設定はテスト印刷を出さず、保存すると宛先を空白なしで送り店舗の設定を差し替える', async () => {
    const w = mountPanel()
    expect(w.find('[data-test-print]').exists()).toBe(false)
    expect(w.text()).toContain('設定されていません')
    const me = useAuthStore().me
    api.updatePrinterSettings.mockResolvedValue({ ...me?.store, printer: PRINTER })
    await w.find('#printer-host').setValue(' 192.168.1.50 ')
    await w.findAll('[role="radio"]').find((b) => b.text().includes('58'))?.trigger('click')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(api.updatePrinterSettings).toHaveBeenCalledWith({ host: '192.168.1.50', paper_width: 58 })
    expect(useAuthStore().me?.store?.printer).toEqual(PRINTER)
    expect(w.text()).toContain('プリンターの設定を保存しました')
    expect(w.find('[data-test-print]').exists()).toBe(true)
  })

  it('空にして保存すると null を送る。422 は欄の下に出す', async () => {
    const w = mountPanel(PRINTER)
    expect((w.find('#printer-host').element as HTMLInputElement).value).toBe('192.168.1.50')
    api.updatePrinterSettings.mockRejectedValue(apiError(422, { message: 'x', errors: { host: ['宛先の形が正しくありません'] } }))
    await w.find('#printer-host').setValue('  ')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updatePrinterSettings).toHaveBeenCalledWith({ host: null, paper_width: 58 })
    expect(w.text()).toContain('宛先の形が正しくありません')
    expect(w.find('#printer-host').attributes('aria-invalid')).toBe('true')
  })

  it('テスト印刷は保存済みの設定と店舗名で送り、結果を出す。準備の手順はプリンターの画面へのリンク付き', async () => {
    const w = mountPanel(PRINTER)
    receipt.printTest.mockResolvedValue({ ok: false, reason: 'cover_open' })
    await w.find('[data-test-print]').trigger('click')
    await flushPromises()
    expect(receipt.printTest).toHaveBeenCalledWith(PRINTER, 'テスト店 A')
    expect(w.find('[role="alert"]').text()).not.toBe('')

    receipt.printTest.mockResolvedValue({ ok: true })
    await w.find('[data-test-print]').trigger('click')
    await flushPromises()
    expect(w.find('[role="status"]').exists()).toBe(true)

    const prepare = w.find('[aria-controls="printer-prepare"]')
    await prepare.trigger('click')
    expect(prepare.attributes('aria-expanded')).toBe('true')
    expect(w.find('#printer-prepare a').attributes('href')).toBe('https://192.168.1.50/')
  })
})
