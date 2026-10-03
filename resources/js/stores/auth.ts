import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import * as attendanceApi from '@/api/attendance'
import * as authApi from '@/api/auth'
import { errorStatus, isNetworkError } from '@/lib/apiError'
import { useCatalogStore } from '@/stores/catalog'
import type { Me } from '@/types/api'

/** 14 §7.3 通信できないまま起動したときに使う、最後に受け取った GET /me（パスワード・トークンは含まない） */
export const ME_CACHE_KEY = 'regi:me'

function loadCachedMe(): Me | null {
  try {
    const raw = localStorage.getItem(ME_CACHE_KEY)
    if (raw === null) return null
    const v = JSON.parse(raw) as unknown
    if (typeof v !== 'object' || v === null) return null
    const r = v as Record<string, unknown>
    return typeof r.user === 'object' && r.user !== null ? (v as Me) : null
  } catch {
    return null
  }
}

function saveCachedMe(value: Me | null): void {
  try {
    if (value === null) localStorage.removeItem(ME_CACHE_KEY)
    else localStorage.setItem(ME_CACHE_KEY, JSON.stringify(value))
  } catch {
    // 使えない端末では、通信できないまま起動したときにログイン画面になるだけ
  }
}

function offlineNow(): boolean {
  return typeof navigator !== 'undefined' && navigator.onLine === false
}

/** ログイン中の利用者（08 §7.1） */
export const useAuthStore = defineStore('auth', () => {
  const me = ref<Me | null>(null)
  /** 起動時の GET /me が終わったか（それまでは 401 でログイン画面へ飛ばさない） */
  const loaded = ref(false)
  /** 14 §7.3 通信できずに端末に残した利用者で動いている（サーバーでログインが続いているかは未確認） */
  const offlineSession = ref(false)
  let loading: Promise<void> | null = null

  const role = computed(() => me.value?.user.role ?? null)
  const isAdmin = computed(() => role.value === 'admin')
  const isOwner = computed(() => role.value === 'owner')
  const isStaff = computed(() => role.value === 'staff')

  /** 勤務中か（13 §2。休憩中を含む）。admin は打刻しない */
  const working = computed(() => me.value?.attendance != null)
  const onBreak = computed(() => me.value?.attendance?.on_break === true)
  /** owner / staff で勤務中でない（S22 で出勤を求める） */
  const needsClockIn = computed(() => (isOwner.value || isStaff.value) && !working.value)

  /** 役割のホーム：admin は A01、owner / staff は S00 */
  const homeRoute = computed<RouteLocationRaw>(() => (isAdmin.value ? { name: 'admin-stores' } : { name: 'home' }))

  // 受け取った利用者を端末に残す（admin はレジを使わないので残さない）
  // （null にしたときは clear() が消す。端末に残した値で動いている間は書き直さない）
  watch(me, (value) => {
    if (value === null || offlineSession.value) return
    saveCachedMe(value.user.role === 'admin' ? null : value)
  })

  /** 通信できないとき：端末に残した利用者があればそれで動く */
  function useCached(): boolean {
    const cached = loadCachedMe()
    if (cached === null) return false
    offlineSession.value = true
    me.value = cached
    return true
  }

  async function fetchMe(): Promise<void> {
    try {
      if (offlineNow() && useCached()) return
      me.value = await authApi.fetchMe()
      offlineSession.value = false
    } catch (err) {
      if (isNetworkError(err) && useCached()) return
      me.value = null
      if (errorStatus(err) !== 401) throw err
      saveCachedMe(null) // ログインが切れている：通信できないまま起動しても前の人で動かさない
    } finally {
      loaded.value = true
    }
  }

  /** 勤怠の状態を読み直す（13 §7）。通信できないときは手元の状態のまま（例外を投げる）、401 だけログアウト扱い */
  async function refreshMe(): Promise<void> {
    try {
      const fresh = await authApi.fetchMe()
      offlineSession.value = false
      me.value = fresh
    } catch (err) {
      if (errorStatus(err) !== 401) throw err
      offlineSession.value = false
      me.value = null
      saveCachedMe(null)
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
    useCatalogStore().clear() // 前にログインしていた店舗の商品を、別の店舗の画面に出さない
    const fresh = await authApi.login(input)
    offlineSession.value = false
    me.value = fresh
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

  /** サーバーでセッションが切れた・停止されたときに呼ぶ（端末に残した利用者も消す。送信待ちの会計は消さない） */
  function clear(): void {
    offlineSession.value = false
    me.value = null
    saveCachedMe(null)
    useCatalogStore().clear()
  }

  return {
    me, loaded, offlineSession, role, isAdmin, isOwner, isStaff, working, onBreak, needsClockIn, homeRoute,
    fetchMe, refreshMe, ensureLoaded, login, logout, clockIn, startBreak, endBreak, switchOperator, clear,
  }
})
