import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { basePath } from '@/lib/basePath'
import HomePage from '@/pages/HomePage.vue'
import { useAuthStore } from '@/stores/auth'
import type { Role } from '@/types/api'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean // ログインの有無に関係なく開ける
    guest?: boolean // 未ログインのときだけ開ける（ログイン済みなら役割のホームへ）
    roles?: Role[] // 開ける役割（省略時はログイン済みなら誰でも）
  }
}

// パスと名前は 08 §4。各画面は WP ごとに追加する
const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { guest: true } },
  // S00 は初回のバンドルに含める（08 §4）
  { path: '/', name: 'home', component: HomePage, meta: { roles: ['owner', 'staff'] } },
  { path: '/account', name: 'account', component: () => import('@/pages/AccountPage.vue') },
  { path: '/products', name: 'products', component: () => import('@/pages/ProductsPage.vue'), meta: { roles: ['owner'] } },
  { path: '/settings/store', name: 'settings-store', component: () => import('@/pages/StoreSettingsPage.vue'), meta: { roles: ['owner'] } },
  { path: '/admin/stores', name: 'admin-stores', component: () => import('@/pages/AdminStoresPage.vue'), meta: { roles: ['admin'] } },
]

if (import.meta.env.DEV) {
  routes.push({
    path: '/dev/components',
    name: 'dev-components',
    component: () => import('@/pages/DevComponentsPage.vue'),
    meta: { public: true },
  })
}

routes.push({
  path: '/:pathMatch(.*)*',
  name: 'not-found',
  component: () => import('@/pages/NotFoundPage.vue'),
  meta: { public: true },
})

export const router = createRouter({
  history: createWebHistory(`${basePath}/`), // 本番 '/regi/'、ローカル '/'
  routes,
  scrollBehavior: (to) => (to.hash ? { el: to.hash, top: 80 } : { top: 0 }),
})

// 08 §4：初回に GET /me を 1 回だけ呼ぶ。未ログインは /login?redirect=、役割が合わなければ役割のホームへ
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.ensureLoaded()

  if (to.meta.public) return true
  if (to.meta.guest) return auth.me ? auth.homeRoute : true
  if (!auth.me) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }
  if (to.meta.roles && !to.meta.roles.includes(auth.me.user.role)) return auth.homeRoute
  return true
})
