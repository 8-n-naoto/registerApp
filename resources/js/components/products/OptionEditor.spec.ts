import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OptionEditor from '@/components/products/OptionEditor.vue'
import type { ProductOption, ProductOptionGroup } from '@/types/api'

const api = vi.hoisted(() => ({
  createOption: vi.fn(),
  updateOption: vi.fn(),
  deleteOption: vi.fn(),
  reorderOptions: vi.fn(),
  createOptionGroup: vi.fn(),
  updateOptionGroup: vi.fn(),
  deleteOptionGroup: vi.fn(),
}))
vi.mock('@/api/catalog', () => api)

function option(id: number, name: string, group_id: number | null, is_default = false): ProductOption {
  return { id, product_id: 5, name, price: 0, sort_order: id, is_active: true, group_id, is_default }
}
const size: ProductOptionGroup = { id: 1, product_id: 5, name: '麺の固さ', selection: 'single', sort_order: 0 }

let wrapper: VueWrapper | null = null
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})
beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  Element.prototype.scrollIntoView = () => {}
})

function mountEditor(options: ProductOption[], groups: ProductOptionGroup[]): VueWrapper {
  wrapper = mount(OptionEditor, {
    props: {
      productId: 5,
      options,
      groups,
      'onUpdate:options': (next: ProductOption[]) => wrapper?.setProps({ options: next }),
      'onUpdate:groups': (next: ProductOptionGroup[]) => wrapper?.setProps({ groups: next }),
    },
    attachTo: document.body,
  })
  return wrapper
}

function button(w: VueWrapper, label: string) {
  const found = w.findAll('button').find((b) => b.text() === label)
  if (!found) throw new Error(label)
  return found
}

describe('OptionEditor のグループ（docs/10「オプションのグループ」）', () => {
  it('グループごとの枠に並べ、最初に選ぶの印を付け、グループなしは最後', () => {
    const w = mountEditor([option(13, 'チーズ', null), option(11, '固め', 1), option(12, '普通', 1, true)], [size])
    const box = w.get('[data-group="1"]')
    expect((box.get('input').element as HTMLInputElement).value).toBe('麺の固さ')
    expect((box.get('select').element as HTMLSelectElement).value).toBe('single')
    expect(box.findAll('.adm-row__main').map((r) => r.text())).toEqual(['固め', '普通最初に選ぶ'])
    expect(w.text()).toContain('グループなし')
    expect(w.text()).toContain('チーズ')
  })

  it('グループを追加し、3 つで追加できなくなる', async () => {
    api.createOptionGroup.mockResolvedValue({ id: 3, product_id: 5, name: 'トッピング', selection: 'multi', sort_order: 2 })
    const w = mountEditor([], [size, { ...size, id: 2, name: '温度' }])
    await button(w, '＋グループを追加').trigger('click')
    await w.get('#group-name').setValue('トッピング')
    await w.get('#group-selection').setValue('multi')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.createOptionGroup).toHaveBeenCalledWith(5, { name: 'トッピング', selection: 'multi' })
    expect(w.findAll('[data-group]')).toHaveLength(3)
    expect(button(w, '＋グループを追加').attributes('disabled')).toBeDefined()
  })

  it('グループの中にオプションを追加し、最初に選ぶを付け替えると前のものは外れる', async () => {
    api.createOption.mockResolvedValue(option(14, '柔らかめ', 1, true))
    const w = mountEditor([option(11, '固め', 1), option(12, '普通', 1, true)], [size])
    await button(w, '＋オプション').trigger('click')
    expect((w.get('#option-group').element as HTMLSelectElement).value).toBe('1')
    await w.get('#option-name').setValue('柔らかめ')
    await w.find('form input[type="checkbox"]').setValue(true)
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.createOption).toHaveBeenCalledWith(5, { name: '柔らかめ', price: 0, is_active: true, group_id: 1, is_default: true })
    expect(w.get('[data-group="1"]').findAll('.adm-row__main').map((r) => r.text())).toEqual(['固め', '普通', '柔らかめ最初に選ぶ'])
  })

  it('選び方を「いくつでも」に変えるとすぐ保存し、最初に選ぶの印を外す', async () => {
    api.updateOptionGroup.mockResolvedValue({ ...size, selection: 'multi' })
    const w = mountEditor([option(12, '普通', 1, true)], [size])
    await w.get('[data-group="1"] select').setValue('multi')
    await flushPromises()
    expect(api.updateOptionGroup).toHaveBeenCalledWith(1, { name: '麺の固さ', selection: 'multi' })
    expect(w.get('[data-group="1"] .adm-row__main').text()).toBe('普通')
  })

  it('グループを削除すると中のオプションも消える', async () => {
    api.deleteOptionGroup.mockResolvedValue(undefined)
    const w = mountEditor([option(12, '普通', 1, true), option(13, 'チーズ', null)], [size])
    await w.get('[aria-label="グループ「麺の固さ」を削除"]').trigger('click')
    expect(document.body.textContent).toContain('中のオプション（1 件）も削除します')
    const confirm = [...document.querySelectorAll<HTMLButtonElement>('.dialog button')].find((b) => b.textContent?.trim() === '削除')
    confirm?.click()
    await flushPromises()
    expect(api.deleteOptionGroup).toHaveBeenCalledWith(1)
    expect(w.find('[data-group]').exists()).toBe(false)
    expect(w.text()).not.toContain('普通')
    expect(w.text()).toContain('チーズ')
  })
})
