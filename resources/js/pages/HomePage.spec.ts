import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
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
      { path: '/attendance', name: 'attendance', component: HomePage },
      { path: '/:p(.*)*', component: HomePage },
    ],
  })
  return mount(HomePage, { global: { plugins: [pinia, router] } })
}

describe('S00 ホーム（08 §5.2）', () => {
  beforeEach(() => {
    fetchMe.mockReset()
  })

  it('owner はカード 6 枚とボタン 16 個', () => {
    const w = mountAs('owner')
    expect(w.findAll('.home-card__title').map((e) => e.text())).toEqual(['レジ操作', '注文', '商品管理', '売上管理', '勤怠', '設定'])
    expect(w.findAll('.home-card__btn')).toHaveLength(16)
  })

  it('勤怠のカードは勤怠・勤務表（owner / staff とも）', () => {
    for (const role of ['owner', 'staff'] as const) {
      const card = mountAs(role).findAll('.home-card').find((c) => c.find('.home-card__title').text() === '勤怠')
      expect(card?.findAll('.home-card__btn').map((b) => b.text())).toEqual(['勤怠', '勤務表'])
    }
  })

  it('注文のカードに［会計］があり、S02 を開く（owner / staff とも）', () => {
    for (const role of ['owner', 'staff'] as const) {
      const card = mountAs(role).findAll('.home-card').find((c) => c.find('.home-card__title').text() === '注文')
      const btns = card?.findAll('.home-card__btn') ?? []
      expect(btns.map((b) => b.text())).toEqual(['注文を受ける', '会計', '厨房', '注文確認'])
      expect(btns[1]?.attributes('href')).toBe('/register')
    }
  })

  it('AC-S00-2：staff はレジ操作・注文・売上管理・勤怠の 4 枚で、売上管理は売上確認・レジ締めのみ', () => {
    const w = mountAs('staff')
    const cards = w.findAll('.home-card')
    expect(cards.map((c) => c.find('.home-card__title').text())).toEqual(['レジ操作', '注文', '売上管理', '勤怠'])
    expect(cards[2]?.findAll('.home-card__btn').map((b) => b.text())).toEqual(['売上確認', 'レジ締め'])
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
