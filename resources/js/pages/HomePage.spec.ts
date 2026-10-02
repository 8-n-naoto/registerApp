import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { NAV_GROUPS } from '@/lib/adminNav'
import HomePage from '@/pages/HomePage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import type { Me, Role } from '@/types/api'

// 担当者の欄（OperatorBar）が開いたときに GET /me で勤怠の状態を読み直す
const fetchMe = vi.fn<() => Promise<Me>>()
vi.mock('@/api/auth', () => ({ fetchMe: () => fetchMe(), login: vi.fn(), logout: vi.fn(), updatePassword: vi.fn() }))
vi.mock('@/api/attendance', () => ({ fetchOperators: vi.fn(() => Promise.resolve([])), startBreak: vi.fn(), endBreak: vi.fn() }))

function mountAs(role: Role, me: Me = makeMe(role)) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = me
  fetchMe.mockResolvedValue(me)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: HomePage },
      { path: '/account', name: 'account', component: HomePage },
      { path: '/login', name: 'login', component: HomePage },
      { path: '/clock-in', name: 'clock-in', component: HomePage },
      { path: '/shifts', name: 'shifts', component: HomePage },
      { path: '/manage', name: 'manage', component: HomePage },
      ...NAV_GROUPS.flatMap((g) => g.pages).map((p) => ({ path: `/nav/${p.name}`, name: p.name, component: HomePage })),
      { path: '/:p(.*)*', component: HomePage },
    ],
  })
  return mount(HomePage, { global: { plugins: [pinia, router] } })
}

describe('S00 ホーム（08 §5.2）', () => {
  beforeEach(() => {
    fetchMe.mockReset()
  })

  it('owner は管理のカード 5 枚（レールと同じ順）とボタン 11 個', () => {
    const w = mountAs('owner')
    expect(w.findAll('.home-card__title').map((e) => e.text())).toEqual(['売上', '商品', 'スタッフ', '店舗設定', '記録'])
    expect(w.findAll('.home-card__btn')).toHaveLength(11)
  })

  it('スタッフのカードは勤怠・勤務表（owner / staff とも）', () => {
    for (const role of ['owner', 'staff'] as const) {
      const card = mountAs(role).findAll('.home-card').find((c) => c.find('.home-card__title').text() === 'スタッフ')
      const labels = card?.findAll('.home-card__btn').map((b) => b.text()) ?? []
      expect(labels.slice(0, 2)).toEqual(['勤怠', '勤務表'])
    }
  })

  it('お店の操作：レジ・注文入力・注文確認・厨房（owner / staff とも）', () => {
    for (const role of ['owner', 'staff'] as const) {
      const btns = mountAs(role).findAll('.home-shop__grid .h-btn:not(.home-shop__extra)')
      expect(btns.map((b) => b.text())).toEqual(['レジ', '注文入力', '注文確認', '厨房'])
      expect(btns.map((b) => b.attributes('href'))).toEqual(['/register?view=order', '/orders/new', '/orders', '/kitchen'])
    }
  })

  it('AC-S00-2：staff は管理のカードが売上・スタッフの 2 枚で、売上は日次売上・レジ締めのみ', () => {
    const w = mountAs('staff')
    const cards = w.findAll('.home-card')
    expect(cards.map((c) => c.find('.home-card__title').text())).toEqual(['売上', 'スタッフ'])
    expect(cards[0]?.findAll('.home-card__btn').map((b) => b.text())).toEqual(['日次売上', 'レジ締め'])
    expect(cards[1]?.findAll('.home-card__btn').map((b) => b.text())).toEqual(['勤怠', '勤務表'])
  })

  it('AC-S00-6：店舗名と営業日を出す', () => {
    const text = mountAs('owner').find('.home__store').text()
    expect(text).toContain('テスト店 A')
    expect(text).toContain('9/29（火）')
  })

  it('13 §7：担当者の欄に名前と勤務中の時刻、休憩・切替のボタン', async () => {
    const w = mountAs('staff')
    await flushPromises()
    const bar = w.find('.op-bar')
    expect(bar.text()).toContain('山田')
    expect(bar.text()).toContain('09:02')
    expect(bar.findAll('.op-bar__btn').map((b) => b.text())).toEqual(['休憩開始', '担当者を切替'])
  })

  it('13 §6.6：労働条件が未入力なら owner にだけ警告を出す', () => {
    const owner = mountAs('owner', { ...makeMe('owner'), labor_warnings: ['minimum_wage', 'hourly_wage'] })
    expect(owner.find('.labor-warn').exists()).toBe(true)
    expect(owner.findAll('.labor-warn__items li')).toHaveLength(2)
    expect(mountAs('staff', { ...makeMe('staff'), labor_warnings: ['minimum_wage'] }).find('.labor-warn').exists()).toBe(false)
    expect(mountAs('owner').find('.labor-warn').exists()).toBe(false)
  })
})
