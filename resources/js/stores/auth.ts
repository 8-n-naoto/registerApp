import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
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

  /** サーバーでセッションが切れた・停止されたときに呼ぶ */
  function clear(): void {
    me.value = null
  }

  return { me, loaded, role, isAdmin, isOwner, isStaff, homeRoute, fetchMe, ensureLoaded, login, logout, clear }
})
