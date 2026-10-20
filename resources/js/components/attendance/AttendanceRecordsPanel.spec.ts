import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AttendanceRecordsPanel from '@/components/attendance/AttendanceRecordsPanel.vue'
import { ja } from '@/i18n/ja'
import type { AttendanceList, LaborMember } from '@/types/api'

const api = vi.hoisted(() => ({
  fetchAttendances: vi.fn<() => Promise<AttendanceList>>(),
  fetchLaborMembers: vi.fn<() => Promise<LaborMember[]>>(),
  createAttendance: vi.fn(),
  updateAttendance: vi.fn(),
  deleteAttendance: vi.fn(),
}))
vi.mock('@/api/attendance', () => api)

const t = ja.attendance

describe('AttendanceRecordsPanel', () => {
  it('休憩はサーバーの上限（10 件）まで追加でき、それ以上は［休憩を追加］を押せない（F-010）', async () => {
    api.fetchAttendances.mockResolvedValue({ month: '2026-10', attendances: [] })
    api.fetchLaborMembers.mockResolvedValue([
      { id: 1, name: '山田', role: 'staff', is_active: true, hourly_wage: null, overtime_exempt: false },
    ])
    const w = mount(AttendanceRecordsPanel, {
      props: { month: '2026-10' },
      global: {
        stubs: {
          BottomSheet: { props: ['open', 'title'], template: '<div v-if="open"><slot /></div>' },
          AttendanceTable: true,
        },
      },
    })
    await flushPromises()

    await w.findAll('button').find((b) => b.text() === t.add)?.trigger('click')
    const addBreak = () => w.findAll('button').find((b) => b.text() === t.addBreak)
    for (let i = 0; i < 10; i++) {
      expect(addBreak()?.attributes('disabled')).toBeUndefined()
      await addBreak()?.trigger('click')
    }

    expect(w.findAll('.att-breaks input[type="datetime-local"]').length).toBe(20)
    expect(addBreak()?.attributes('disabled')).toBeDefined()
  })
})
