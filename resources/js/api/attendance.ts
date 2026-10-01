import { apiBaseUrl, http } from '@/api/client'
import type { Attendance, AttendanceList, AttendanceSummary, LaborMember, LaborSettings, LaborSettingsValues, Me, Operator } from '@/types/api'

// 13 §5 勤怠（打刻・担当者の切替・集計・労働条件）

/** 時刻は 'YYYY-MM-DDTHH:MM'（日本時間） */
export interface AttendanceInput {
  clock_in_at: string
  clock_out_at: string | null
  breaks: { started_at: string; ended_at: string | null }[]
}

export interface AttendanceCreateInput extends AttendanceInput {
  user_id: number
}

export interface LaborMemberInput {
  hourly_wage: number | null
  overtime_exempt: boolean
}

/** #66 端末の店舗で勤務中の人 */
export async function fetchOperators(): Promise<Operator[]> {
  return (await http.get<{ operators: Operator[] }>('/operators')).data.operators
}

/** #67 担当者の切替（owner へはパスワードが要る） */
export async function switchOperator(userId: number, password?: string): Promise<Me> {
  return (await http.post<Me>('/operators/switch', password === undefined ? { user_id: userId } : { user_id: userId, password })).data
}

/** #68 ログインしたままの端末で出勤する */
export async function clockIn(password: string): Promise<Me> {
  return (await http.post<Me>('/attendance/clock-in', { password })).data
}

/** #69 */
export async function startBreak(): Promise<Me> {
  return (await http.post<Me>('/attendance/break-start')).data
}

/** #70 */
export async function endBreak(): Promise<Me> {
  return (await http.post<Me>('/attendance/break-end')).data
}

/** #71 owner は全員（user_id で絞れる）、staff は本人のみ */
export async function fetchAttendances(month: string, userId?: number | null): Promise<AttendanceList> {
  const params: Record<string, string | number> = { month }
  if (userId) params.user_id = userId
  return (await http.get<AttendanceList>('/attendances', { params })).data
}

/** #72 */
export async function createAttendance(input: AttendanceCreateInput): Promise<Attendance> {
  return (await http.post<Attendance>('/attendances', input)).data
}

/** #73 */
export async function updateAttendance(id: number, input: AttendanceInput): Promise<Attendance> {
  return (await http.put<Attendance>(`/attendances/${id}`, input)).data
}

/** #74 */
export async function deleteAttendance(id: number): Promise<void> {
  await http.delete(`/attendances/${id}`)
}

/** #75 */
export async function fetchAttendanceSummary(month: string): Promise<AttendanceSummary> {
  return (await http.get<AttendanceSummary>('/attendances/summary', { params: { month } })).data
}

/** #76 CSV（リンクで開く） */
export function attendanceExportUrl(month: string): string {
  return `${apiBaseUrl}/attendances/export?${new URLSearchParams({ month }).toString()}`
}

/** #77 */
export async function fetchLaborSettings(): Promise<LaborSettings> {
  return (await http.get<LaborSettings>('/settings/labor')).data
}

/** #78 */
export async function updateLaborSettings(input: LaborSettingsValues): Promise<LaborSettings> {
  return (await http.put<LaborSettings>('/settings/labor', input)).data
}

/** #79 */
export async function fetchLaborMembers(): Promise<LaborMember[]> {
  return (await http.get<{ members: LaborMember[] }>('/labor-members')).data.members
}

/** #80 */
export async function updateLaborMember(id: number, input: LaborMemberInput): Promise<LaborMember> {
  return (await http.put<LaborMember>(`/labor-members/${id}`, input)).data
}
