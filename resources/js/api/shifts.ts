import { http } from '@/api/client'
import type { Shift, ShiftBoard, ShiftMonth, ShiftPattern, ShiftRequest, ShiftRequestKind, ShiftSegment } from '@/types/api'

// 13 §5 勤務表（予定・締切・公開・希望・区分）

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
  /** 区分を選んだときはサーバーが時刻と休憩を区分から決める */
  pattern_id: number | null
}

export interface ShiftRequestInput {
  date: string
  kind: ShiftRequestKind
  /** 出られる日は区分かメモのどちらかが要る */
  pattern_id: number | null
  note: string | null
}

export interface MyShiftRequests { month: ShiftMonth; requests: ShiftRequest[]; patterns: ShiftPattern[] }

export interface ShiftPatternInput { name: string; segments: ShiftSegment[]; is_active: boolean }

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

/** #91 使わない区分も含む（owner） */
export async function fetchShiftPatterns(): Promise<ShiftPattern[]> {
  return (await http.get<{ patterns: ShiftPattern[] }>('/shift-patterns')).data.patterns
}

/** #92 */
export async function createShiftPattern(input: ShiftPatternInput): Promise<ShiftPattern> {
  return (await http.post<ShiftPattern>('/shift-patterns', input)).data
}

/** #93 */
export async function updateShiftPattern(id: number, input: ShiftPatternInput): Promise<ShiftPattern> {
  return (await http.put<ShiftPattern>(`/shift-patterns/${id}`, input)).data
}
