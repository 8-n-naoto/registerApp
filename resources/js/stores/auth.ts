import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import * as attendanceApi from '@/api/attendance'
import * as authApi from '@/api/auth'
import { errorStatus } from '@/lib/apiError'
import type { Me } from '@/types/api'

/** ログイン中の利用者（08 §7.1） */
export const useAuthStore = defineStore('auth', () => {
  const me = ref<Me | null>(null)
  /** 起動時の GET /me が終わったか（それまでは 401 でログイン画面へ飛ばさない） */
  const loaded = ref(false)
  let loading: Promise<void> | null = null

  const role = computed(() => me.value?.user.role ?? null)
  const isAdmin = computed(() => role.value === 'admin')
  const isOwner = computed(() => role.value === 'owner')
  const isStaff = computed(() => role.value === 'staff')

  /** 担当者が勤務中か（13 §2。休憩中を含む）。admin は打刻しない */
  const working = computed(() => me.value?.attendance != null)
  const onBreak = computed(() => me.value?.attendance?.on_break === true)
  /** owner / staff で勤務中でない（S22 で出勤を求める） */
  const needsClockIn = computed(() => (isOwner.value || isStaff.value) && !working.value)

  /** 役割のホーム：admin は A01、owner / staff は S00 */
  const homeRoute = computed<RouteLocationRaw>(() => (isAdmin.value ? { name: 'admin-stores' } : { name: 'home' }))

  async function fetchMe(): Promise<void> {
    try {
      me.value = await authApi.fetchMe()
    } catch (err) {
      me.value = null
      if (errorStatus(err) !== 401) throw err
    } finally {
      loaded.value = true
    }
  }

  /** 勤怠の状態を読み直す（13 §7）。通信できないときは手元の状態のまま（例外を投げる）、401 だけログアウト扱い */
  async function refreshMe(): Promise<void> {
    try {
      me.value = await authApi.fetchMe()
    } catch (err) {
      if (errorStatus(err) !== 401) throw err
      me.value = null
    }
  }

  /** 初回だけ GET /me を呼ぶ。通信できなかった場合は次の画面遷移でもう一度試す */
  function ensureLoaded(): Promise<void> {
    loading ??= fetchMe().catch(() => {
      loading = null
    })
    return loading
  }

  async function login(input: authApi.LoginInput): Promise<void> {
    me.value = await authApi.login(input)
    loaded.value = true
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      clear()
    }
  }

  /** #68 ログインしたままの端末で出勤する */
  async function clockIn(password: string): Promise<void> {
    me.value = await attendanceApi.clockIn(password)
  }

  /** #69・#70 */
  async function startBreak(): Promise<void> {
    me.value = await attendanceApi.startBreak()
  }
  async function endBreak(): Promise<void> {
    me.value = await attendanceApi.endBreak()
  }

  /** #67 担当者の切替。権限・会計・操作ログは切り替えた人になる */
  async function switchOperator(userId: number, password?: string): Promise<void> {
    me.value = await attendanceApi.switchOperator(userId, password)
    loaded.value = true
  }

  /** サーバーでセッションが切れた・停止されたときに呼ぶ */
  function clear(): void {
    me.value = null
  }

  return {
    me, loaded, role, isAdmin, isOwner, isStaff, working, onBreak, needsClockIn, homeRoute,
    fetchMe, refreshMe, ensureLoaded, login, logout, clockIn, startBreak, endBreak, switchOperator, clear,
  }
})
