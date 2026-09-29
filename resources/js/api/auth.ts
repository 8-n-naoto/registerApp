import { http } from '@/api/client'
import type { Me } from '@/types/api'

// 06 §3 認証

export interface LoginInput {
  login_id: string
  password: string
  remember: boolean
}

export interface PasswordInput {
  current_password: string
  password: string
  password_confirmation: string
}

export async function login(input: LoginInput): Promise<Me> {
  return (await http.post<Me>('/login', input)).data
}

export async function logout(): Promise<void> {
  await http.post('/logout')
}

export async function fetchMe(): Promise<Me> {
  return (await http.get<Me>('/me')).data
}

export async function updatePassword(input: PasswordInput): Promise<void> {
  await http.put('/me/password', input)
}
