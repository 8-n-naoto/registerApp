import { http } from '@/api/client'
import type { User } from '@/types/api'

// 06 §9 スタッフ管理（owner のみ）

export interface StaffCreateInput {
  login_id: string
  name: string
  password: string
}

export interface StaffUpdateInput {
  name: string
  is_active: boolean
}

/** #38 */
export async function fetchStaff(): Promise<User[]> {
  return (await http.get<User[]>('/staff')).data
}

/** #39 */
export async function createStaff(input: StaffCreateInput): Promise<User> {
  return (await http.post<User>('/staff', input)).data
}

/** #40 */
export async function updateStaff(id: number, input: StaffUpdateInput): Promise<User> {
  return (await http.put<User>(`/staff/${id}`, input)).data
}

/** #41 */
export async function resetStaffPassword(id: number, password: string): Promise<void> {
  await http.put(`/staff/${id}/password`, { password })
}
