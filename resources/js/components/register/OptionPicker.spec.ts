import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import OptionPicker from '@/components/register/OptionPicker.vue'
import { makeProduct } from '@/test/register'
import type { Product } from '@/types/api'

// 味噌煮込みうどん ¥830：麺の固さ（1つ選ぶ、普通が最初）、追加（いくつでも）
const udon: Product = makeProduct(5, '味噌煮込みうどん', {
  price: 830,
  option_groups: [
    { id: 1, product_id: 5, name: '麺の固さ', selection: 'single', sort_order: 0 },
    { id: 2, product_id: 5, name: '追加', selection: 'multi', sort_order: 1 },
  ],
  options: [
    { id: 21, product_id: 5, name: '麺大盛り', price: 150, sort_order: 0, is_active: true, group_id: 2, is_default: false },
    { id: 11, product_id: 5, name: '固め', price: 0, sort_order: 1, is_active: true, group_id: 1, is_default: false },
    { id: 12, product_id: 5, name: '普通', price: 0, sort_order: 2, is_active: true, group_id: 1, is_default: true },
    { id: 13, product_id: 5, name: '柔らかめ', price: 0, sort_order: 3, is_active: true, group_id: 1, is_default: false },
    { id: 22, product_id: 5, name: 'ねぎ多め', price: 50, sort_order: 4, is_active: true, group_id: 2, is_default: false },
  ],
})

let wrapper: VueWrapper | null = null
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

async function open(product: Product): Promise<VueWrapper> {
  wrapper = mount(OptionPicker, { props: { product: null }, attachTo: document.body })
  await wrapper.setProps({ product })
  await nextTick()
  return wrapper
}

function buttons(selector: string): HTMLButtonElement[] {
  return [...document.querySelectorAll<HTMLButtonElement>(selector)]
}
function byText(label: string): HTMLButtonElement {
  const found = buttons('button').find((b) => b.textContent?.trim().startsWith(label))
  if (!found) throw new Error(label)
  return found
}
async function click(el: HTMLElement): Promise<void> {
  el.click()
  await nextTick()
}

describe('OptionPicker（docs/10「オプションのグループ」）', () => {
  it('「1つ選ぶ」は横並びで最初に選ぶを選んだ状態、「いくつでも」は縦の並び、下に 1 品の金額', async () => {
    const w = await open(udon)
    expect(buttons('.seg [role="radio"]').map((b) => [b.textContent?.trim(), b.getAttribute('aria-checked')])).toEqual([
      ['固め＋¥0', 'false'], ['普通＋¥0', 'true'], ['柔らかめ＋¥0', 'false'],
    ])
    expect(buttons('.opt').map((b) => b.textContent?.replace(/\s+/g, ''))).toEqual(['麺大盛り＋¥150', 'ねぎ多め＋¥50'])
    expect(document.querySelectorAll('.divider')).toHaveLength(1)
    expect(document.querySelector('[data-unit-price]')?.textContent).toBe('¥830')

    await click(byText('柔らかめ'))
    await click(byText('麺大盛り'))
    await click(byText('ねぎ多め'))
    await click(byText('ねぎ多め'))
    expect(byText('普通').getAttribute('aria-checked')).toBe('false')
    expect(document.querySelector('[data-unit-price]')?.textContent).toBe('¥980')

    await click(byText('追加'))
    expect(w.emitted('add')?.[0]).toEqual([[13, 21]])
  })

  it('「最初に選ぶ」が無い「1つ選ぶ」グループは選ぶまで追加できない', async () => {
    const w = await open({ ...udon, options: udon.options.map((o) => ({ ...o, is_default: false })) })
    expect(buttons('.seg [aria-checked="true"]')).toHaveLength(0)
    await click(byText('追加'))
    expect(w.emitted('add')).toBeUndefined()
    expect(document.body.textContent).toContain('「麺の固さ」を選んでください')
    await click(byText('固め'))
    await click(byText('追加'))
    expect(w.emitted('add')?.[0]).toEqual([[11]])
  })

  it('グループのない商品は今までどおり縦の並びで、選ばずに追加できる', async () => {
    const latte = makeProduct(3, 'ラテ', {
      price: 500,
      options: [{ id: 31, product_id: 3, name: 'ショット', price: 50, sort_order: 1, is_active: true, group_id: null, is_default: false }],
    })
    const w = await open(latte)
    expect(document.querySelector('.seg')).toBeNull()
    expect(document.querySelector('.group__head')).toBeNull()
    expect(document.body.textContent).toContain('選ばずに追加もできます')
    await click(byText('追加'))
    expect(w.emitted('add')?.[0]).toEqual([[]])
  })
})
