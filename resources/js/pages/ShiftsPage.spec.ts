import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import ShiftsPage from '@/pages/ShiftsPage.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError, makeMe } from '@/test/helpers'
import type { Role, Shift, ShiftBoard, ShiftMonth, ShiftPattern, ShiftRequest } from '@/types/api'

const fetchShiftBoard = vi.fn<(month: string) => Promise<ShiftBoard>>()
const fetchMyShiftRequests = vi.fn()
const submitMyShiftRequests = vi.fn()
const fetchShiftPatterns = vi.fn()
const createShiftPattern = vi.fn()
const updateShiftPattern = vi.fn()
const createShift = vi.fn()
vi.mock('@/api/shifts', () => ({
  fetchShiftBoard: (m: string) => fetchShiftBoard(m),
  fetchMyShiftRequests: (...a: unknown[]) => fetchMyShiftRequests(...a),
  submitMyShiftRequests: (...a: unknown[]) => submitMyShiftRequests(...a),
  fetchShiftPatterns: (...a: unknown[]) => fetchShiftPatterns(...a),
  createShiftPattern: (...a: unknown[]) => createShiftPattern(...a),
  updateShiftPattern: (...a: unknown[]) => updateShiftPattern(...a),
  createShift: (...a: unknown[]) => createShift(...a),
  updateShiftMonth: vi.fn(),
  updateShift: vi.fn(),
  deleteShift: vi.fn(),
}))

const Blank = { template: '<div />' }

function monthInfo(over: Partial<ShiftMonth> = {}): ShiftMonth {
  return { month: '2026-09', request_deadline: null, published_at: null, memo: null, accepting_requests: true, ...over }
}

const patternA: ShiftPattern = {
  id: 1, name: 'A', segments: [{ start: '09:00', end: '12:00' }, { start: '13:00', end: '15:00' }], is_active: true,
  start_time: '09:00', end_time: '15:00', break_minutes: 60,
}
const patternB: ShiftPattern = {
  id: 2, name: 'B', segments: [{ start: '09:00', end: '12:00' }], is_active: true,
  start_time: '09:00', end_time: '12:00', break_minutes: 0,
}

function shift(over: Partial<Shift>): Shift {
  return {
    id: 1, user_id: 1, date: '2026-09-02', start_time: '10:00', end_time: '15:00', break_minutes: 0, note: null, planned_minutes: 300,
    pattern_id: null, pattern_name: null, segments: null, ...over,
  }
}

function request(over: Partial<ShiftRequest>): ShiftRequest {
  return { user_id: 1, date: '2026-09-02', kind: 'available', start_time: null, end_time: null, note: null, pattern_id: null, pattern_name: null, segments: null, ...over }
}

async function mountAs(role: Role, path = '/shifts') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().me = makeMe(role)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Blank },
      { path: '/shifts', name: 'shifts', component: ShiftsPage },
    ],
  })
  await router.push(path)
  const w = mount(ShiftsPage, { global: { plugins: [pinia, router], stubs: { teleport: true } } })
  await flushPromises()
  return w
}

const mountStaff = (path?: string) => mountAs('staff', path)

