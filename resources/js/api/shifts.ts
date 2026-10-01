import { http } from '@/api/client'
import type { Shift, ShiftBoard, ShiftMonth, ShiftRequest, ShiftRequestKind } from '@/types/api'

// 13 §5 勤務表（予定・締切・公開・希望）

export interface ShiftMonthInput {
  month: string
  request_deadline: string | null
  memo: string | null
  published: boolean
}

export interface ShiftInput {
  user_id: number
  date: string
  start_time: string
  end_time: string
  break_minutes: number
  note: string | null
}

export interface ShiftRequestInput {
  date: string
  kind: ShiftRequestKind
  start_time: string | null
  end_time: string | null
  note: string | null
}

export interface MyShiftRequests { month: ShiftMonth; requests: ShiftRequest[] }

/** #81 owner は予定と全員の希望、staff は公開済みの予定 */
export async function fetchShiftBoard(month: string): Promise<ShiftBoard> {
  return (await http.get<ShiftBoard>('/shifts', { params: { month } })).data
}

/** #82 */
export async function updateShiftMonth(input: ShiftMonthInput): Promise<ShiftMonth> {
  return (await http.put<ShiftMonth>('/shift-months', input)).data
}

/** #83 */
export async function createShift(input: ShiftInput): Promise<Shift> {
  return (await http.post<Shift>('/shifts', input)).data
}

/** #84 */
export async function updateShift(id: number, input: ShiftInput): Promise<Shift> {
  return (await http.put<Shift>(`/shifts/${id}`, input)).data
}

/** #85 */
export async function deleteShift(id: number): Promise<void> {
  await http.delete(`/shifts/${id}`)
}

/** #86 */
export async function fetchMyShiftRequests(month: string): Promise<MyShiftRequests> {
  return (await http.get<MyShiftRequests>('/shift-requests/mine', { params: { month } })).data
}

/** #87 その月の分を置き換える */
export async function submitMyShiftRequests(month: string, requests: ShiftRequestInput[]): Promise<MyShiftRequests> {
  return (await http.put<MyShiftRequests>('/shift-requests/mine', { month, requests })).data
}
