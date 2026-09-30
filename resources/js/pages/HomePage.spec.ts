import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import HomePage from '@/pages/HomePage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import type { Role } from '@/types/api'

function mountAs(role: Role) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: HomePage },
      { path: '/account', name: 'account', component: HomePage },
      { path: '/login', name: 'login', component: HomePage },
      { path: '/:p(.*)*', component: HomePage },
    ],
  })
  return mount(HomePage, { global: { plugins: [pinia, router] } })
}

describe('S00 ホーム（08 §5.2）', () => {
  it('owner はカード 5 枚とボタン 14 個', () => {
    const w = mountAs('owner')
    expect(w.findAll('.home-card__title').map((e) => e.text())).toEqual(['レジ操作', '注文', '商品管理', '売上管理', '設定'])
    expect(w.findAll('.home-card__btn')).toHaveLength(14)
  })

  it('注文のカードに［会計］があり、S02 を開く（owner / staff とも）', () => {
    for (const role of ['owner', 'staff'] as const) {
      const card = mountAs(role).findAll('.home-card').find((c) => c.find('.home-card__title').text() === '注文')
      const btns = card?.findAll('.home-card__btn') ?? []
      expect(btns.map((b) => b.text())).toEqual(['注文を受ける', '会計', '厨房', '注文確認'])
      expect(btns[1]?.attributes('href')).toBe('/register')
    }
  })

  it('AC-S00-2：staff はレジ操作・注文・売上管理の 3 枚で、売上管理は売上確認・レジ締めのみ', () => {
    const w = mountAs('staff')
    const cards = w.findAll('.home-card')
    expect(cards.map((c) => c.find('.home-card__title').text())).toEqual(['レジ操作', '注文', '売上管理'])
    expect(cards[2]?.findAll('.home-card__btn').map((b) => b.text())).toEqual(['売上確認', 'レジ締め'])
  })

  it('AC-S00-6：店舗名と営業日を出す', () => {
    const text = mountAs('owner').find('.home__store').text()
    expect(text).toContain('テスト店 A')
    expect(text).toContain('9/29（火）')
  })
})
