import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import ShiftsPage from '@/pages/ShiftsPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { ShiftBoard, ShiftMonth, ShiftRequestKind } from '@/types/api'

const fetchShiftBoard = vi.fn<(month: string) => Promise<ShiftBoard>>()
const fetchMyShiftRequests = vi.fn()
const submitMyShiftRequests = vi.fn()
vi.mock('@/api/shifts', () => ({
  fetchShiftBoard: (m: string) => fetchShiftBoard(m),
  fetchMyShiftRequests: (...a: unknown[]) => fetchMyShiftRequests(...a),
  submitMyShiftRequests: (...a: unknown[]) => submitMyShiftRequests(...a),
  updateShiftMonth: vi.fn(),
  createShift: vi.fn(),
  updateShift: vi.fn(),
  deleteShift: vi.fn(),
}))

const Blank = { template: '<div />' }

function monthInfo(over: Partial<ShiftMonth> = {}): ShiftMonth {
  return { month: '2026-09', request_deadline: null, published_at: null, memo: null, accepting_requests: true, ...over }
}

async function mountStaff(path = '/shifts') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe('staff')
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/shifts', name: 'shifts', component: ShiftsPage },
    ],
  })
  await router.push(path)
  const w = mount(ShiftsPage, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return w
}

describe('S21 勤務表（13 §6.7・§6.8）', () => {
  beforeEach(() => {
    fetchShiftBoard.mockReset()
    fetchMyShiftRequests.mockReset()
    submitMyShiftRequests.mockReset()
  })

  it('staff：公開前の月は「まだ公開されていません」、月の設定は出ない', async () => {
    fetchShiftBoard.mockResolvedValue({ month: monthInfo(), shifts: [], requests: [], members: [] })
    const w = await mountStaff()
    expect(fetchShiftBoard).toHaveBeenCalledWith('2026-09')
    expect(w.text()).toContain('この月の勤務表はまだ公開されていません')
    expect(w.find('#shift-month-heading').exists()).toBe(false)
  })

  it('staff：公開済みなら全員分が見え、自分の予定に印と合計', async () => {
    fetchShiftBoard.mockResolvedValue({
      month: monthInfo({ published_at: '2026-09-01T10:00:00+09:00' }),
      shifts: [
        { id: 1, user_id: 1, date: '2026-09-02', start_time: '10:00', end_time: '15:00', break_minutes: 0, note: null, planned_minutes: 300 },
        { id: 2, user_id: 2, date: '2026-09-02', start_time: '17:00', end_time: '26:00', break_minutes: 60, note: null, planned_minutes: 480 },
      ],
      requests: [],
      members: [
        { id: 1, name: '山田', role: 'staff', is_active: true },
        { id: 2, name: '佐藤', role: 'staff', is_active: true },
      ],
    })
    const w = await mountStaff()
    expect(w.text()).toContain('佐藤')
    expect(w.text()).toContain('（自分）')
    expect(w.text()).toContain('自分の予定の合計 5:00')
  })

  it('［希望を出す］：選んだ日だけ送る。添字のエラーは日付の下に出す', async () => {
    fetchMyShiftRequests.mockResolvedValue({ month: monthInfo(), requests: [] })
    submitMyShiftRequests.mockRejectedValue(apiError(422, { message: 'x', errors: { 'requests.1.end_time': ['終了は開始より後にしてください'] } }))
    const w = await mountStaff('/shifts?tab=requests&month=2026-09')
    expect(w.findAll('.req-day')).toHaveLength(30)

    const pick = async (index: number, kind: ShiftRequestKind) => {
      const label = kind === 'available' ? '出られる' : '出られない'
      await w.findAll('.req-day')[index]?.findAll('.segmented__btn').find((b) => b.text() === label)?.trigger('click')
    }
    await pick(0, 'unavailable')
    await pick(4, 'available')
    await w.find('#req-start-2026-09-05').setValue('1700')
    await w.find('#req-end-2026-09-05').setValue('1600')
    await w.find('form.adm-form').trigger('submit')
    await flushPromises()

    expect(submitMyShiftRequests).toHaveBeenCalledWith('2026-09', [
      { date: '2026-09-01', kind: 'unavailable', start_time: null, end_time: null, note: null },
      { date: '2026-09-05', kind: 'available', start_time: '17:00', end_time: '16:00', note: null },
    ])
    expect(w.findAll('.req-day')[4]?.text()).toContain('終了は開始より後にしてください')
  })

  it('［希望を出す］：締切後は選べず、送るボタンも出ない', async () => {
    fetchMyShiftRequests.mockResolvedValue({ month: monthInfo({ accepting_requests: false, request_deadline: '2026-08-25' }), requests: [] })
    const w = await mountStaff('/shifts?tab=requests&month=2026-09')
    expect(w.text()).toContain('希望の受付は終わりました')
    expect(w.find('button[type="submit"]').exists()).toBe(false)
    expect(w.find('.req-day .segmented__btn').attributes('disabled')).toBeDefined()
  })
})