describe('S21 勤務表（13 §6.7〜§6.9）', () => {
  beforeEach(() => {
    for (const f of [fetchShiftBoard, fetchMyShiftRequests, submitMyShiftRequests, fetchShiftPatterns, createShiftPattern, updateShiftPattern, createShift]) f.mockReset()
  })

  it('staff：公開前の月は「まだ公開されていません」、月の設定と［区分］は出ない', async () => {
    fetchShiftBoard.mockResolvedValue({ month: monthInfo(), shifts: [], requests: [], members: [], patterns: [] })
    const w = await mountStaff()
    expect(fetchShiftBoard).toHaveBeenCalledWith('2026-09')
    expect(w.text()).toContain('この月の勤務表はまだ公開されていません')
    expect(w.find('#shift-month-heading').exists()).toBe(false)
    expect(w.findAll('.segmented__btn').map((b) => b.text())).not.toContain('区分')
  })

  it('staff：公開済みなら全員分が見え、自分の予定に印と合計。区分の予定は区分の名前と時間帯', async () => {
    fetchShiftBoard.mockResolvedValue({
      month: monthInfo({ published_at: '2026-09-01T10:00:00+09:00' }),
      shifts: [
        shift({ id: 1, user_id: 1 }),
        shift({ id: 2, user_id: 2, start_time: '09:00', end_time: '15:00', break_minutes: 60, planned_minutes: 300, pattern_id: 1, pattern_name: 'A', segments: patternA.segments }),
      ],
      requests: [],
      members: [
        { id: 1, name: '山田', role: 'staff', is_active: true },
        { id: 2, name: '佐藤', role: 'staff', is_active: true },
      ],
      patterns: [patternA],
    })
    const w = await mountStaff()
    expect(w.text()).toContain('佐藤')
    expect(w.text()).toContain('（自分）')
    expect(w.text()).toContain('自分の予定の合計 5:00')
    expect(w.find('.shift-item__pattern').text()).toBe('A')
    expect(w.text()).toContain('09:00〜12:00・13:00〜15:00')
  })

  it('［希望を出す］：区分・その他（メモ）・出られない を選んで送る。メモのないその他は送らずに止める', async () => {
    fetchMyShiftRequests.mockResolvedValue({ month: monthInfo(), requests: [], patterns: [patternA, patternB] })
    submitMyShiftRequests.mockRejectedValue(apiError(422, { message: 'x', errors: { 'requests.1.pattern_id': ['選んだ区分は使えません。選び直してください'] } }))
    const w = await mountStaff('/shifts?tab=requests&month=2026-09')
    expect(w.findAll('.req-day')).toHaveLength(30)
    expect(w.find('.req-legend').text()).toContain('09:00〜12:00・13:00〜15:00')

    const pick = async (index: number, label: string) => {
      await w.findAll('.req-day')[index]?.findAll('.segmented__btn').find((b) => b.text() === label)?.trigger('click')
    }
    await pick(0, '出られない')
    await pick(2, 'A')
    await pick(4, 'その他（メモ）')
    expect(w.findAll('.req-day')[2]?.find('.req-day__segments').text()).toBe('09:00〜12:00・13:00〜15:00')

    await w.find('form.adm-form').trigger('submit')
    await flushPromises()
    expect(submitMyShiftRequests).not.toHaveBeenCalled()
    expect(w.findAll('.req-day')[4]?.text()).toContain('メモを書いてください')

    await w.find('#req-note-2026-09-05').setValue(' 夕方なら ')
    await w.find('form.adm-form').trigger('submit')
    await flushPromises()
    expect(submitMyShiftRequests).toHaveBeenCalledWith('2026-09', [
      { date: '2026-09-01', kind: 'unavailable', pattern_id: null, note: null },
      { date: '2026-09-03', kind: 'available', pattern_id: 1, note: null },
      { date: '2026-09-05', kind: 'available', pattern_id: null, note: '夕方なら' },
    ])
    expect(w.findAll('.req-day')[2]?.text()).toContain('選んだ区分は使えません')
  })

  it('［希望を出す］：使わなくなった区分で出した日はその区分のまま、区分の前に時間で出した日はメモに時間を入れて開く', async () => {
    fetchMyShiftRequests.mockResolvedValue({
      month: monthInfo(),
      requests: [
        request({ date: '2026-09-01', pattern_id: 9, pattern_name: '旧C', segments: [{ start: '18:00', end: '22:00' }], start_time: '18:00', end_time: '22:00' }),
        request({ date: '2026-09-02', start_time: '10:00', end_time: '15:00' }),
      ],
      patterns: [patternA],
    })
    const w = await mountStaff('/shifts?tab=requests&month=2026-09')
    const day1 = w.findAll('.req-day')[0]
    expect(day1?.find('.segmented__btn--on').text()).toBe('旧C')
    expect(day1?.find('.req-day__segments').text()).toBe('18:00〜22:00')
    expect(w.findAll('.req-day')[1]?.find('.segmented__btn--on').text()).toBe('その他（メモ）')
    expect((w.find('#req-note-2026-09-02').element as HTMLInputElement).value).toBe('10:00〜15:00')
    // 他の日には使わない区分は出ない
    expect(w.findAll('.req-day')[2]?.findAll('.segmented__btn').map((b) => b.text())).not.toContain('旧C')
  })

  it('［希望を出す］：締切後は選べず、送るボタンも出ない', async () => {
    fetchMyShiftRequests.mockResolvedValue({ month: monthInfo({ accepting_requests: false, request_deadline: '2026-08-25' }), requests: [], patterns: [] })
    const w = await mountStaff('/shifts?tab=requests&month=2026-09')
    expect(w.text()).toContain('希望の受付は終わりました')
    expect(w.find('button[type="submit"]').exists()).toBe(false)
    expect(w.find('.req-day .segmented__btn').attributes('disabled')).toBeDefined()
  })

  it('owner［区分］：一覧（使わない区分に印）と追加。時間帯を足して保存し、422 は欄の下に出す', async () => {
    fetchShiftBoard.mockResolvedValue({ month: monthInfo(), shifts: [], requests: [], members: [], patterns: [patternA] })
    fetchShiftPatterns.mockResolvedValue([patternA, { ...patternB, is_active: false }])
    createShiftPattern.mockRejectedValueOnce(apiError(422, { message: 'x', errors: { name: ['この区分の名前はすでに使われています'] } }))
    createShiftPattern.mockResolvedValueOnce({ ...patternA, id: 3, name: 'C' })
    const w = await mountAs('owner', '/shifts?tab=patterns&month=2026-09')
    const items = w.findAll('.pattern-item')
    expect(items).toHaveLength(2)
    expect(items[0]?.text()).toContain('休憩 1:00')
    expect(items[1]?.text()).toContain('使わない')

    await w.findAll('button').find((b) => b.text() === '区分を追加')?.trigger('click')
    await w.find('#pattern-name').setValue('C')
    await w.find('#pattern-start-0').setValue('900')
    await w.find('#pattern-end-0').setValue('1200')
    await w.findAll('button').find((b) => b.text() === '時間帯を足す')?.trigger('click')
    await w.find('#pattern-start-1').setValue('13:00')
    await w.find('#pattern-end-1').setValue('15:00')
    await w.find('form[aria-label="区分を追加"]').trigger('submit')
    await flushPromises()
    expect(createShiftPattern).toHaveBeenCalledWith({
      name: 'C', segments: [{ start: '09:00', end: '12:00' }, { start: '13:00', end: '15:00' }], is_active: true,
    })
    expect(w.text()).toContain('この区分の名前はすでに使われています')

    await w.find('form[aria-label="区分を追加"]').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('区分を保存しました')
    expect(fetchShiftBoard).toHaveBeenCalledTimes(2)
  })

  it('owner 勤務表：区分で出た希望を押すと、その人と区分で予定の追加が開く。時刻を直すと区分は外れる', async () => {
    fetchShiftBoard.mockResolvedValue({
      month: monthInfo(),
      shifts: [],
      requests: [request({ user_id: 2, date: '2026-09-03', pattern_id: 1, pattern_name: 'A', segments: patternA.segments, start_time: '09:00', end_time: '15:00' })],
      members: [
        { id: 1, name: '山田', role: 'owner', is_active: true },
        { id: 2, name: '佐藤', role: 'staff', is_active: true },
      ],
      patterns: [patternA, patternB],
    })
    createShift.mockResolvedValue(shift({}))
    const w = await mountAs('owner', '/shifts?month=2026-09')
    const chip = w.find('.shift-request__btn')
    expect(chip.attributes('aria-label')).toBe('佐藤さんの希望（A 09:00〜12:00・13:00〜15:00）で予定を追加')
    await chip.trigger('click')

    expect((w.find('#shift-user').element as HTMLSelectElement).value).toBe('2')
    expect((w.find('#shift-start').element as HTMLInputElement).value).toBe('09:00')
    expect((w.find('#shift-break').element as HTMLInputElement).value).toBe('60')
    const form = w.find('form[aria-label="予定を追加"]')
    await form.trigger('submit')
    await flushPromises()
    expect(createShift).toHaveBeenLastCalledWith(expect.objectContaining({ user_id: 2, date: '2026-09-03', pattern_id: 1 }))

    await chip.trigger('click')
    await w.find('#shift-end').setValue('16:00')
    await w.find('form[aria-label="予定を追加"]').trigger('submit')
    await flushPromises()
    expect(createShift).toHaveBeenLastCalledWith(expect.objectContaining({ start_time: '09:00', end_time: '16:00', break_minutes: 60, pattern_id: null }))
  })
})
