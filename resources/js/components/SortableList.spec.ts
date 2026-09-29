import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it } from 'vitest'
import { h } from 'vue'
import SortableList from '@/components/SortableList.vue'

type Item = { id: number; name: string }
const items: Item[] = [
  { id: 1, name: 'A' },
  { id: 2, name: 'B' },
  { id: 3, name: 'C' },
]

function mountList(layout: 'list' | 'grid' = 'list') {
  return mount(SortableList<Item>, {
    props: { items, layout, label: '並び', handleLabel: (i: Item) => `${i.name} を移動` },
    slots: { default: ({ item }: { item: Item }) => h('span', item.name) },
  })
}

/** jsdom には PointerEvent が無いので MouseEvent に pointerId を足して送る */
function pointer(el: Element, type: string, clientX: number, clientY: number): void {
  const ev = new MouseEvent(type, { button: 0, clientX, clientY, bubbles: true, cancelable: true })
  Object.defineProperty(ev, 'pointerId', { value: 1 })
  el.dispatchEvent(ev)
}

describe('SortableList（08 §6）', () => {
  beforeAll(() => {
    Element.prototype.setPointerCapture = () => {}
  })

  it('矢印キーで 1 つずつ動かす。端では動かない', async () => {
    const w = mountList()
    const grips = w.findAll('[data-sort-grip]')
    expect(grips[0]?.attributes('aria-label')).toBe('A を移動')
    await grips[0]?.trigger('keydown', { key: 'ArrowDown' })
    expect(w.emitted('update:items')?.[0]?.[0]).toEqual([items[1], items[0], items[2]])
    await grips[0]?.trigger('keydown', { key: 'ArrowUp' })
    expect(w.emitted('update:items')).toHaveLength(1)
  })

  it('つまんで指の下の項目の位置へ入れ替える（grid は項目全体がつまみ）', async () => {
    const w = mountList('grid')
    const lis = w.findAll('[data-sort-index]')
    lis.forEach((li, i) => {
      li.element.getBoundingClientRect = () => ({
        left: i * 100, right: i * 100 + 99, top: 200, bottom: 299, x: i * 100, y: 200, width: 99, height: 99, toJSON: () => ({}),
      })
    })
    const grip = w.findAll('[data-sort-grip]')[0]?.element
    if (!grip) throw new Error('no grip')
    pointer(grip, 'pointerdown', 0, 0)
    pointer(grip, 'pointermove', 250, 250)
    await w.vm.$nextTick()
    expect(w.emitted('update:items')?.[0]?.[0]).toEqual([items[1], items[2], items[0]])
    pointer(grip, 'pointerup', 250, 250)
    pointer(grip, 'pointermove', 50, 250)
    expect(w.emitted('update:items')).toHaveLength(1)
  })
})
