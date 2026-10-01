import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import AttendancePage from '@/pages/AttendancePage.vue'
import { useAuthStore } from '@/stores/auth'
import { makeMe } from '@/test/helpers'
import type { Attendance, AttendanceList, Role } from '@/types/api'

const fetchAttendances = vi.fn<(month: string) => Promise<AttendanceList>>()
const fetchAttendanceSummary = vi.fn()
vi.mock('@/api/attendance', () => ({
  fetchAttendances: (month: string) => fetchAttendances(month),
  fetchAttendanceSummary: () => fetchAttendanceSummary(),
  attendanceExportUrl: (m: string) => `export-${m}`,
  fetchLaborMembers: vi.fn().mockResolvedValue([]),
  fetchLaborSettings: vi.fn(),
}))

const Blank = { template: '<div />' }
let router: Router

function att(over: Partial<Attendance>): Attendance {
  return {
    id: 1, user_id: 1, user_name: '山田', business_date: '2026-09-28',
    clock_in_at: '2026-09-28T10:00:00+09:00', clock_out_at: '2026-09-28T15:30:00+09:00',
    breaks: [], break_minutes: 30, work_minutes: 300, status: 'closed', edited: false, ...over,
  }
}

async function mountAs(role: Role, path = '/attendance') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/attendance', name: 'attendance', component: AttendancePage },
    ],
  })
  await router.push(path)
  const w = mount(AttendancePage, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return w
}

describe('S20 勤怠（13 §6.3〜§6.6）', () => {
  beforeEach(() => {
    fetchAttendances.mockReset()
    fetchAttendanceSummary.mockReset()
  })

  it('staff は本人の打刻だけ。既定の月は今の営業日の月、合計は日数と時間', async () => {
    fetchAttendances.mockResolvedValue({
      month: '2026-09',
      attendances: [
        att({ id: 1 }),
        att({ id: 2, business_date: '2026-09-29', clock_in_at: '2026-09-29T09:02:00+09:00', clock_out_at: null, work_minutes: null, status: 'working' }),
      ],
    })
    const w = await mountAs('staff')
    expect(fetchAttendances).toHaveBeenCalledWith('2026-09')
    expect(fetchAttendanceSummary).not.toHaveBeenCalled()
    expect(w.find('[role="radiogroup"]').exists()).toBe(false)
    expect(w.find('#att-mine-heading').text()).toBe('出勤 2 日・勤務 5:00')
    expect(w.text()).not.toContain('時給')
  })

  it('staff：月を進めると読み直し、URL に月が入る', async () => {
    fetchAttendances.mockResolvedValue({ month: '2026-09', attendances: [] })
    const w = await mountAs('staff')
    expect(w.text()).toContain('この月の打刻はありません')
    await w.findAll('.month-nav button').find((b) => b.text().includes('次の月'))?.trigger('click')
    await flushPromises()
    expect(fetchAttendances).toHaveBeenLastCalledWith('2026-10')
    expect(router.currentRoute.value.query.month).toBe('2026-10')
  })

  it('owner は［集計］［打刻］［労働条件］のタブ。?month= の月で集計を読む', async () => {
    fetchAttendanceSummary.mockResolvedValue({
      month: '2026-08',
      settings: { weekly_hours_limit: null, week_start_day: null, legal_holiday_day: null, minimum_wage: null },
      warnings: [],
      rows: [],
      totals: { days: 0, work_minutes: 0, overtime_minutes: 0, night_minutes: 0, holiday_minutes: 0, scheduled_minutes: 0, base_pay: 0, premium_pay: 0, total_pay: 0 },
    })
    const w = await mountAs('owner', '/attendance?month=2026-08')
    expect(w.findAll('.segmented__btn').map((b) => b.text())).toEqual(['集計', '打刻', '労働条件'])
    expect(fetchAttendanceSummary).toHaveBeenCalled()
    expect(fetchAttendances).not.toHaveBeenCalled()
    expect(w.text()).toContain('2026年8月')
  })
})
