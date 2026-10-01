import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import KitchenCard from '@/components/orders/KitchenCard.vue'
import { makeOrder, makeOrderItem } from '@/test/orders'

describe('KitchenCard のオプション（docs/10「オプションのグループ」）', () => {
  it('「最初に選ぶ」のままは出さず、「1つ選ぶ」の選択を目立たせ、最初の選択だけなら（オプションなし）', () => {
    const order = makeOrder({
      items: [
        makeOrderItem({
          id: 1,
          product_name: '味噌煮込みうどん',
          options: [
            { product_option_id: 11, option_name: '固め', price: 0, is_default: false, is_choice: true },
            { product_option_id: 21, option_name: '麺大盛り', price: 150, is_default: false, is_choice: false },
          ],
        }),
        makeOrderItem({
          id: 2,
          product_name: '味噌煮込みうどん',
          options: [{ product_option_id: 12, option_name: '普通', price: 0, is_default: true, is_choice: true }],
        }),
        makeOrderItem({ id: 3, product_name: 'ケーキ' }),
      ],
    })
    const w = mount(KitchenCard, { props: { order, now: Date.now(), busy: false, highlighted: false } })
    const first = w.get('[data-item="1"] [data-options]')
    expect(first.text()).toBe('固め・麺大盛り')
    expect(first.findAll('em').map((e) => e.text())).toEqual(['固め'])
    expect(w.get('[data-item="2"] [data-options]').text()).toBe('（オプションなし）')
    expect(w.find('[data-item="3"] [data-options]').exists()).toBe(false)
  })
})
