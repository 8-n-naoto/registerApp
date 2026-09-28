import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { basePath } from '@/lib/basePath'
import HomePage from '@/pages/HomePage.vue'
import type { Role } from '@/types/api'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean // 未ログインで開ける
    roles?: Role[] // 開ける役割（省略時はログイン済みなら誰でも）
  }
}

// パスと名前は 08 §4。各画面は WP ごとに追加する。ガード（ログイン・役割）は WP 2 で入れる
const routes: RouteRecordRaw[] = [
  // S00 は初回のバンドルに含める（08 §4）
  { path: '/', name: 'home', component: HomePage, meta: { roles: ['owner', 'staff'] } },
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
  scrollBehavior: () => ({ top: 0 }),
})
